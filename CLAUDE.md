# Song Request

Subscription SaaS where guests request songs from a DJ at live events. Planning notes live in `docs/` (start with `docs/roadmap.md`).

## Tech Stack

- Symfony 7.4 (LTS) on PHP 8.5, served by php-fpm behind nginx
- PostgreSQL 17, Doctrine ORM 3 / DBAL 4, hand-written SQL migrations
- Symfony Messenger with the Doctrine transport (`async` + `failed`), one `command.bus`
- Twig, Forms, Turbo, AssetMapper (no Node build)
- Docker Compose for everything local: `php`, `worker`, `nginx`, `database`, `mailpit` (+ `stripe` profile)

## Commands

Never run `composer` or `bin/console` on the host. The host has a different PHP version; every target below runs inside the `php` container.

| Command | What it does |
|---|---|
| `make init` | First run: build image, `composer install`, create + migrate DB, start the stack |
| `make up` / `make down` / `make logs` | Start, stop, follow logs |
| `make sh` | Shell in the php container |
| `make sf c="debug:router"` | Any console command |
| `make vendor` | `composer install` |
| `make db-migrate` / `make db-fresh` | Run migrations / drop, recreate and migrate |
| `make test` | Prepare `app_test`, run all suites |
| `make test-short` | Run tests, stop at the first failure |
| `make cs-fix` / `make cs-check` | Fix / check coding standards |
| `make phpstan` | PHPStan level 7 + architecture rules |
| `make lint` | `cs-check` + `phpstan` (the CI gate) |
| `make audit` | `composer audit` |
| `make worker` | Consume `async` in the foreground (the `worker` service does this in the background) |
| `make stripe-listen` | Forward Stripe webhooks (see Stripe below) |

App: http://localhost (`/health` returns `{"status":"ok"}`). Mail UI: http://localhost:8025.

## Testing

| Suite | Folder | Base class | Use for |
|---|---|---|---|
| Unit | `tests/Unit` | `PHPUnit\Framework\TestCase` | Pure PHP, no container |
| Integration | `tests/Integration` | `KernelTestCase` | Services, repositories, real Postgres |
| Application | `tests/Application` | `WebTestCase` | HTTP in, HTML/JSON out |

- Test classes are `final`; methods are `test_snake_case` (enforced by PHP-CS-Fixer).
- `make test` creates and migrates the `app_test` database first.
- PHPUnit fails on deprecations, notices and warnings coming from `src/`.
- Factories use Foundry (`zenstruck/foundry`).

## Architecture

Module-first modular monolith: `src/<Module>/<Layer>/`, namespace `App\<Module>\<Layer>`.

- Modules: `Accounts`, `Events`, `Requests`, `Billing`, `Tips`, plus `Shared` for building blocks.
- Layers inside a module: `Controller`, `Command` (command DTOs), `CommandHandler`, `Entity`, `Repository`, `Exception`, `Form`.
- Allowed module dependencies (everything may use `Shared`; `Shared` uses no module):

  | Module | May depend on |
  |---|---|
  | Accounts | — |
  | Events | Accounts |
  | Requests | Events, Accounts |
  | Billing | Accounts |
  | Tips | Requests, Events, Accounts |

- Writes: controller → command → bus → handler. Reads: controller → repository. There is no query bus.
- phpat enforces layers, modifiers and module boundaries during `make phpstan` (rules in `tests/Architecture/`). Details: `.claude/rules/php-architecture.md`, `.claude/rules/cqrs.md`.

## Validation strategy

Three layers, each catching what the previous one cannot:

1. **Forms** validate input shape (Symfony Form + Validator on a form data class).
2. **Handlers** enforce business rules and throw `DomainException` subclasses.
3. **Database constraints** guarantee uniqueness and integrity under concurrency.

Details: `.claude/rules/validation.md`.

## Building blocks (`src/Shared`)

- `Uid\EntityId::generate()`: the only way to create an entity ID (UUID v7 string).
- `Doctrine\Type\FlexibleDateTimeTzImmutableType`: replaces `datetimetz_immutable` so Postgres values with microseconds hydrate.
- `Exception\DomainException`: abstract base for business errors.
- `Messenger\CommandHandlerInterface`: marker that registers a handler on `command.bus`.
- `Controller\HealthController`: `GET /health`, checks the database.

## Exception conventions

- Every business error is a `final` class in `<Module>\Exception` extending `App\Shared\Exception\DomainException`.
- Create them through named constructors (`EventClosed::forEvent($id)`), not `new` with a message at the call site.
- Infrastructure errors (DB down, HTTP failures) are not domain exceptions; let them bubble.

## Database & migrations

Hand-written SQL migrations only; never `doctrine:migrations:diff`. Every migration has a `down()`. Naming, types and entity mapping rules: `.claude/rules/database.md`.

## Local port conflicts

Host ports default to 80 (nginx), 5432 (Postgres) and 8025 (Mailpit). Override per shell:

```sh
export NGINX_HOST_PORT=8080 DB_HOST_PORT=5433 MAILPIT_HOST_PORT=8026
```

or in a git-ignored `compose.override.yaml`.

## CI

`.github/workflows/ci.yaml` starts the same Compose `php` + `database` services and runs `make vendor`, `make lint`, `make audit`, `make test`. `make lint` passing locally means CI lint passes. Pre-commit (`.pre-commit-config.yaml`) runs `make cs-fix` and `make phpstan`; activate with `brew install pre-commit && pre-commit install`.

## Stripe

```sh
STRIPE_API_KEY=sk_test_… make stripe-listen
```

Runs the Stripe CLI in a container and forwards events to `http://nginx/webhooks/stripe`.

## Order of work

`make cs-fix` → `make lint` → `make test`. See `.claude/rules/code-quality.md`.
