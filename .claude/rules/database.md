---
paths:
  - "migrations/**/*.php"
  - "src/**/Entity/**/*.php"
  - "src/**/Repository/**/*.php"
  - "config/packages/doctrine*.yaml"
---

# Database and migrations

## Migrations

- Write every migration by hand as SQL with `$this->addSql(...)`. Never run `doctrine:migrations:diff` or `doctrine:schema:update --force`.
- Every migration implements `down()` that fully reverses `up()`.
- Name files `migrations/VersionYYYYMMDDHHMMSS.php`; give each a `getDescription()`.
- After writing one: `make db-migrate`, `make db-test`, then `make sf c="doctrine:schema:validate"` must report the mapping and the database in sync.

## Column types

| Data | PostgreSQL type |
|---|---|
| IDs | `UUID` |
| Points in time | `TIMESTAMPTZ` (`TIMESTAMP(0) WITH TIME ZONE`) |
| Flags | `BOOLEAN` |
| Structured blobs | `JSONB` |
| Text with a known limit | `VARCHAR(n)` |
| Money | `INTEGER` in the smallest unit (Rappen/cents) plus a `CHAR(3)` currency |

## Naming

- Tables and columns: `snake_case`, tables plural (`song_requests`).
- Constraints and indexes carry a prefix: `pk_`, `fk_<table>_<column>`, `uk_<table>_<columns>`, `chk_<table>_<rule>`, `idx_<table>_<columns>`.
- Uniqueness and integrity rules live in the database as constraints, even when the application also checks them.

## Timestamps

Every table gets `created_at TIMESTAMPTZ NOT NULL DEFAULT now()` and `updated_at TIMESTAMPTZ NOT NULL DEFAULT now()`. Map them as `DateTimeImmutable` (`datetimetz_immutable`, which is our microsecond-tolerant type).

## Entities and IDs

- IDs are generated in PHP, only through `App\Shared\Uid\EntityId::generate()`.
- Mapping:

  ```php
  #[ORM\Id]
  #[ORM\Column(type: Types::GUID)]
  private ?string $id = null;
  ```

  Assign the ID in the constructor (`$this->id = EntityId::generate();`).
- Entities are neither `final` nor `readonly`.
- Each module registers its own Doctrine mapping (`App\<Module>\Entity` → `src/<Module>/Entity`) in `config/packages/doctrine.yaml` when its first entity lands.
