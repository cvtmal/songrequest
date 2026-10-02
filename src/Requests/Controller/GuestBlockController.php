<?php

declare(strict_types=1);

namespace App\Requests\Controller;

use App\Accounts\Entity\User;
use App\Requests\Command\UnblockGuest;
use App\Requests\Repository\AccountGuestBlockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The account's block list, where a block is undone (DQ-4).
 */
final class GuestBlockController extends AbstractController
{
    public function __construct(
        private readonly AccountGuestBlockRepository $blocks,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/dj/blocks', name: 'guest_blocks', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): Response
    {
        return $this->render('requests/dj/blocks.html.twig', [
            'blocks' => $this->blocks->findForAccount($user->getAccount()->getId()),
        ]);
    }

    #[Route('/dj/blocks/{guestId}/unblock', name: 'guest_unblock', requirements: ['guestId' => Requirement::UUID], methods: ['POST'])]
    public function unblock(Request $request, string $guestId, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('guest_unblock', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // The handler throws no domain exception: another account's guest is a silent no-op.
        $this->bus->dispatch(new UnblockGuest($guestId, $user->getAccount()->getId()));

        return $this->redirectToRoute('guest_blocks', status: Response::HTTP_SEE_OTHER);
    }
}
