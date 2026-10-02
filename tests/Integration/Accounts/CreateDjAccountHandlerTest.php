<?php

declare(strict_types=1);

namespace App\Tests\Integration\Accounts;

use App\Accounts\Command\CreateDjAccount;
use App\Accounts\Entity\User;
use App\Accounts\Exception\EmailAlreadyRegistered;
use App\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreateDjAccountHandlerTest extends KernelTestCase
{
    public function test_creates_account_and_user_with_lowercased_email(): void
    {
        $this->dispatch(' Dj@Example.com ', 'DJ Mira', 'correct horse');

        self::assertSame(1, $this->countRows('accounts'));
        self::assertSame(1, $this->countRows('users'));
        $account = $this->connection()->fetchAssociative('SELECT id, stage_name FROM accounts');
        $user = $this->connection()->fetchAssociative('SELECT email, account_id FROM users');
        self::assertIsArray($account);
        self::assertIsArray($user);
        self::assertSame('DJ Mira', $account['stage_name']);
        self::assertSame('dj@example.com', $user['email']);
        self::assertSame($account['id'], $user['account_id']);
    }

    public function test_stored_hash_verifies_the_password(): void
    {
        $this->dispatch('dj@example.com', 'DJ Mira', 'correct horse');

        $hash = $this->connection()->fetchOne('SELECT password_hash FROM users');
        self::assertIsString($hash);
        self::assertNotSame('correct horse', $hash);
        self::assertTrue($this->hasherFactory()->getPasswordHasher(User::class)->verify($hash, 'correct horse'));
    }

    public function test_duplicate_email_in_any_case_is_rejected_and_rolled_back(): void
    {
        UserFactory::createOne(['email' => 'dj@example.com']);

        try {
            $this->dispatch('DJ@example.com', 'DJ Copy', 'correct horse');
            self::fail('Expected EmailAlreadyRegistered.');
        } catch (EmailAlreadyRegistered) {
        }

        self::assertSame(1, $this->countRows('accounts'));
        self::assertSame(1, $this->countRows('users'));
    }

    private function dispatch(string $email, string $stageName, string $password): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        try {
            $bus->dispatch(new CreateDjAccount($email, $stageName, $password));
        } catch (HandlerFailedException $e) {
            throw $e->getWrappedExceptions()[array_key_first($e->getWrappedExceptions())];
        }
    }

    private function hasherFactory(): PasswordHasherFactoryInterface
    {
        $factory = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);

        return $factory;
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
