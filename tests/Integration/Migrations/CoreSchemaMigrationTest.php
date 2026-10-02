<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Resets the database because the round trip drops and recreates the schema.
 */
#[ResetDatabase]
final class CoreSchemaMigrationTest extends KernelTestCase
{
    private const CORE_VERSION = 'DoctrineMigrations\Version20261001120000';
    private const IP_ADDRESS_VERSION = 'DoctrineMigrations\Version20261001130000';
    private const HANDLED_AT_VERSION = 'DoctrineMigrations\Version20261002120000';

    public function test_mapping_is_in_sync_with_migrated_schema(): void
    {
        $this->assertSchemaIsValid();
    }

    public function test_down_then_up_restores_the_schema(): void
    {
        $this->executeMigration(self::HANDLED_AT_VERSION, '--down');
        self::assertFalse($this->requestsHasHandledAt());

        $this->executeMigration(self::IP_ADDRESS_VERSION, '--down');
        self::assertFalse($this->requestVotesHasIpAddress());

        $this->executeMigration(self::CORE_VERSION, '--down');
        self::assertFalse($this->connection()->createSchemaManager()->tablesExist(['accounts']));

        $this->executeMigration(self::CORE_VERSION, '--up');
        self::assertTrue($this->connection()->createSchemaManager()->tablesExist([
            'accounts', 'users', 'events', 'guests', 'requests', 'request_votes', 'account_guest_blocks',
        ]));

        $this->executeMigration(self::IP_ADDRESS_VERSION, '--up');
        self::assertTrue($this->requestVotesHasIpAddress());

        $this->executeMigration(self::HANDLED_AT_VERSION, '--up');
        self::assertTrue($this->requestsHasHandledAt());

        $this->assertSchemaIsValid();
    }

    private function assertSchemaIsValid(): void
    {
        $tester = $this->application();
        $tester->run(['command' => 'doctrine:schema:validate']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function executeMigration(string $version, string $direction): void
    {
        $tester = $this->application();
        $tester->run(
            ['command' => 'doctrine:migrations:execute', 'versions' => [$version], $direction => true],
            ['interactive' => false],
        );

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * Runs commands through the application, not CommandTester, so console.command fires:
     * the migrations bundle hides doctrine_migration_versions from schema:validate on that event.
     */
    private function application(): ApplicationTester
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function requestVotesHasIpAddress(): bool
    {
        return $this->connection()->createSchemaManager()->introspectTable('request_votes')->hasColumn('ip_address');
    }

    private function requestsHasHandledAt(): bool
    {
        return $this->connection()->createSchemaManager()->introspectTable('requests')->hasColumn('handled_at');
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
