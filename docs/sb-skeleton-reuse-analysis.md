# sb-skeleton Reuse Analysis for Song Request SaaS

> Exported from Claude Code session — 2026-09-29

Builds on `brainstorming.md` (guest cookie token, anti-spam, data model) and `songrequest-saas-stack-and-payments-findings.md` (Symfony monolith, Stripe Billing + Connect with TWINT, VPS hosting). That doc's "Keep from the skeleton" list was high level. This one goes through the skeleton file by file.

**Source analysed:** `~/dev/domain-engineering-platform/.claude/skills/sb-skeleton/` (a Claude skill: `SKILL.md` plus 115 files under `assets/skeleton/`). Below, `SK/` means `…/sb-skeleton/assets/skeleton/`.

⚠️ **IP reminder:** this is employer code. Everything marked "keep" below means **re-create the convention** in your own words and code. Don't copy files. Most of the pieces worth keeping are standard Symfony practice or under 30 lines anyway. The contract check is still an open to-do.

## Decisions (2026-09-29)

- **All recommendations in this doc are accepted, except FrankenPHP.**
- **Runtime: php-fpm + nginx, not FrankenPHP.** The `dunglas/symfony-docker` template is out. So the skeleton's own php-fpm + nginx Docker setup becomes more useful (see the Adapt table and Ops pieces).
- Consequences:
  - **Live DJ dashboard:** there's no built-in Mercure hub. Start with 5s polling (Turbo Frame reload). If live push is needed later, run a standalone Mercure hub (`dunglas/mercure` image) as a separate service.
- **Hosting: one cheap VPS (~$5/month), not a PaaS, not Kubernetes.** (Swiss shared hosting was considered and dropped.) This replaces the "Fly.io / Railway / Render" option in the findings doc.
  - **Production = the same Docker Compose stack as local:** `nginx`, `php` (fpm), `worker` (`messenger:consume async`, `restart: unless-stopped`), `database` (postgres:17). Add a Mercure hub container later if polling isn't enough. The "two processes on a PaaS" problem goes away.
  - **TLS:** nginx + Let's Encrypt (certbot), or a small reverse proxy in front.
  - **Deploy:** a simple `make deploy` script (ssh → `git pull` → `docker compose build` → `migrate` → `up -d`), or Kamal later. No registry or CI build needed at first.
  - **You run the database, so you do the backups:** nightly `pg_dump` via cron, copied **off the server** (e.g. object storage), plus a restore test. This matters more than anything else on a single box.
  - **Sizing:** the skeleton's `FPM_MAX_CHILDREN=20` is for K8s pods. On a 2–4 GB VPS use around 5–10, and keep Postgres memory settings modest.
  - **Basics:** SSH keys only, firewall (22/80/443), unattended security upgrades, and an external uptime monitor pinging `/health`.
  - **Data location:** any provider and country is fine; it doesn't have to be Swiss or EU. Just name the provider and country in the privacy policy. For countries without a Swiss adequacy decision (e.g. the US), the provider's standard DPA/SCCs or Swiss-US DPF certification covers it.
- Open questions below are resolved accordingly.

## Summary

