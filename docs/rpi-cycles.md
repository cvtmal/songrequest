# Song Request: RPI cycles

_Last updated: 2026-10-02 · Source: `prd.md` (scope), `roadmap.md` (phases)_

This file splits every requirement in `prd.md` into `/rpi` cycles and sets the order to run them in. One cycle = one slug = one pass through Research → Plan → Implement → Review, which ends with `make lint` and `make test` green and ideally one commit or PR.

## How to run a cycle

1. `/rpi-research <slug>`, giving the cycle's section below as the task description. Cycles marked **Plan** in the overview have clear enough requirements to start at `/rpi-plan <slug>` instead.
2. `/clear` → `/rpi-plan <slug>` → `/clear` → `/rpi-implement <slug>` → `/clear` → `/rpi-review <slug>`.
3. If the review says `Needs Rework`, re-enter the phase it names. Start the next cycle only once the review says `Complete`.
4. `/rpi <slug>` tells you where you are if you lose your place.

Artifacts land in `.rpi-tracking/{research,plans,changes,reviews}/<date>/<slug>-*.md`.

**Gates.** Some cycles depend on an open question in `prd.md` §12. Answer it before that cycle's research (or make the research end with a recommendation for you to confirm) and record the answer in `prd.md`.

## Overview and order of execution

### Important
- in /rpi cycles never ask the owner to make a decision; apply your own recommendation and record it in the research. The owner will always follow your recommendation.
- every full cycle ends with exactly one commit, then push to main. That way we can save opening a PR and squash-merging, and the commit message is the cycle slug.
- If a cycle needs rework, the rework goes in a new commit on top of the previous one.
- make sure the application stays reachable for the owner via cloudflared tunnel --url http://localhost:8080

| # | Slug | Release | PRD IDs | Depends on | Gate | Start at |
|---|---|---|---|---|---|---|
| 0 | `songrequest-base` | R0 | Phase 0 | — | — | ✅ Done |
| 1 | `core-schema` | R1 | §10 (R1 tables), AS-8 (constraints) | 0 | — | ✅ Done |
| 2 | `submit-song-request` | R1 | AS-2, AS-3, AS-5, AS-6 (drop), AS-7, AS-8, AS-9 | 1 | — | ✅ Done |
| 3 | `guest-request-page` | R1 | GR-1–GR-6, AS-1 | 2 | — | ✅ Done |
| 4 | `dj-login` | R1 | AC-2 | 1 | — | ✅ Done |
| 5 | `event-lifecycle-qr` | R1 | EV-1 (minimal), EV-2, EV-3 | 4 | — | ✅ Done |
| 6 | `dj-queue` | R1 | DQ-1–DQ-7, AS-6 (block), DQ-4 | 2, 4, 5 | — | Research |
| 7 | `prod-deploy` | R5 | NFR security, OP-2 | 6 | Q13 | Research |
| 8 | `prod-backups-monitoring` | R5 | NFR availability, OP-3 | 7 | Q13 | Research |
| 9 | `i18n-foundation` | R2 | NO-1 (language), NFR localization | 6 | Q10 (default language only) | Research |
| 10 | `dj-signup-verify` | R2 | AC-1, NO-1 | 9 | — | Research |
| 11 | `tenant-isolation` | R2 | AC-5 | 10 | — | Research |
| 12 | `dj-account-settings` | R2 | AC-3, AC-4 | 11 | — | Plan |
| 13 | `event-management` | R2 | EV-1, EV-5, EV-6, EV-8, AS-4 | 11 | Q4 | Research |
| 14 | `permanent-qr` | R2 | EV-4 | 13 | Q5 | Plan |
| 15 | `guest-data-retention` | R2 | NFR privacy | 13 | Q9 | Research |
| 16 | `operator-console` | R2 | OP-1 | 11 | — | Plan |
| 17 | `guest-engagement-extras` | R2 | GR-8, GR-9, GR-10, DQ-8 | 13 | — | Research |
| 18 | `plans-and-limits` | R3 | BI-1, BI-6, BI-8, BI-10, BI-9 | 13 | Q1, Q2 | Research |
| 19 | `stripe-webhooks` | R3 | BI-5 (infrastructure) | 18 | — | Research |
| 20 | `stripe-subscribe` | R3 | BI-2, BI-3, BI-4 | 19 | Q14 (Stripe branding) | Research |
| 21 | `subscription-lifecycle` | R3 | BI-5 (handlers), BI-7, NO-1 (billing mails), NO-2 | 20 | Q3 | Research |
| 22 | `localization-complete` | R3 | GR-7, NFR localization | 9, 21 | Q10 | Research |
| 23 | `public-site` | R3 | PS-1, PS-2, PS-3 | 22 | Q13, Q14 | Plan |
| 24 | `account-deletion` | R3 | AC-6 | 20 | — | Research |
| 25 | `performance-baseline` | R3 | NFR performance | 22 | — | Research |
| 26 | `connect-onboarding` | R4 | TI-1, TI-2, TI-9 | 21 | Q11, Q12 | Research |
| 27 | `tip-checkout` | R4 | TI-3, TI-4, TI-5, TI-7, TI-8 | 26 | Q7 | Research |
| 28 | `tip-confirmation` | R4 | TI-6, TI-10, TI-11, DQ-1/DQ-2 (tip marks) | 27 | Q6, Q8 | Research |
| 29 | `event-summary` | R4 | DQ-9 | 28 | — | Plan |

