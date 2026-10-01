<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The R1 core schema.
 *
 * Who asked for which song lives only in request_votes (one row per accepted submission,
 * new or merged); requests.votes is a denormalised counter for sorting and merging.
 *
 * uk_requests_event_song is partial on status = 'new' because AS-5 merges a duplicate
 * only with a song still in the open queue: a played song can be requested again.
 * NULLS NOT DISTINCT makes a missing artist match a missing (or blank) artist.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the R1 core schema: accounts, users, events, guests, requests, request_votes, account_guest_blocks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id UUID NOT NULL,
                stage_name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_accounts PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id UUID NOT NULL,
                account_id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_users PRIMARY KEY (id),
                CONSTRAINT uk_users_email UNIQUE (email),
                CONSTRAINT chk_users_email_lowercase CHECK (email = lower(email)),
                CONSTRAINT fk_users_account_id FOREIGN KEY (account_id) REFERENCES accounts (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_users_account_id ON users (account_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE events (
                id UUID NOT NULL,
                account_id UUID NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                opened_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_events PRIMARY KEY (id),
                CONSTRAINT uk_events_slug UNIQUE (slug),
                CONSTRAINT chk_events_status CHECK (status IN ('open', 'stopped', 'closed')),
                CONSTRAINT chk_events_closed_at CHECK ((status = 'closed') = (closed_at IS NOT NULL)),
                CONSTRAINT fk_events_account_id FOREIGN KEY (account_id) REFERENCES accounts (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_events_account_id ON events (account_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE guests (
                id UUID NOT NULL,
                token UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_guests PRIMARY KEY (id),
                CONSTRAINT uk_guests_token UNIQUE (token)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE requests (
                id UUID NOT NULL,
                account_id UUID NOT NULL,
                event_id UUID NOT NULL,
                title VARCHAR(200) NOT NULL,
                artist VARCHAR(200) DEFAULT NULL,
                title_normalized VARCHAR(200) NOT NULL
                    GENERATED ALWAYS AS (lower(btrim(regexp_replace(title, '\s+', ' ', 'g')))) STORED,
                artist_normalized VARCHAR(200)
                    GENERATED ALWAYS AS (NULLIF(lower(btrim(regexp_replace(artist, '\s+', ' ', 'g'))), '')) STORED,
                votes INTEGER DEFAULT 1 NOT NULL,
                status VARCHAR(16) DEFAULT 'new' NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_requests PRIMARY KEY (id),
                CONSTRAINT chk_requests_status CHECK (status IN ('new', 'played', 'skipped')),
                CONSTRAINT chk_requests_votes CHECK (votes >= 1),
                CONSTRAINT chk_requests_title_not_blank CHECK (title_normalized <> ''),
                CONSTRAINT fk_requests_account_id FOREIGN KEY (account_id) REFERENCES accounts (id),
                CONSTRAINT fk_requests_event_id FOREIGN KEY (event_id) REFERENCES events (id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uk_requests_event_song
                ON requests (event_id, title_normalized, artist_normalized) NULLS NOT DISTINCT
                WHERE status = 'new'
            SQL);
        $this->addSql('CREATE INDEX idx_requests_account_id ON requests (account_id)');
        $this->addSql('CREATE INDEX idx_requests_event_id ON requests (event_id)');
        $this->addSql('CREATE INDEX idx_requests_event_id_status ON requests (event_id, status)');

        $this->addSql(<<<'SQL'
            CREATE TABLE request_votes (
                id UUID NOT NULL,
                account_id UUID NOT NULL,
                event_id UUID NOT NULL,
                request_id UUID NOT NULL,
                guest_id UUID NOT NULL,
                nickname VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_request_votes PRIMARY KEY (id),
                CONSTRAINT uk_request_votes_request_guest UNIQUE (request_id, guest_id),
                CONSTRAINT fk_request_votes_account_id FOREIGN KEY (account_id) REFERENCES accounts (id),
                CONSTRAINT fk_request_votes_event_id FOREIGN KEY (event_id) REFERENCES events (id),
                CONSTRAINT fk_request_votes_request_id FOREIGN KEY (request_id) REFERENCES requests (id),
                CONSTRAINT fk_request_votes_guest_id FOREIGN KEY (guest_id) REFERENCES guests (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_request_votes_account_id ON request_votes (account_id)');
        $this->addSql('CREATE INDEX idx_request_votes_event_id ON request_votes (event_id)');
        $this->addSql('CREATE INDEX idx_request_votes_request_id ON request_votes (request_id)');
        $this->addSql('CREATE INDEX idx_request_votes_guest_id ON request_votes (guest_id)');
        // Per-guest cooldown and cap within an event.
        $this->addSql('CREATE INDEX idx_request_votes_event_id_guest_id_created_at ON request_votes (event_id, guest_id, created_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE account_guest_blocks (
                id UUID NOT NULL,
                account_id UUID NOT NULL,
                guest_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_account_guest_blocks PRIMARY KEY (id),
                CONSTRAINT uk_account_guest_blocks_account_guest UNIQUE (account_id, guest_id),
                CONSTRAINT fk_account_guest_blocks_account_id FOREIGN KEY (account_id) REFERENCES accounts (id),
                CONSTRAINT fk_account_guest_blocks_guest_id FOREIGN KEY (guest_id) REFERENCES guests (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_account_guest_blocks_account_id ON account_guest_blocks (account_id)');
        $this->addSql('CREATE INDEX idx_account_guest_blocks_guest_id ON account_guest_blocks (guest_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_guest_blocks');
        $this->addSql('DROP TABLE request_votes');
        $this->addSql('DROP TABLE requests');
        $this->addSql('DROP TABLE guests');
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE accounts');
    }
}