| Verdict | What |
|---|---|
| ✅ **Keep (rewrite)** | Bootstrap approach, command bus + handler pattern, `DomainException` + named constructors, UUIDv7 `EntityId`, `FlexibleDateTimeTzImmutableType`, hand-written SQL migrations + Postgres conventions, PHPStan level 7 + phpat architecture tests, 3 PHPUnit suites + Foundry + strict PHPUnit config, Makefile, prod Monolog JSON-to-stderr, prod php.ini/opcache values, `/health` endpoint, CLAUDE.md + `.claude/rules/` layout, `fix-phpstan` skill |
| 🔧 **Adapt** | Validation strategy (Forms instead of OpenAPI), `HandlesCommandFailures` (HTML instead of JSON), `AuthenticatesClient` test trait, tenant column pattern, docker-compose, `.env`, CI, pre-commit, coding standard (PHPCS → PHP-CS-Fixer), spec guide + task skills |
| ❌ **Drop** | Keycloak/JWT security stack, OpenAPI validation + docs, filter/pagination, RabbitMQ + event bus + envelope serializer, OpenTelemetry, K8s deploy image, monorepo CI matrix, `SevenEducation\` namespace, coverage gist badge |

## ✅ Keep: rewrite from scratch

### Bootstrap approach (`SKILL.md`)

- **Design principle:** framework boilerplate comes from the official `composer create-project symfony/skeleton:"7.4.*"` + `composer require`. Only custom conventions are layered on top. Do the same here, but start from the **webapp** set, since we need Twig, Forms, Security, Mailer, Turbo and Messenger with the Doctrine transport.
- Bootstrap gotchas documented in the skill that apply here too:
  - zsh globs unquoted `*` in `"symfony/foo:7.4.*"`. Always quote version constraints.
  - Host PHP may lag 8.5. Use `--ignore-platform-req=php` on the host and do the real install in the container.
  - Set `"php": ">=8.5"` in `composer.json` by hand. `composer config platform.php` only pins resolution.
  - The Doctrine recipe writes a `compose.yaml` that **shadows** `docker-compose.yml`. Pick one filename.
  - `allow-contrib: false` means contrib recipes (e.g. the health-check bundle) aren't auto-registered. Add them to `bundles.php` by hand.
  - After hand-editing `composer.json`, run `composer update --lock --no-install`.

### Command → Handler (`SK/src/MessageHandler/`, `SK/config/packages/messenger.yaml`, `SK/.claude/rules/cqrs.md`)

- Marker interfaces `CommandHandlerInterface` and `QueryHandlerInterface`, auto-tagged to buses via `_instanceof` in `services.yaml`. That's cheap and clean, so keep it.
- `command.bus` with the `dispatch_after_current_bus` + `doctrine_transaction` middleware (`SK/config/packages/messenger.yaml:8`). One transaction per command, which is exactly what "submit request" needs: cooldown check, cap check, duplicate merge and insert all happen atomically.
- Rules worth keeping: commands and queries are `final readonly` DTOs; handlers are `final`, invokable, and return `void` or the created ID (never an entity); business-rule violations throw a `DomainException` subclass; controllers never `persist()` or `flush()`.
- **Simplify:** drop the `query.bus` and `QueryBus` for now. The reads are simple (queue for an event, DJ's events list), so controllers can call repositories directly. The skeleton's own rule already allows plain controller → service for simple cases.
- Candidate commands: `SubmitSongRequest`, `MarkRequestPlayed` / `SkipRequest`, `BlockGuest`, `OpenEvent` / `CloseEvent`, `StartSubscriptionCheckout`, `StartTipCheckout`, `ProcessStripeEvent` (async).

### Exceptions (`SK/src/Exception/DomainException.php`, `SK/.claude/rules/php-architecture.md`)

- Abstract `DomainException extends RuntimeException`. Concrete ones are `final` with **named constructors only**.
- Maps directly to guest-facing messages: `RequestRejected::cooldownActive($secondsLeft)`, `::nightlyCapReached()`, `::eventClosed()`, `PlanLimitExceeded::eventsPerMonth()`.
- Note: "muted guest" must **not** throw. It returns silently so the guest still sees "Request sent!" (see the brainstorm).

### IDs and Doctrine types

- `SK/src/Uid/EntityId.php`: a single place for `Uuid::v7()->toRfc4122()`. Entities take `?string $id = null` so a controller can pre-generate the ID (useful for redirects after POST).
- `SK/src/Doctrine/Type/FlexibleDateTimeTzImmutableType.php`: fixes a real DBAL 4 + Postgres gotcha. `TIMESTAMPTZ` values with microseconds (from `DEFAULT CURRENT_TIMESTAMP`) fail Doctrine's default `Y-m-d H:i:sO` parse. Re-implement it: try both formats, registered as `datetimetz_immutable` in `doctrine.yaml`.
- `SK/config/packages/doctrine.yaml` settings worth mirroring: `server_version: '17'`, `underscore_number_aware` naming, identity generation for Postgres, query/result cache pools, `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` for ParaTest.

### Database conventions (`SK/.claude/rules/database.md`)

These all apply, and several solve songrequest problems directly:

- **Hand-written SQL migrations** with `down()`. Don't use `doctrine:migrations:diff`: it can't express `NULLS NOT DISTINCT`, `CHECK` or function defaults.
- Types: `UUID`, `TIMESTAMPTZ`, `JSONB`, `BOOLEAN`. Constraint names: `uk_`, `chk_`, `fk_`.
- Concrete uses for this app:
  - **Duplicate merge:** `CONSTRAINT uk_requests_event_song UNIQUE NULLS NOT DISTINCT (event_id, title_normalized, artist_normalized)`. Artist is nullable, so `NULLS NOT DISTINCT` is exactly right. Use `INSERT … ON CONFLICT … DO UPDATE SET votes = votes + 1`.
  - `chk_requests_status CHECK (status IN ('new','played','skipped'))`.
  - `chk_tips_min_amount CHECK (amount_rappen >= 500)` (CHF 5 minimum).
  - `uk_stripe_events_id` on processed webhook event IDs (idempotency).
  - `account_guest_blocks` with `PRIMARY KEY (account_id, guest_token)`.
- Standard columns: `created_at` / `updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`. Skip `deleted_at` soft delete and the `extensions JSONB` column until they're needed.

### Quality tooling

- **`SK/phpstan.neon.dist`:** level 7 + the phpat extension. **Decided: stay on level 7**, same as the skeleton, not higher.
- **phpat architecture tests (`SK/tests/Architecture/`):** re-create the ideas behind `ClassModifiersTest` (final/readonly rules), `LayerIsolationTest` (controllers ↛ handlers, handlers ↛ controllers, messages ↛ entities) and `InheritanceContractsTest`. **Add module-boundary rules** for `Requests`, `Events`, `Accounts`, `Billing` and `Tips`, e.g. `Tips` may depend on `Accounts` but not the reverse.
  - Finding: `InfrastructureAccessTest` and `CqrsContractsTest` ship **disabled**, with a TODO saying `->excluding()` is "unconfirmed" (`SK/tests/Architecture/InfrastructureAccessTest.php:18`). But `ClassModifiersTest` already uses `->excluding()` successfully in the same skeleton, so the TODO is stale. Enable both from day one in the new app.
- **`SK/phpunit.dist.xml`:** `failOnDeprecation`/`failOnNotice`/`failOnWarning`, the three suites (Unit / Integration / Application), the Foundry extension, and `src/DataFixtures`, `src/Factory` and `Kernel.php` excluded from coverage. Keep all of it.
- **`SK/Makefile`:** keep `up/down/sh/vendor/sf/cache-clear/db-*/test/test-short/cs-check/cs-fix/phpstan/lint`. `cs-check` becomes `php-cs-fixer check --diff` and `cs-fix` becomes `php-cs-fixer fix`. Drop `openapi-*`. Add `make worker` (`messenger:consume async`) and `make stripe-listen` (`stripe listen --forward-to localhost/webhooks/stripe`).
- **`SK/.claude/rules/code-quality.md`:** keep `make lint` as the single CI gate. With PHP-CS-Fixer, `fix` and `check` use the same rules, so the skeleton's "`cs-fix` clean ≠ CI green" caveat mostly goes away.

### Ops pieces

- **Monolog (`SK/config/packages/monolog.yaml`):** prod `fingers_crossed` → JSON on `php://stderr`, plus a separate always-on `app` channel for business events. Works with `docker compose logs`; set Docker's log rotation (`max-size`) so the VPS disk doesn't fill up. Add Sentry alongside it.
- **Prod PHP ini (`SK/docker/deploy/php/zzz-app-php.ini`):** opcache values (`validate_timestamps=0`, JIT on), `expose_php=Off`, UTC timezone. The values are generic tuning, so re-type them.
- **Entrypoint (`SK/docker/deploy/php/entrypoint.sh`):** `cache:warmup` on start. Run `doctrine:migrations:migrate` as a step in the deploy script, not on every container start.
- **`/health` endpoint** (`SK/config/packages/symfony_health_check.yaml`: Doctrine check, 503 on failure). Use it for the Docker healthcheck and an external uptime monitor. The macpaw bundle or a 15-line controller both work.
- **Local Xdebug (`SK/docker/local/php/php.ini`):** `start_with_request = trigger`, `client_host = host.docker.internal`.

