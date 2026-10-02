<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The per-IP backstop (AS-7) counts accepted submissions per IP per event over the last hour.
 *
 * Plain text, not INET: DBAL has no inet type and only equality lookups are needed; 45 chars
 * hold any IPv6 text form. Nullable for console/test dispatches and the Q9/#15 retention scrub.
 */
final class Version20261001130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add request_votes.ip_address for the per-IP backstop (AS-7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_votes ADD ip_address VARCHAR(45) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_request_votes_event_id_ip_address_created_at ON request_votes (event_id, ip_address, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_request_votes_event_id_ip_address_created_at');
        $this->addSql('ALTER TABLE request_votes DROP ip_address');
    }
}
