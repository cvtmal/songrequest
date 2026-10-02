<?php

declare(strict_types=1);

namespace App\Events\Controller;

use App\Accounts\Entity\User;
use App\Events\Command\CloseEvent;
use App\Events\Command\CreateEvent;
use App\Events\Command\ResumeRequests;
use App\Events\Command\StopRequests;
use App\Events\Exception\EventNotFound;
use App\Events\Form\EventData;
use App\Events\Form\EventType;
use App\Shared\Exception\DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Creating, closing, stopping and resuming events (EV-1, EV-2, DQ-5).
 */
final class EventController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/dj/events/new', name: 'event_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $data = new EventData();
        $form = $this->createForm(EventType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('events/dj/new.html.twig', ['form' => $form]);
        }

        $envelope = $this->bus->dispatch(new CreateEvent($user->getAccount()->getId(), (string) $data->name));
        $id = $envelope->last(HandledStamp::class)?->getResult();
        \assert(\is_string($id));

        // Flow §8.3: create, then show the QR code.
        return $this->redirectToRoute('event_qr', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/dj/events/{id}/close', name: 'event_close', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function close(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('event_close', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->dispatchOrNotFound(new CloseEvent($id, $user->getAccount()->getId()));

        return $this->redirectToRoute('dj_home', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/dj/events/{id}/stop', name: 'event_stop', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function stop(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('event_stop', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->dispatchOrNotFound(new StopRequests($id, $user->getAccount()->getId()));

        return $this->redirectToRoute('event_queue', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/dj/events/{id}/resume', name: 'event_resume', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function resume(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('event_resume', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->dispatchOrNotFound(new ResumeRequests($id, $user->getAccount()->getId()));

        return $this->redirectToRoute('event_queue', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function dispatchOrNotFound(object $command): void
    {
        try {
            $this->bus->dispatch($command);
        } catch (HandlerFailedException $e) {
            // Keyed by handler name, not by position.
            $domainException = array_values($e->getWrappedExceptions(DomainException::class, true))[0] ?? null;
            if ($domainException instanceof EventNotFound) {
                throw $this->createNotFoundException(previous: $domainException);
            }

            throw $e;
        }
    }
}
