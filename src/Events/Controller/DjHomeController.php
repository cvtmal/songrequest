<?php

declare(strict_types=1);

namespace App\Events\Controller;

use App\Accounts\Entity\User;
use App\Events\Entity\Event;
use App\Events\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The DJ's event list.
 */
final class DjHomeController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
    ) {
    }

    #[Route('/dj', name: 'dj_home', methods: ['GET'])]
    public function home(#[CurrentUser] User $user): Response
    {
        $events = $this->events->findForAccount($user->getAccount());

        return $this->render('events/dj/home.html.twig', [
            'dj' => $user->getAccount()->getStageName(),
            'active' => array_values(array_filter($events, static fn (Event $event): bool => Event::STATUS_CLOSED !== $event->getStatus())),
            'closed' => array_values(array_filter($events, static fn (Event $event): bool => Event::STATUS_CLOSED === $event->getStatus())),
        ]);
    }
}
