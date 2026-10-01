# Song Request SaaS: Roadmap

> Exported from Claude Code session — 2026-09-29

What to build and in which order. The decisions behind it are in `songrequest-saas-stack-and-payments-findings.md` (stack, payments, competitor) and `sb-skeleton-reuse-analysis.md` (conventions and setup details).

**Decided so far:** Symfony 7.4 / PHP 8.5, php-fpm + nginx (no FrankenPHP), PostgreSQL 17, Docker Compose locally and in production on a ~$5 VPS (any provider and country), PHPStan level 7, PHP-CS-Fixer `@Symfony`.

## Phase 0: Project setup

Goal: an empty app where `make lint` and `make test` pass, locally and in CI.

1. Put `songrequest/` under git. Move the existing notes into `docs/`.
2. Bootstrap: `composer create-project symfony/skeleton:"7.4.*"`, then `composer require webapp`. Add `symfony/uid`, `symfony/rate-limiter`, `stripe/stripe-php`, `symfonycasts/reset-password-bundle`, `symfonycasts/verify-email-bundle`, `sentry/sentry-symfony`. Dev: `phpstan/phpstan`, `phpat/phpat`, `zenstruck/foundry`, `doctrine/doctrine-fixtures-bundle`, `friendsofphp/php-cs-fixer`. Quote every `*` (zsh).
3. Docker Compose: `nginx`, `php` (fpm, Xdebug on trigger), `worker` (`messenger:consume async`), `database` (postgres:17 with healthcheck), `mailpit`. Make the host ports overridable via env.
4. Base code:
   - `EntityId` (UUIDv7)
   - `FlexibleDateTimeTzImmutableType`
   - abstract `DomainException`
   - `CommandHandlerInterface`, auto-tagged to `command.bus` in `services.yaml`
   - `messenger.yaml`: `command.bus` with `doctrine_transaction`, plus an `async` Doctrine transport and a `failed` transport
5. Tooling:
   - `.php-cs-fixer.dist.php` (`@Symfony`, `@Symfony:risky`, `declare_strict_types`, snake_case test methods)
   - `phpstan.neon.dist` at level 7 with phpat
   - Architecture tests: class modifiers, layer isolation, inheritance, infrastructure access, and module boundaries for `Requests`, `Events`, `Accounts`, `Billing`, `Tips`
   - `phpunit.dist.xml` with the Unit / Integration / Application suites and strict fail flags
6. `Makefile` (`up/down/sh/sf/db-*/test/cs-check/cs-fix/phpstan/lint/worker/stripe-listen`), `.pre-commit-config.yaml`, and a GitHub Actions workflow (Postgres service → lint → tests).
7. `CLAUDE.md` + `.claude/rules/`: php-architecture, cqrs, database, code-quality, and the adapted validation strategy (Forms → handler → DB constraints).
8. `/health` endpoint with a Doctrine check.

## Phase 1: Guest request flow (no payments)

Goal: usable at a real gig with one hard-coded DJ, and it's the core product. No Stripe needed.

1. First SQL migration: `accounts`, `users`, `events`, `guests`, `requests` (with `uk_requests_event_song UNIQUE NULLS NOT DISTINCT` and `chk_requests_status`), `account_guest_blocks`.
2. Guest token: a listener issues `guest_token` (HttpOnly, Secure, SameSite=Lax, 1 day), and an argument resolver hands it to controllers.
3. Event page `/r/{slug}` with a QR code. Closed events reject requests.
4. `SubmitSongRequest` command + handler:
   - enforces the cooldown (RateLimiter) and the nightly cap
   - duplicates add a vote (`ON CONFLICT … votes + 1`)
   - muted guests are dropped silently, and the guest still sees "Request sent!"
5. Guest form: Symfony Form, works without JS, 422 re-render on errors.
6. DJ queue view: sorted by votes and time. Mark as played or skipped, block a guest, stop requests. Reloads every 5s (Turbo Frame).
7. Tests: unit tests for the handler rules, application tests for the guest flow (`asGuest()` helper) and the DJ flow.

## Phase 2: DJ accounts and tenancy

Goal: any DJ can sign up and sees only their own data.

1. Signup with email verification, `form_login` + `remember_me` + login throttling, password reset.
2. `account_id` on all tenant tables. A voter (`EVENT_MANAGE`) plus repository methods that filter by account.
3. DJ's event management: create, open and close events, a permanent QR code, and a max number of requests per event.
4. Tests proving DJ A can't see or change DJ B's events.

## Phase 3: DJ subscriptions (Stripe Billing + TWINT)

Goal: DJs pay a monthly CHF subscription with TWINT or card.

1. `subscriptions` table plus plan limits (free tier: request cap, no tips).
2. "Subscribe" → Stripe Checkout in `subscription` mode (TWINT + cards, CHF, localized). "Manage subscription" → Stripe Customer Portal.
3. Webhook endpoint: verify the signature, store the event ID for idempotency, and dispatch `ProcessStripeEvent` to the `async` transport.
4. Handle `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated` and `customer.subscription.deleted`.
5. One Stripe customer per DJ, and upgrades change the existing subscription (the TWINT mandate-collision rule).
6. Enforce plan limits from the local table only. Never call Stripe during a guest request.

## Phase 4: Guest tips (Stripe Connect + TWINT)

Goal: guests tip the DJ with TWINT, linked to their request.

1. DJ onboarding: a "Get paid: enter your bank details" button starts Stripe Connect onboarding. Sync `charges_enabled` / `payouts_enabled` via `account.updated`.
2. `tips` table (`request_id`, `amount_rappen >= 500`, currency, `stripe_payment_intent_id`, status).
3. "Tip CHF 5 with TWINT" → Checkout Session on the DJ's connected account (direct charge, `payment_method_types: ['twint']`).
4. The webhook marks the request as tipped, and the DJ sees it in the queue.
5. Decide the open points before building: minimum tip, whether tips skip the line or the cooldown, platform fee, refund policy.

## Phase 5: Production server

Goal: live on the VPS, with backups that are proven to restore.

1. VPS: SSH keys only, firewall (22/80/443), automatic security updates, Docker.
2. Prod Compose override: prod `php.ini`/opcache values, FPM children tuned for the VPS RAM (5–10), Docker log rotation, TLS via nginx + Let's Encrypt.
3. `make deploy`: ssh → `git pull` → `docker compose build` → `doctrine:migrations:migrate` → `up -d` → `cache:warmup`.
4. Nightly `pg_dump` via cron to off-server storage, plus a tested restore.
5. Sentry, an external uptime monitor on `/health`, and Stripe webhook endpoints set to the production URL.
6. Privacy policy naming the hosting provider and country.

Phase 5 can also run earlier, right after Phase 1, so you can try the app at a real gig.

## Parked for now

- Ask Stripe support whether "tips for DJs" is an accepted business type for Connect. Needed before Phase 4.
- Check the employment contract regarding recreating the `sb-skeleton` conventions.
- Pick the VPS provider and the off-server backup target.
- Later features: Mercure live push, Serato/VirtualDJ integration, optional Google sign-in for stricter limits.
