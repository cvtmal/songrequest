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
    private const VERSION = 'DoctrineMigrations\Version20261001120000';

    public function test_mapping_is_in_sync_with_migrated_schema(): void
    {
        $this->assertSchemaIsValid();
    }

    public function test_down_then_up_restores_the_schema(): void
    {
        $this->executeMigration('--down');
        self::assertFalse($this->connection()->createSchemaManager()->tablesExist(['accounts']));

        $this->executeMigration('--up');
        self::assertTrue($this->connection()->createSchemaManager()->tablesExist([
            'accounts', 'users', 'events', 'guests', 'requests', 'request_votes', 'account_guest_blocks',
        ]));
        $this->assertSchemaIsValid();
    }

    private function assertSchemaIsValid(): void
    {
        $tester = $this->application();
        $tester->run(['command' => 'doctrine:schema:validate']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function executeMigration(string $direction): void
    {
        $tester = $this->application();
        $tester->run(
            ['command' => 'doctrine:migrations:execute', 'versions' => [self::VERSION], $direction => true],
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

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