**Milestones.** After #6 the app is gig-ready for the owner (R1). After #8 it runs on the VPS with tested backups (R5), which is required before any other DJ gets access. After #17 invited beta DJs can use it (R2). After #25 it can launch publicly (R3). After #29 tips are live (R4).

**What can move.** #7–#8 can run right after #6 (the roadmap allows it) or be deferred until just before #17 if you want to test R1 locally first; they must not move after R2's release. #16, #17 and #25 have no cycle depending on them and can slip. #23 and #24 are independent of each other.

## Cross-cutting decisions to settle in research

These came up while mapping the PRD onto the module rules in `CLAUDE.md`. Each must be decided in the research of the cycle named, not worked around during implementation.

1. **Plan limits vs module boundaries (#18).** `Requests` must enforce the Free request cap (BI-6), but `Requests` may not depend on `Billing`. Options: Billing writes the effective plan/limits onto the account (`Accounts`), which `Requests` may read; or a contract in `Shared`. Changing the allowed-dependency table is a design change for the owner to approve, not a fix for a phpat error.
2. **Tips vs Billing (#26–#28).** `Tips` needs "DJ is on a paid plan" (TI-3) and Stripe webhooks (`account.updated`, `checkout.session.completed` on connected accounts), but may not depend on `Billing`. Decide where the webhook endpoint and `stripe_events` live (`Billing`, `Shared`, or per-module endpoints) in #19, with #26–#28 in mind.
3. **`request_votes` vs `requests.guest_token` (#1).** The PRD (§10) leaves this to Phase 1. Per-guest caps, "my requests" (GR-8) and blocking need to know which guest asked for which song. **Settled in #1:** `request_votes` (one row per accepted submission, with `guest_id`, the nickname and copies of `event_id`/`account_id`) is the only record of who asked for what; `requests` has no guest column. `guests` has its own UUID `id`, and the cookie value lives in `guests.token`.
4. **Scheduled jobs (#13).** EV-8 auto-close is the first scheduled task; #15 retention and maybe #21 grace-period fallback follow. Pick one mechanism (Symfony Scheduler on the worker vs host cron calling `bin/console`) in #13 and reuse it.
5. **Translation keys from day one (#3).** R1 templates should use translation keys with German (or the Q10 default) as the only catalogue, so #9 and #22 don't have to rewrite every template. **Settled in #3:** German only, ICU messages in `translations/messages+intl-icu.de.yaml`; Q10 stays open for #22.
6. **Design references.** `templates/design/` already holds static mockups (`guest`, `dj_queue`, `events`, `event_settings`, `auth`, `plan`, `get_paid`, `index`). Each UI cycle's research should start from the matching mockup.

## R1 Gig-ready (roadmap Phase 1)

Goal: the owner uses it at a real gig. One seeded DJ, no payments.

### 1. `core-schema`

- First hand-written SQL migration for the R1 tables: `accounts`, `users`, `events`, `guests`, `requests`, `request_votes`, `account_guest_blocks` (PRD §10). Every tenant table carries `account_id` now, even though R1 has one DJ.
- Constraints that AS-8 relies on: `uk_requests_event_song UNIQUE NULLS NOT DISTINCT` on the normalised title/artist, `chk_requests_status`, event `status` check (open/stopped/closed), unique `events.slug`, unique `users.email`, unique block per (account, guest token).
- Leave out columns that later cycles own (`stripe_*`, `charges_enabled`, `tips_enabled`, `max_requests`, `permanent_slug`). Each cycle adds its own migration.
- Entities, repositories, Foundry factories, and an `AppStory` that seeds one DJ with one open event.
- Settle decision 3 (`request_votes`).
- Done when: migration up/down works on `app_test`; integration tests prove the unique and check constraints fire.
- **Outcome (2026-10-01):** migration `Version20261001120000`; the entity for `requests` is `SongRequest`. `uk_requests_event_song` is a partial index (`WHERE status = 'new'`), so #2's merge must write `ON CONFLICT (event_id, title_normalized, artist_normalized) WHERE status = 'new'` and set `updated_at = CURRENT_TIMESTAMP` itself. Normalised columns exist only after flush. Foundry resets dev and test through the real migrations. To seed dev: `make db-fresh`, then `make sf c="foundry:load-fixtures main --append -n"`.

### 2. `submit-song-request`

- `SubmitSongRequest` command + handler in `Requests`, running in one transaction on `command.bus`.
- Cooldown per guest per event, default 5 min, with "time left" in the exception (AS-2). Per-guest cap, default 3 (AS-3). IP backstop of 30/h per IP per event (AS-7). Research: Symfony RateLimiter vs counting `request_votes` rows in SQL, given AS-8 asks for guarantees under concurrent submits.
- Duplicate merge via `INSERT … ON CONFLICT … votes + 1` using the normalisation rules in AS-5. Duplicates still count against cooldown and cap.
- Muted guest (AS-6): the request is dropped without error and the handler reports success.
- Event not open / stopped → domain exception (GR-5 server side).
- No device IDs, fingerprinting or SMS (AS-9).
- Done when: unit tests for each rule, plus an integration test that fires concurrent submits and shows caps and merges hold.
- **Outcome (2026-10-02):** no RateLimiter. The handler locks the event row (`PESSIMISTIC_WRITE`) and counts cooldown, cap and IP limits in SQL over `request_votes`, so every check sees each earlier accepted vote. Migration `Version20261001130000` adds `request_votes.ip_address` (`VARCHAR(45) NULL`). Limits are the `requests.cooldown_seconds`, `requests.max_per_guest` and `requests.max_per_ip_per_hour` container parameters. #3 maps these exceptions from `App\Requests\Exception` to messages: `EventNotFound`, `EventClosed`, `RequestsStopped`, `GuestRequestLimitReached`, `RequestCooldownActive` (`getSecondsLeft()`) and `IpRequestLimitReached`. Muted guests and a guest re-requesting their own queued song return success with nothing written. The handler creates the `guests` row on first submit, so #3's cookie listener only sets the cookie. #3 must configure trusted proxies before it passes `Request::getClientIp()`; otherwise every guest shares the proxy's IP and AS-7 caps the whole event at 30/h. #3 must also reject or reissue a `guest_token` that is not a UUID before dispatching: `guests.token` is a `UUID` column, so a tampered cookie otherwise surfaces as a 500.

### 3. `guest-request-page`

- Listener issuing `guest_token` (`HttpOnly; Secure; SameSite=Lax; Max-Age=86400`, server-generated, AS-1) and an argument resolver that hands it to controllers.
- `GET/POST /r/{slug}`: event and DJ name, form with title (required), artist, nickname (GR-1, GR-2).
- Works without JS: normal form post, 422 re-render keeping values (GR-3). "Request sent!" plus "send another" (GR-4). Closed/stopped event shows a message and no form; a forced POST is rejected (GR-5). Friendly cooldown/cap messages.
- Dark, high-contrast, ≥ 44 px tap targets, small page weight (GR-6, NFR accessibility). Start from `templates/design/guest.html.twig`.
- Translation keys from the start (decision 5).
- Done when: application tests with an `asGuest()` helper cover success, 422, cooldown, cap, duplicate, muted and closed paths.
- **Outcome (2026-10-02):** the `guest_token` comes from the targeted `GuestTokenValueResolver` (pinned with `#[ValueResolver]`) plus `GuestTokenCookieListener`, in the new layers `ValueResolver` and `EventListener`; a cookie that is not a UUID is reissued. A successful submit redirects (303) to a `UriSigner`-signed `/r/{slug}/sent?title=…`, so the success page repeats the title without a session; an unsigned or tampered link shows the page without the title. Domain exceptions map to German messages with 422; a closed or stopped event shows no form, and a POST to it gets 422. Guest pages use the CSS-only `guest` importmap entry and no Google Fonts. `default_locale: de` with the `messages+intl-icu` and `validators` catalogues. `SYMFONY_TRUSTED_PROXIES=private_ranges` is tracked in `.env`; #7 must make nginx overwrite `X-Forwarded-For` in production, or any client can spoof its IP past AS-7.

### 4. `dj-login`

- Security firewall with `form_login` against `users`, `remember_me`, login throttling, logout, CSRF (AC-2, NFR security).
- A console command to create the seeded DJ user with a hashed password (signup arrives in #10).
- Start from `templates/design/auth.html.twig`.
- Done when: application tests for login, bad password, throttling, and that DJ routes redirect anonymous users.
- **Outcome (2026-10-02):** `/login` lives in `Accounts`; the `/dj` landing page (`dj_home`) lives in `Events`, and #5 turns it into the event list. `^/dj` requires `ROLE_USER`. A `guest` firewall (`^/r/`, `security: false`) keeps guest pages session-free. Login ignores email case through `UserRepository::loadUserByIdentifier()`. Throttling allows 5 failed attempts per minute per email+IP and 25 per minute per IP, stored in `cache.rate_limiter`. Remember-me is ticked by default: a 30-day rolling signature cookie that a password change invalidates. Logout is a CSRF-protected POST. The new `Console` layer holds `app:create-dj <email> <stage-name>`, which dispatches `CreateDjAccount` (reusable by #10); a duplicate email surfaces as `EmailAlreadyRegistered`, caught from `uk_users_email`. The seed DJ logs in as `dj@example.com` / `password`. #7 must set a real `APP_SECRET` (it signs remember-me cookies) and persistent session storage. #16 adds a `UserChecker` on `main`.

### 5. `event-lifecycle-qr`

- Minimal event creation (name only) with a unique, hard-to-guess slug (EV-1, completed in #13).
- Open / close an event. Closing is permanent for that link (EV-2).
- QR code per event: full-screen view, PNG and SVG download, print layout (EV-3). Research the QR library (needs a new Composer package, installed in the container).
- Done when: application tests for create/open/close and that a closed event's guest page rejects requests; QR endpoints return valid images.
- **Outcome (2026-10-02):** `/dj` (`dj_home`) is the event list: active (open and stopped) events above closed ones. `/dj/events/new` (`event_new`) takes a name only and redirects to the QR page; `/dj/events/{id}/close` (`event_close`) is a CSRF-protected POST with a `data-turbo-confirm` prompt; `/dj/events/{id}/qr`, `qr.png` and `qr.svg` show and download the code. Creating an event opens it, so there is no draft state (revisit in #13). `Event::close()` is one-way and idempotent: no method leaves `closed`, a second close is a no-op, and `CloseEventHandler` takes a row lock so an in-flight submit finishes first or sees `closed`. `CreateEventHandler` generates a 10-character slug from `23456789abcdefghjkmnpqrstuvwxyz` (no `0 o 1 l i`, about 49.5 bits; `uk_events_slug` guards collisions) and returns the new ID through `HandledStamp`. DJ routes and `CloseEvent` are scoped by account in queries (another account's event is a 404); #11 adds the `EVENT_MANAGE` voter on top. QR codes come from `endroid/qr-code` ^6.1; the PHP image now has `gd` for PNG, so existing checkouts need `make build`. The QR code encodes the guest URL built from the current request, so a code made through the quick tunnel carries its random hostname and stops working when the tunnel URL changes; print codes only after #7 gives a fixed host, or regenerate them per gig.

### 6. `dj-queue`

- Queue for an event: open requests sorted by votes desc, then first request asc (DQ-1). Rows show title, artist, votes, nickname(s), age, and a tip slot left empty until R4 (DQ-2).
- One tap played / skipped, a "done" list with undo (DQ-3). One tap block guest (AS-6, DQ-4) and an account block list with undo.
- Stop Requests / Resume toggle (DQ-5).
- Turbo Frame polling every ≤ 5 s (DQ-6). One-handed on a phone, readable on a laptop (DQ-7). Start from `templates/design/dj_queue.html.twig`.
- All writes through commands on `command.bus`.
- Done when: application tests for each action, the sort order and block/undo; muted guest's later requests don't appear.

## R5 Production hardening (roadmap Phase 5, run early)

Required before R2 goes to other DJs.

### 7. `prod-deploy`

- VPS setup notes and scripts: SSH keys only, firewall 22/80/443, unattended upgrades, Docker.
- Prod Compose override: prod `php.ini`/opcache, FPM children sized for the VPS RAM, Docker log rotation, nginx TLS with Let's Encrypt, HSTS, the `worker` service running in prod.
- `make deploy` (ssh → pull → build → migrate → up -d → cache:warmup) and a deploy freeze note for Fri 16:00 – Sun 06:00.
- Check that failed Messenger messages land in `failed` and that retry works (OP-2).
- Gate: Q13 (provider).
- Done when: the app answers on HTTPS at the production host and a deploy runs end to end.

### 8. `prod-backups-monitoring`

- Nightly `pg_dump` to off-server storage, a documented restore, and one restore actually tested.
- Sentry for PHP errors, an external uptime monitor on `/health` (OP-3).
- Gate: Q13 (backup target).
- Done when: a restore from last night's dump into a fresh database works, and a test error shows up in Sentry.

## R2 Multi-DJ (roadmap Phase 2)

Goal: invited DJs sign up and see only their own data.

### 9. `i18n-foundation`

- Translator config, locale resolution (DJ: account locale; guest: browser language with a cookie override), Swiss date/number formats, and moving any leftover hard-coded strings into catalogues.
- Ships one complete language (Q10 default). FR/IT and the guest's manual switch come in #22.
- Done when: switching an account's locale changes the DJ UI and email language in tests.

### 10. `dj-signup-verify`

- Signup with email and password; email verification before an event can be opened (AC-1), using `symfonycasts/verify-email-bundle`.
- Async transactional email through Messenger in the DJ's language: verify and welcome (NO-1).
- Creates the account and user together; stage name can be set during signup (flow §8.3).
- Done when: application tests for signup, verify link, and "unverified DJ cannot open an event"; mail visible in Mailpit locally.

### 11. `tenant-isolation`

- `EVENT_MANAGE` voter (and others as needed) on every DJ action; repository methods for DJ reads filter by `account_id` (AC-5).
- Go back over everything from #5 and #6 (queue, block list, QR, open/close) and put it behind the voter.
- Done when: tests show DJ A gets 403/404 on DJ B's events, requests, blocks and QR codes by ID and by slug.

### 12. `dj-account-settings`

- Password reset by email (AC-3), using `symfonycasts/reset-password-bundle`.
- Profile: stage name (shown to guests), contact email, language (AC-4).
- Done when: application tests for the reset flow and profile edits.

### 13. `event-management`

- Full event creation: name, optional date and venue (EV-1).
- Event list split into upcoming/open and past, with request counts (EV-5). Start from `templates/design/events.html.twig` and `event_settings.html.twig`.
- Max requests per event; reaching it behaves like Stop Requests (AS-4, EV-6). Cooldown/cap overrides only if Q4 says so.
- Auto-close after a DJ-set time or 12 h after opening (EV-8). Settle decision 4 (scheduler).
- Gate: Q4.
- Done when: tests for the cap, auto-close, and the list's counts.

### 14. `permanent-qr`

- One permanent slug and QR per DJ (`accounts.permanent_slug`) that redirects to the currently open event or shows "not taking requests right now" (EV-4).
- Gate: Q5 (two open events).
- Done when: application tests for zero, one and two open events.

### 15. `guest-data-retention`

- Scheduled clean-up: drop IPs after the retention window, anonymise guest tokens and nicknames after events close (NFR privacy).
- Gate: Q9 (periods). Uses the mechanism from #13.
- Done when: integration tests show data older than the window is removed or anonymised and newer data is kept.

### 16. `operator-console`

- Console commands: look up an account by email, disable/enable an account, resend verification (OP-1). A disabled account can't log in and its guest pages stop taking requests.
- Done when: integration tests per command.

### 17. `guest-engagement-extras`

Should/could items. Cut any of them if R2 is late.

- Guest sees their requests tonight and whether each was played (GR-8, S).
- Optional public queue, titles and votes only, off by default (GR-9, C), with "+1" (GR-10, C).
- Visual cue for new requests in the DJ queue, opt-in sound (DQ-8, S).

## R3 Paid (roadmap Phase 3)

Goal: public launch with Free and Pro plans.

### 18. `plans-and-limits`

- `subscriptions` table (local mirror, source of truth for limits) and plan definitions: Free (request cap, no tips) and Pro (BI-1, BI-6).
- Enforce Free limits inside the guest request handler from local data only, never calling Stripe (BI-6). Settle decision 1 first.
- Downgrade keeps data; only features above Free stop (BI-8). Limits shown where they bite, with an upgrade link (BI-10). Trial handling if Q2 says yes (BI-9). Start from `templates/design/plan.html.twig`.
- Gate: Q1, Q2.
- Done when: tests prove a Free DJ hits the cap and no Stripe client is touched during a guest request.

### 19. `stripe-webhooks`

- `POST /webhooks/stripe`: signature check, store `stripe_events.stripe_event_id` (unique) for idempotency, dispatch `ProcessStripeEvent` to `async`, return 2xx fast (BI-5). Settle decision 2 so Connect events in R4 fit the same design.
- Local testing through `make stripe-listen`.
- Done when: tests for bad signature, duplicate event ID and async dispatch.

### 20. `stripe-subscribe`

- One Stripe customer per account, created on first use (BI-4).
- "Subscribe" → Checkout in subscription mode, CHF, TWINT + cards, localized (BI-2). "Manage subscription" → Customer Portal (BI-3).
- Plan changes modify the existing subscription and never start a second Checkout (BI-4, TWINT mandate rule).
- Done when: tests with a stubbed Stripe client cover customer reuse and "no second Checkout"; a manual run in test mode with TWINT reaches the success page.

### 21. `subscription-lifecycle`

- Handlers for `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted` that update `subscriptions` (BI-5, flow §8.4).
- Grace period on failed renewal, then fallback to Free (BI-7). Stripe sends dunning and receipt emails (NO-2). We send "subscription started/ended" in the DJ's language (NO-1).
- Gate: Q3.
- Done when: handler tests for each event type walk the §8.4 state machine, including replays of the same event.

### 22. `localization-complete`

- Remaining languages of DE/FR/IT (and EN if Q10 says so), the guest's automatic language from the browser plus a manual switch (GR-7), Stripe Checkout/Portal locale passed from the account.
- Gate: Q10.
- Done when: every catalogue has every key (a test or lint check) and the guest page renders in each language.

### 23. `public-site`

- Landing page in all languages: what it does, how tips work, pricing, signup (PS-1). Start from `templates/design/index.html.twig`.
- Legal: imprint, privacy policy naming host and country, DJ terms, a short guest notice (PS-2). FAQ on getting paid and cost (PS-3).
- Gate: Q13, Q14. Legal texts come from the owner; the cycle builds the pages.

### 24. `account-deletion`

- DJ deletes their account: personal data removed or anonymised, Stripe subscription cancelled and customer detached, billing records kept (AC-6). Connect detach is added in #26.
- Done when: integration tests show what is deleted, what is anonymised and what is kept.

### 25. `performance-baseline`

- Load test against the design load (§9: ~200 queue polls/s plus writes) on a prod-like stack. Check the p95 < 200 ms target for the guest page and POST, add indexes or caching where the numbers say so.
- Done when: a short report with numbers sits in `docs/`, and the fixes are merged.

## R4 Tips (roadmap Phase 4)

Gate for the whole release: Q11 (Stripe accepts DJ tips on Connect) and Q12 (AMLA/GwG check) answered yes. If either is no, stop and replan R4 using the fallbacks in `prd.md` §11.

### 26. `connect-onboarding`

- "Get paid: enter your bank details" → Stripe-hosted Connect onboarding in DE/FR/IT, never labelled "Connect TWINT", with an explanation of the ID step (TI-1). Start from `templates/design/get_paid.html.twig`.
- `accounts.stripe_account_id`, `charges_enabled`, `payouts_enabled` synced from `account.updated`; UI states "Finish setup" / "Ready" / "Action needed" (TI-2).
- Connect pricing set to "Stripe handles pricing" (TI-9).
- Add the connected-account detach to #24's deletion flow.

### 27. `tip-checkout`

- `tips` table (`amount_rappen >= 500`, CHF, Stripe session/intent IDs, status) and `events.tips_enabled`.
- Tips offered only on a paid plan with `charges_enabled` and tips on for the event (TI-3).
- "Tip CHF X with TWINT" → Checkout Session as a direct charge on the DJ's connected account (TI-4). Presets 5/10/20 plus custom ≥ CHF 5 (TI-5). Return page "Request sent + tipped ✓" or untipped on abandon/failure (TI-7).
- Money goes straight to the DJ; `application_fee_amount` only if Q7 says so (TI-8).
- Gate: Q7.

### 28. `tip-confirmation`

- `checkout.session.completed` / `payment_intent.succeeded` from connected accounts mark the tip paid and the request tipped, never on redirect alone (TI-6).
- DJ queue shows tipped requests and amounts (DQ-1, DQ-2).
- Skip the Line rules (TI-10) and refund policy (TI-11) as decided.
- Gate: Q6, Q8.
- Done when: a tip shows in the queue one refresh after the webhook, and a redirect without a webhook doesn't mark anything as paid.

### 29. `event-summary`

- After an event: request count, guests, top songs, played vs skipped, tips total (DQ-9, C).

## Backlog (not scheduled)

R3+ "could" items without a cycle yet: EV-7 event PIN, AC-7 CSV export. Later items (AC-8 Google sign-in for DJs, TI-12 cards/Apple Pay/Google Pay tips, NO-3 Mercure push, and everything in `prd.md` §13) wait for DJ feedback after R4. Give each its own slug when it's picked up.

## Traceability: PRD ID → cycle

| IDs | Cycle |
|---|---|
| GR-1–GR-6 | #3 `guest-request-page` |
| GR-7 | #22 `localization-complete` |
| GR-8–GR-10 | #17 `guest-engagement-extras` |
| AS-1 | #3 `guest-request-page` |
| AS-2, AS-3, AS-5, AS-7, AS-8, AS-9 | #2 `submit-song-request` (AS-8 constraints also #1) |
| AS-4 | #13 `event-management` |
| AS-6 | #2 (silent drop), #6 (block action) |
| DQ-1–DQ-7 | #6 `dj-queue` (tip display in #28) |
| DQ-8 | #17 `guest-engagement-extras` |
| DQ-9 | #29 `event-summary` |
| EV-1 | #5 (minimal), #13 (full) |
| EV-2, EV-3 | #5 `event-lifecycle-qr` |
| EV-4 | #14 `permanent-qr` |
| EV-5, EV-6, EV-8 | #13 `event-management` |
| EV-7 | Backlog |
| AC-1 | #10 `dj-signup-verify` |
| AC-2 | #4 `dj-login` |
| AC-3, AC-4 | #12 `dj-account-settings` |
| AC-5 | #11 `tenant-isolation` |
| AC-6 | #24 `account-deletion` (+ #26) |
| AC-7, AC-8 | Backlog / Later |
| BI-1, BI-6, BI-8, BI-9, BI-10 | #18 `plans-and-limits` |
| BI-2, BI-3, BI-4 | #20 `stripe-subscribe` |
| BI-5 | #19 (endpoint), #21 (handlers) |
| BI-7 | #21 `subscription-lifecycle` |
| TI-1, TI-2, TI-9 | #26 `connect-onboarding` |
| TI-3, TI-4, TI-5, TI-7, TI-8 | #27 `tip-checkout` |
| TI-6, TI-10, TI-11 | #28 `tip-confirmation` |
| TI-12 | Later |
| NO-1 | #9, #10, #21 |
| NO-2 | #21 `subscription-lifecycle` |
| NO-3 | Later |
| PS-1–PS-3 | #23 `public-site` |
| OP-1 | #16 `operator-console` |
| OP-2 | #7 `prod-deploy` |
| OP-3 | #8 `prod-backups-monitoring` |
| NFR security | #4, #7, #11, #19 |
| NFR availability | #7, #8, #19 |
| NFR privacy | #15 `guest-data-retention`, #23 (policy) |
| NFR localization | #9, #22 |
| NFR performance | #25 `performance-baseline` |
| NFR accessibility | #3, #6 |
