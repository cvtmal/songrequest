<?php

declare(strict_types=1);

namespace App\Tests\Integration\Accounts;

use App\Shared\Uid\EntityId;
use App\Tests\Integration\ConstraintAssertions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AccountsConstraintsTest extends KernelTestCase
{
    use ConstraintAssertions;

    public function test_user_email_is_unique(): void
    {
        $accountId = $this->insertAccount();
        $this->insertUser($accountId, 'dj@example.com');

        self::assertUniqueViolation('uk_users_email', fn () => $this->insertUser($accountId, 'dj@example.com'));
    }

    public function test_user_email_must_be_lowercase(): void
    {
        $accountId = $this->insertAccount();

        self::assertCheckViolation('chk_users_email_lowercase', fn () => $this->insertUser($accountId, 'DJ@example.com'));
    }

    private function insertAccount(): string
    {
        $id = EntityId::generate();
        $this->connection()->insert('accounts', ['id' => $id, 'stage_name' => 'DJ Test']);

        return $id;
    }

    private function insertUser(string $accountId, string $email): void
    {
        $this->connection()->insert('users', [
            'id' => EntityId::generate(),
            'account_id' => $accountId,
            'email' => $email,
            'password_hash' => '!',
        ]);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
