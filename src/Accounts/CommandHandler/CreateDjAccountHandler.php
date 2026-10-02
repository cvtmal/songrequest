<?php

declare(strict_types=1);

namespace App\Accounts\CommandHandler;

use App\Accounts\Command\CreateDjAccount;
use App\Accounts\Entity\Account;
use App\Accounts\Entity\User;
use App\Accounts\Exception\EmailAlreadyRegistered;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class CreateDjAccountHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function __invoke(CreateDjAccount $command): void
    {
        $email = mb_strtolower(trim($command->email));
        $hash = $this->hasherFactory->getPasswordHasher(User::class)->hash($command->plainPassword);

        $account = new Account(trim($command->stageName));
        $user = new User($account, $email, $hash);
        $this->entityManager->persist($account);
        $this->entityManager->persist($user);

        // Flushing here makes uk_users_email fire inside the handler, so a duplicate and a concurrent
        // signup take the same path, and the bus transaction rolls back the account.
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw EmailAlreadyRegistered::forEmail($email);
        }
    }
}
