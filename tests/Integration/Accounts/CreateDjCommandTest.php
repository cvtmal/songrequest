<?php

declare(strict_types=1);

namespace App\Tests\Integration\Accounts;

use App\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreateDjCommandTest extends KernelTestCase
{
    public function test_creates_the_dj(): void
    {
        $tester = $this->tester(['correct horse battery', 'correct horse battery']);

        $exitCode = $tester->execute(['email' => 'Dj@Example.com', 'stage-name' => 'DJ Mira']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('dj@example.com', $tester->getDisplay());
        self::assertSame(1, $this->countUsers());
    }

    public function test_mismatched_passwords_create_nothing(): void
    {
        $tester = $this->tester(['correct horse battery', 'correct horse battery staple']);

        $exitCode = $tester->execute(['email' => 'dj@example.com', 'stage-name' => 'DJ Mira']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $this->countUsers());
    }

    public function test_short_password_is_rejected(): void
    {
        $tester = $this->tester(['short', 'short']);

        $exitCode = $tester->execute(['email' => 'dj@example.com', 'stage-name' => 'DJ Mira']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $this->countUsers());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $tester = $this->tester(['correct horse battery', 'correct horse battery']);

        $exitCode = $tester->execute(['email' => 'not-an-email', 'stage-name' => 'DJ Mira']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $this->countUsers());
        // Arguments are validated before the password prompts.
        self::assertStringNotContainsString('Password', $tester->getDisplay());
    }

    public function test_existing_email_is_reported(): void
    {
        UserFactory::createOne(['email' => 'dj@example.com']);
        $tester = $this->tester(['correct horse battery', 'correct horse battery']);

        $exitCode = $tester->execute(['email' => 'dj@example.com', 'stage-name' => 'DJ Mira']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    /**
     * @param list<string> $inputs
     */
    private function tester(array $inputs): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:create-dj'));
        $tester->setInputs($inputs);

        return $tester;
    }

    private function countUsers(): int
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return (int) $connection->fetchOne('SELECT count(*) FROM users');
    }
}
