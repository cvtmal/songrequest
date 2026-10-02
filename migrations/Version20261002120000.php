<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The done list sorts by when a request left the queue, and the event summary (DQ-9, #29)
 * reports it. Not updated_at: marking tips (#28) touches done rows. The check mirrors
 * chk_events_closed_at.
 */
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add requests.handled_at for the done list (DQ-3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE requests ADD handled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Backfill existing done rows so the check below holds on any database.
        $this->addSql("UPDATE requests SET handled_at = updated_at WHERE status <> 'new'");
        $this->addSql("ALTER TABLE requests ADD CONSTRAINT chk_requests_handled_at CHECK ((status = 'new') = (handled_at IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE requests DROP CONSTRAINT chk_requests_handled_at');
        $this->addSql('ALTER TABLE requests DROP handled_at');
    }
}
