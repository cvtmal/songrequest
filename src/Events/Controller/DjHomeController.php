<?php

declare(strict_types=1);

namespace App\Events\Controller;

use App\Accounts\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * DJ landing page; #5 turns it into the event list.
 */
final class DjHomeController extends AbstractController
{
    #[Route('/dj', name: 'dj_home', methods: ['GET'])]
    public function home(#[CurrentUser] User $user): Response
    {
        return $this->render('events/dj/home.html.twig', [
            'dj' => $user->getAccount()->getStageName(),
        ]);
    }
}