### Claude Code setup

- `SK/CLAUDE.md` structure (stack, Make command table, test suites, architecture, validation strategy, DB rules pointer) makes a good template.
- `.claude/rules/`: carry over `php-architecture.md`, `cqrs.md`, `database.md` and `code-quality.md`, reworded for this app. Skip `communication.md`, since your global CLAUDE.md already covers confirmations.
- `.claude/skills/fix-phpstan`: generic enough to reuse after changing the `apps/__SB_KEBAB__` path.

## 🔧 Adapt

| Skeleton piece | Change for songrequest |
|---|---|
| **Validation strategy** (`SK/CLAUDE.md:50`: OpenAPI listener → 400, handler → 422, DB → 409; "no validator attributes") | Keep the **three-layer idea**, swap the first layer: **Symfony Form + Validator** for structure (required title, max lengths), re-rendered inline with status 422 so Turbo swaps the form. Business rules stay in handlers; uniqueness stays in DB constraints. Commands stay free of validation attributes (put them on a form data class instead). |
| **`HandlesCommandFailures`** (`SK/src/Controller/Trait/`) | Same unwrap loop over `HandlerFailedException::getWrappedExceptions()`, but map a `DomainException` to a **form error / flash message + re-render**, not a JSON 422. Keep a JSON variant only for the Stripe webhook endpoint. |
| **`AuthenticatesClient`** (`SK/tests/Application/`) | Two helpers: `loginAsDj($account)` using `$client->loginUser()`, and `asGuest($token)` that sets the `guest_token` cookie. |
| **Tenant scoping** (`organization_id VARCHAR(36)` + `group_app` from the JWT via `ExtractsRequestIdentity`) | Use `account_id UUID NOT NULL REFERENCES accounts(id)`: a real FK, since it's one database. Tenant comes from the logged-in DJ user. Enforce with a Voter (`EVENT_MANAGE`) plus account-filtered repository methods. A Doctrine SQL filter is optional later. |
| **`docker-compose.yml`** | Keep `php` + `database` (postgres:17-alpine with healthcheck, `${DB_HOST_PORT:-5432}` override pattern). Keep `nginx` too (**decided: php-fpm + nginx, no FrankenPHP**). `SK/docker/local/nginx/default.conf` is the standard Symfony front-controller config and can be re-typed as is. Drop `rabbitmq`, OTel env vars and the `libs/coding-standard` mount. Add Mailpit, and a standalone Mercure hub only if polling isn't enough. |
| **`.env`** | Keep the layout and `DATABASE_URL`. Replace Keycloak, RabbitMQ and OTel with `MESSENGER_TRANSPORT_DSN=doctrine://default`, `MAILER_DSN`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET`, `SENTRY_DSN`, `MERCURE_*`. |
| **`messenger.yaml`** | Keep `command.bus` (+ `doctrine_transaction`). Drop `event.bus`, `RoutingKeyMiddleware`, `EventEnvelopeSerializer`, `CorrelationIdStamp` and AMQP. Add an `async` Doctrine transport with a `failed` transport, routing `ProcessStripeEvent` and emails there. |
| **`security.yaml`** | It's `stateless: true` with an OIDC token handler (`SK/config/packages/security.yaml:13`). Replace it: a `User` entity provider, `form_login`, `remember_me`, `login_throttling`, plus `symfonycasts/reset-password-bundle` and `verify-email-bundle`. Keep the `health` / `dev_profiler` firewalls with `security: false`. **Guest token:** a `kernel.request`/`kernel.response` listener that issues the HttpOnly cookie, plus an argument resolver for `GuestToken`, is simpler than a second firewall (guests aren't authenticated users). |
| **PHPCS** (`SK/phpcs.xml:3` references the monorepo-only `SevenEducation` ruleset) | **Decided: PHP-CS-Fixer with `@Symfony`** (+ `@Symfony:risky`, `declare_strict_types`) in `.php-cs-fixer.dist.php`. One tool, a short config, and it fixes everything it reports. Tests keep snake_case method names (`php_unit_method_casing: snake_case`). |
| **CI** (`SK/.github/ci.yaml.tpl` is the monorepo's dynamic-matrix format) | A plain GitHub Actions workflow: Postgres service → `composer install` → `make lint` → `phpunit` per suite. Drop `build-setup.sh` and the coverage gist. |
| **`.pre-commit-config.yaml`** | Same two hooks, now `php-cs-fixer fix` + phpstan, without the `cd apps/…` prefix. |
| **`specs/SPEC-GUIDE.md` + `start-task` / `task-spec` skills** | These are tied to Jira and `sdui.atlassian.net`. Keep a lightweight `specs/` template (Context / Requirements / Approach / Acceptance criteria) keyed to GitHub issues, or skip it until there's more than one contributor. |
| **`templates/base.html.twig`** | This is the stock recipe file (FrankenPHP hot reload). Replace it with your own layout: lang switcher for DE/FR/IT, mobile-first guest page. |

## ❌ Drop

- **Security stack:** `ServiceUser`, `ServiceTokenUserProvider`, `Scope` enum, `#[RequiresScope]` + `ScopeCheckListener`, `DevAuthenticator` (the `TOKEN_VALIDATION_ENABLED` bypass), `cache.oidc_jwks`. All of it exists for Keycloak machine-to-machine auth.
- **OpenAPI:** `OpenApiValidationListener`, `OpenApiValidatorBuilderFactory` (a clever content-hash cache key, but irrelevant here), `DocumentationController` (Swagger UI), `docs/openapi/**`, `league/openapi-psr7-validator`, `nyholm/psr7`, the PSR-7 bridge.
- **Filter / pagination:** `src/Query/Filter/*`, `src/Pagination/*`. There are no public list APIs. If the DJ's event history ever needs paging, use Doctrine's `Paginator`.
- **Eventing:** RabbitMQ, `EventPublisher`, `DomainEventInterface`, `RoutingKeyMiddleware`, `EventEnvelopeSerializer`, `CorrelationIdStamp`, `docker/local/rabbitmq/*`.
- **Observability:** the OpenTelemetry packages and PECL extension. Use Sentry instead.
- **Deploy (K8s-specific parts only):** `cgi-fcgi-wrapper.sh` for K8s probes, the AMQP/OpenTelemetry extension installs, and the "vendor pre-installed by CI" assumption. Since we're staying on php-fpm, **keep the rest of `docker/deploy/php/Dockerfile` as a model** (rewritten): `php:8.5-fpm` base, `php.ini-production` + app ini overlay, FPM pool env tuning (`zzz-app-php-fpm.conf`), and the cache-warmup entrypoint.
- **Monorepo coupling:** `SevenEducation\…App` namespace (use `App\`), `/__SB_KEBAB__/v1` route prefix, `*.sdux.de`, `7education/coding-standard`, `.claude/settings.json` (`gh api user`), README coverage badge.
- **`serializer.yaml`** snake_case JSON config: no JSON API. The Stripe webhook payload is parsed by `stripe-php`.

## Suggested bootstrap sequence

1. `composer create-project symfony/skeleton:"7.4.*" songrequest`, then `composer require webapp`. Docker: php-fpm + nginx + worker + Postgres, plus Mailpit locally (no FrankenPHP). The same Compose file (with a prod override) runs on the VPS.
2. `composer require` in addition: `symfony/uid`, `stripe/stripe-php`, `symfonycasts/reset-password-bundle`, `symfonycasts/verify-email-bundle`, `sentry/sentry-symfony`, `symfony/rate-limiter`. Dev: `phpstan/phpstan`, `phpat/phpat`, `zenstruck/foundry`, `doctrine/doctrine-fixtures-bundle`, `friendsofphp/php-cs-fixer`. Quote every `*`.
3. Write by hand: `EntityId`, `FlexibleDateTimeTzImmutableType`, `DomainException`, handler marker interfaces + `_instanceof` tagging, `messenger.yaml` (`command.bus` + `async` Doctrine transport).
4. Write the first migration in SQL: `accounts`, `users`, `events`, `guests`, `requests` (with `uk_requests_event_song NULLS NOT DISTINCT`), `account_guest_blocks`.
5. Set up the tooling: `phpstan.neon.dist` + phpat tests (including module boundaries), `phpunit.dist.xml` with 3 suites, Makefile, pre-commit, GitHub Actions.
6. Write `CLAUDE.md` + `.claude/rules/` for this app (adapted validation strategy, DB rules, CQRS rules).
7. First vertical slice: `SubmitSongRequest` handler + guest-token listener + an Application test using `asGuest()`.

## Open questions

- [ ] Employment contract: are re-created *conventions* OK? (Same to-do as the findings doc.)
- [x] ~~FrankenPHP vs php-fpm + nginx~~ → **php-fpm + nginx** (FrankenPHP rejected).
- [x] ~~Plain repository reads vs a `query.bus`~~ → **plain repository reads**, no `query.bus`.
- [x] ~~PHPStan level~~ → **7**, same as the skeleton, not higher.
- [x] ~~Coding standard~~ → **PHP-CS-Fixer `@Symfony`**.
- [ ] Live updates: is polling enough, or add a standalone Mercure hub?
- [x] ~~Hosting / process model~~ → **~$5 VPS running Docker Compose** (nginx + php-fpm + worker + Postgres).
- [ ] Which VPS provider (location is unrestricted)?
- [ ] Where the off-server `pg_dump` backups go.
