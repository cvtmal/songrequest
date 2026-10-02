<?php

declare(strict_types=1);

namespace App\Tests\Unit\Accounts\Entity;

use App\Accounts\Entity\Account;
use App\Accounts\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const string HASH = '$2y$04$abcdefghijklmnopqrstuuJ1tC0QH6aM5HfXz5rEKrYB0G4p8qZ1C';

    public function test_identifier_is_the_email(): void
    {
        self::assertSame('dj@example.com', $this->user()->getUserIdentifier());
    }

    public function test_roles_are_role_user(): void
    {
        self::assertSame(['ROLE_USER'], $this->user()->getRoles());
    }

    public function test_password_is_the_hash(): void
    {
        self::assertSame(self::HASH, $this->user()->getPassword());
    }

    public function test_serialized_user_holds_only_id_email_and_crc32c_of_the_hash(): void
    {
        $user = $this->user();

        $data = $user->__serialize();

        self::assertSame(
            ["\0".User::class."\0id", "\0".User::class."\0email", "\0".User::class."\0passwordHash"],
            array_keys($data),
        );
        self::assertSame($user->getId(), $data["\0".User::class."\0id"]);
        self::assertSame(hash('crc32c', self::HASH), $data["\0".User::class."\0passwordHash"]);
    }

    public function test_unserialized_user_keeps_id_and_email(): void
    {
        $user = $this->user();

        $restored = unserialize(serialize($user));

        self::assertInstanceOf(User::class, $restored);
        self::assertSame($user->getId(), $restored->getId());
        self::assertSame('dj@example.com', $restored->getUserIdentifier());
        self::assertSame(hash('crc32c', self::HASH), $restored->getPassword());
    }

    private function user(): User
    {
        return new User(new Account('DJ Mira'), 'dj@example.com', self::HASH);
    }
}
