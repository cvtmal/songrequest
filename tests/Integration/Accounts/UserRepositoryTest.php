<?php

declare(strict_types=1);

namespace App\Tests\Integration\Accounts;

use App\Accounts\Repository\UserRepository;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class UserRepositoryTest extends KernelTestCase
{
    public function test_load_user_by_identifier_ignores_case_and_whitespace(): void
    {
        $user = UserFactory::createOne(['email' => 'dj@example.com']);

        $found = $this->repository()->loadUserByIdentifier(' Dj@Example.COM ');

        self::assertNotNull($found);
        self::assertSame($user->getId(), $found->getId());
    }

    public function test_load_user_by_identifier_returns_null_for_unknown_email(): void
    {
        UserFactory::createOne(['email' => 'dj@example.com']);

        self::assertNull($this->repository()->loadUserByIdentifier('someone@example.com'));
    }

    private function repository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
