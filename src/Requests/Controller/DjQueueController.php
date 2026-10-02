<?php

declare(strict_types=1);

namespace App\Requests\Controller;

use App\Accounts\Entity\User;
use App\Events\Repository\EventRepository;
use App\Requests\Command\BlockGuest;
use App\Requests\Command\MarkRequestPlayed;
use App\Requests\Command\ReopenRequest;
use App\Requests\Command\SkipRequest;
use App\Requests\Exception\EventNotFound;
use App\Requests\Exception\GuestNotFound;
use App\Requests\Exception\SongRequestNotFound;
use App\Requests\Repository\AccountGuestBlockRepository;
use App\Requests\Repository\SongRequestRepository;
use App\Shared\Exception\DomainException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The DJ queue for one event (DQ-1 to DQ-6).
 */
final class DjQueueController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly SongRequestRepository $songRequests,
        private readonly AccountGuestBlockRepository $blocks,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/dj/events/{id}/queue', name: 'event_queue', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function queue(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        $event = $this->events->findOneForAccount($id, $user->getAccount()) ?? throw $this->createNotFoundException();
        $accountId = $user->getAccount()->getId();
        $tab = 'done' === $request->query->getString('tab') ? 'done' : 'queue';

        $parameters = [
            'event' => $event,
            'tab' => $tab,
            'queue' => $this->songRequests->findQueue($id, $accountId),
            'done' => 'done' === $tab ? $this->songRequests->findDone($id, $accountId) : [],
            'doneCount' => $this->songRequests->countDone($id, $accountId),
            'mutedCount' => $this->blocks->countForAccount($accountId),
            'now' => $this->clock->now(),
        ];

        // Polls ask for the frame only, which keeps them small; any other request gets the full
        // page, from which Turbo extracts the frame.
        if ('queue' === $request->headers->get('Turbo-Frame')) {
            return $this->render('requests/dj/_queue_frame.html.twig', $parameters + ['frame_request' => true]);
        }

        return $this->render('requests/dj/queue.html.twig', $parameters + ['frame_request' => false]);
    }

    #[Route('/dj/events/{id}/requests/{requestId}/played', name: 'request_played', requirements: ['id' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['POST'])]
    public function played(Request $request, string $id, string $requestId, #[CurrentUser] User $user): Response
    {
        $this->handle($request, 'request_played', new MarkRequestPlayed($requestId, $id, $user->getAccount()->getId()));

        return $this->redirectToRoute('event_queue', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/dj/events/{id}/requests/{requestId}/skipped', name: 'request_skipped', requirements: ['id' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['POST'])]
    public function skipped(Request $request, string $id, string $requestId, #[CurrentUser] User $user): Response
    {
        $this->handle($request, 'request_skipped', new SkipRequest($requestId, $id, $user->getAccount()->getId()));

        return $this->redirectToRoute('event_queue', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/dj/events/{id}/requests/{requestId}/reopen', name: 'request_reopen', requirements: ['id' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['POST'])]
    public function reopen(Request $request, string $id, string $requestId, #[CurrentUser] User $user): Response
    {
        $this->handle($request, 'request_reopen', new ReopenRequest($requestId, $id, $user->getAccount()->getId()));

        // Undo is tapped on the Done tab, so the DJ stays there.
        return $this->redirectToRoute('event_queue', ['id' => $id, 'tab' => 'done'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/dj/events/{id}/guests/{guestId}/block', name: 'guest_block', requirements: ['id' => Requirement::UUID, 'guestId' => Requirement::UUID], methods: ['POST'])]
    public function block(Request $request, string $id, string $guestId, #[CurrentUser] User $user): Response
    {
        $this->handle($request, 'guest_block', new BlockGuest($guestId, $user->getAccount()->getId()));

        return $this->redirectToRoute('event_queue', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function handle(Request $request, string $tokenId, object $command): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->bus->dispatch($command);
        } catch (HandlerFailedException $e) {
            // Keyed by handler name, not by position.
            $domainException = array_values($e->getWrappedExceptions(DomainException::class, true))[0] ?? null;
            if ($domainException instanceof EventNotFound
                || $domainException instanceof SongRequestNotFound
                || $domainException instanceof GuestNotFound
            ) {
                throw $this->createNotFoundException(previous: $domainException);
            }

            throw $e;
        }
    }
}
