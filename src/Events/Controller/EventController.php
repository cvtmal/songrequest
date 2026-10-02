<?php

declare(strict_types=1);

namespace App\Events\Controller;

use App\Accounts\Entity\User;
use App\Events\Command\CloseEvent;
use App\Events\Command\CreateEvent;
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
 * Creating and closing events (EV-1, EV-2).
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

        try {
            $this->bus->dispatch(new CloseEvent($id, $user->getAccount()->getId()));
        } catch (HandlerFailedException $e) {
            // Keyed by handler name, not by position.
            $domainException = array_values($e->getWrappedExceptions(DomainException::class, true))[0] ?? null;
            if ($domainException instanceof EventNotFound) {
                throw $this->createNotFoundException(previous: $domainException);
            }

            throw $e;
        }

        return $this->redirectToRoute('dj_home', status: Response::HTTP_SEE_OTHER);
    }
}
