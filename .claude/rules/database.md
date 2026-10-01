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

Every table gets `created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP` and `updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`. Map them as `DateTimeImmutable` (`datetimetz_immutable`, which is our microsecond-tolerant type) with `options: ['default' => 'CURRENT_TIMESTAMP']`.

Write `CURRENT_TIMESTAMP`, not the `now` function: PostgreSQL stores the two as different text, so `now` makes `schema:validate` report a diff.

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

## Keeping `schema:validate` in sync

The DDL is hand-written, so the mapping has to describe it exactly or `doctrine:schema:validate` reports a diff:

- Map every FK column as a unidirectional `#[ORM\ManyToOne]` + `#[ORM\JoinColumn(name: '<col>', nullable: …)]`, with `nullable` matching the DDL. A plain scalar ID column makes Doctrine want to drop the FK.
- Never add the inverse `OneToMany`. Across modules it would point a module at one it may not depend on; inside a module, keep the same one-way shape so every FK reads alike and reads go through repositories.
- Name every index with `#[ORM\Index(name: 'idx_…', columns: […])]`, including one single-column index per FK column. Doctrine expects that index and does not accept a composite index or unique key in its place.
- Name every unique key with `#[ORM\UniqueConstraint(name: 'uk_…', columns: […])]`. A partial one also needs `options: ['where' => …]` written in PostgreSQL's normalised form, e.g. `"((status)::text = 'new'::text)"`.
- `CHECK` constraints and `NULLS NOT DISTINCT` are invisible to Doctrine and need no mapping.
- Map generated columns with `insertable: false, updatable: false, generated: 'ALWAYS'` and the same nullability as the DDL.
- Mirror every DB `DEFAULT` in `options: ['default' => …]`.
- Status values are string class constants, not PHP enums: enums are implicitly final, and entities must not be final.
