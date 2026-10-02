<?php

declare(strict_types=1);

namespace App\Events\CommandHandler;

use App\Accounts\Entity\Account;
use App\Events\Command\CreateEvent;
use App\Events\Entity\Event;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\ByteString;

final class CreateEventHandler implements CommandHandlerInterface
{
    // Lowercase without 0 o 1 l i, so a slug read off a printout can be typed;
    // 31^10 ≈ 49.5 bits make it hard to guess (EV-1).
    private const SLUG_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';
    private const SLUG_LENGTH = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateEvent $command): string
    {
        // No query: the ID comes from the logged-in user.
        $account = $this->entityManager->getReference(Account::class, $command->accountId);
        \assert($account instanceof Account);

        $event = new Event($account, trim($command->name), $this->generateSlug());
        $this->entityManager->persist($event);

        return $event->getId();
    }

    private function generateSlug(): string
    {
        // uk_events_slug is the guarantee. At about 1e-9 collision odds per insert there is no retry,
        // because a retry would need a fresh EntityManager inside the transaction middleware.
        return ByteString::fromRandom(self::SLUG_LENGTH, self::SLUG_ALPHABET)->toString();
    }
}
