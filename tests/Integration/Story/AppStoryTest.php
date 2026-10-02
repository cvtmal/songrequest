<?php

declare(strict_types=1);

namespace App\Tests\Integration\Story;

use App\Accounts\Entity\User;
use App\Accounts\Repository\AccountRepository;
use App\Accounts\Repository\UserRepository;
use App\Events\Entity\Event;
use App\Events\Repository\EventRepository;
use App\Story\AppStory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AppStoryTest extends KernelTestCase
{
    public function test_builds_one_dj_with_one_open_event(): void
    {
        AppStory::load();

        foreach ([AccountRepository::class, UserRepository::class, EventRepository::class] as $repositoryClass) {
            $repository = self::getContainer()->get($repositoryClass);
            self::assertInstanceOf($repositoryClass, $repository);
            self::assertSame(1, $repository->count([]), $repositoryClass);
        }

        $user = AppStory::get('user');
        $event = AppStory::get('event');
        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(Event::class, $event);
        self::assertSame(Event::STATUS_OPEN, $event->getStatus());
        self::assertSame('demo', $event->getSlug());
        self::assertSame($user->getAccount()->getId(), $event->getAccount()->getId());
    }

    public function test_seeded_dj_password_is_password(): void
    {
        AppStory::load();

        $user = AppStory::get('user');
        $hasherFactory = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $hasherFactory);
        self::assertSame('dj@example.com', $user->getEmail());
        self::assertTrue($hasherFactory->getPasswordHasher(User::class)->verify($user->getPasswordHash(), 'password'));
    }
}
