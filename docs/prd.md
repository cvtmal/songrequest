# Song Request: Product Requirements Document

_Status: draft · Last updated: 2026-10-01 · Owner: Damian Ermanni_

This PRD describes what the whole product does and why. It is the reference for scope and behaviour. The build order lives in `roadmap.md`. The reasoning behind stack and payment choices lives in `songrequest-saas-stack-and-payments-findings.md` and `sb-skeleton-reuse-analysis.md`. The original guest and anti-spam ideas are in `brainstorming.md`.

Items marked **Open** are not decided yet. They are collected in [§12 Open questions](#12-open-questions).

## Contents

1. [Summary](#1-summary)
2. [Problem](#2-problem)
3. [Goals, non-goals and success metrics](#3-goals-non-goals-and-success-metrics)
4. [Users](#4-users)
5. [Market and positioning](#5-market-and-positioning)
6. [Scope and releases](#6-scope-and-releases)
7. [Functional requirements](#7-functional-requirements)
8. [Key flows](#8-key-flows)
9. [Non-functional requirements](#9-non-functional-requirements)
10. [Data model](#10-data-model)
11. [Dependencies and risks](#11-dependencies-and-risks)
12. [Open questions](#12-open-questions)
13. [Later and out of scope](#13-later-and-out-of-scope)
14. [Glossary](#14-glossary)

## 1. Summary

Song Request lets guests at a live event request songs from the DJ and tip them with TWINT. Guests scan a QR code, type a song, and send it. No app, no account. The DJ sees one ranked queue on their phone or laptop, marks songs as played or skipped, and mutes anyone who misbehaves.

The product is a subscription SaaS sold to DJs. Switzerland is the first market: prices in CHF, the interface in German, French and Italian, and TWINT for both the DJ's subscription and the guest's tips. DJs who aren't technical must be able to finish every flow alone.

## 2. Problem

- **DJs** get requests shouted across the booth, scribbled on napkins, or shown on a phone screen mid-mix. They lose track of them, see the same song asked for ten times, and can't tell what the room actually wants.
- **Guests** have to fight their way to the booth and interrupt the DJ. They never know whether the request was heard.
- **Tipping** at Swiss events is mostly cash, which fewer people carry. TWINT is how Swiss people pay each other, but a DJ can't take TWINT tips that are tied to a request without a business setup and a payment integration.
- **Existing tools** (mainly NoSongRequests) are US-focused: no TWINT, no CHF, no Swiss languages, and a long list of features most DJs don't need.

## 3. Goals, non-goals and success metrics

### Goals

1. A guest can send a request in under 30 seconds from scanning the QR code, on any phone, without installing anything.
2. A DJ sees one deduplicated, ranked queue and acts on a request with one tap.
3. One guest can't flood the queue.
4. A DJ can sign up, subscribe with TWINT, and start taking TWINT tips without contacting support.
5. Tip money goes from the guest straight to the DJ. The platform never holds it.
6. The platform runs on one small VPS for a long time, cheaply.

### Non-goals

- Becoming a full DJ toolkit (CRM, merch, ticketing, photo walls, tracking pixels).
- Native iOS or Android apps. The product is a mobile-first web app.
- Holding, pooling or forwarding money on behalf of DJs.
- Accounts or logins for guests.
- Markets outside Switzerland at launch (the product must not block them later, though).

### Success metrics (proposed targets, to be confirmed)

| Metric | Target |
|---|---|
| QR scan → request sent (guests who start a request) | ≥ 70% complete it |
| Median time from page load to "Request sent!" | < 30 s |
| Signup → first event opened | ≥ 60% of verified DJs within 7 days |
| Free → paid conversion | ≥ 10% of active DJs within 60 days |
| Stripe Connect onboarding started → `charges_enabled` | ≥ 80% |
| Support contacts per active DJ per month | < 0.2 |
| Monthly paid churn | < 5% |
| Uptime of `/health` (Fri–Sun, 18:00–04:00 CET) | ≥ 99.5% |

## 4. Users

| Persona | Who | Wants | Constraints |
|---|---|---|---|
| **Guest** | Anyone at the event: wedding guest, club-goer, bar patron | Hear their song; feel heard; optionally thank the DJ | Dark, loud room; weak mobile data; possibly drunk; won't install an app or create an account |
| **DJ** (customer, tenant) | Freelance or resident DJ in Switzerland: weddings, bars, clubs, corporate events. Often a sole trader or doing it on the side | Fewer interruptions, a clear picture of the crowd, extra income from tips | Not technical; checks the queue between tracks; little patience for setup or paperwork |
| **Operator** (us) | The person running the platform | Reliable gigs on weekend nights, low support load, predictable costs | One person; one VPS; uses Stripe's dashboard and the database for admin work |

Each DJ is one **account** (tenant). At launch an account has exactly one user. Multi-user accounts (agencies, a DJ plus assistant) are a later option; the data model scopes everything by `account_id` rather than by user so this stays possible.

## 5. Market and positioning

**Positioning:** "Song requests and TWINT tips for Swiss DJs." CHF, DE/FR/IT, local support, deliberately simple.

**Main competitor: NoSongRequests.com** (snapshot 2026-09-29)

| | NoSongRequests | Song Request |
|---|---|---|
| Market | US-centric, 160 countries | Switzerland first |
| Tips | Stripe (card, Apple/Google Pay, Cash App) or unverified Venmo/Zelle handles | TWINT through Stripe Connect, verified and tied to a request |
| Pricing | Free (100 requests/month, no tips), $10/mo, $20/mo | CHF, free tier + one paid plan (**Open**: price) |
| Languages | English | DE, FR, IT |
| Feature set | Very broad (Serato, CRM, merch, tickets, photo wall…) | Narrow core done well |

Features worth matching at launch: permanent QR code, Stop Requests button, max requests per event, guest blocking, minimum tip. Features we skip are listed in [§13](#13-later-and-out-of-scope).

## 6. Scope and releases

Releases follow the roadmap phases. Each release must be usable on its own.

| Release | Roadmap phase | Contents | Who can use it |
|---|---|---|---|
| **R0 Foundation** | Phase 0 | Empty app, tooling, CI, `/health` | Nobody (internal) |
| **R1 Gig-ready** | Phase 1 (+ Phase 5 early) | Guest request flow, anti-spam, DJ queue, one seeded DJ | The owner at real gigs |
| **R2 Multi-DJ** | Phase 2 | Signup, login, tenancy, event management, permanent QR | Invited beta DJs, free |
| **R3 Paid** | Phase 3 | Plans, Stripe Billing with TWINT and cards, plan limits | Public launch |
| **R4 Tips** | Phase 4 | Stripe Connect onboarding, TWINT tips tied to requests | All paying DJs |
| **R5 Production hardening** | Phase 5 | VPS, TLS, backups with tested restore, monitoring | Required before R2 goes to other DJs |

## 7. Functional requirements

Priority: **M** = must have for the release named, **S** = should have, **C** = could have.

### 7.1 Guest request page (module `Requests`)

| ID | Requirement | Release | Pri |
|---|---|---|---|
| GR-1 | Scanning an event's QR code opens `/r/{slug}`, which shows the event and DJ name and the request form. | R1 | M |
| GR-2 | The form has **song title** (required), **artist** (optional) and **nickname** (optional). | R1 | M |
| GR-3 | The page and form work fully without JavaScript. Submitting is a normal form post. Validation errors re-render the form with HTTP 422 and keep the typed values. | R1 | M |
| GR-4 | On success the guest sees "Request sent!" and can send another (subject to limits). | R1 | M |
| GR-5 | If the event is closed or the DJ pressed Stop Requests, the page says so and shows no form. Posting anyway is rejected. | R1 | M |
| GR-6 | The page is usable on a small phone in a dark room: large tap targets, high contrast, dark theme, no tiny text. | R1 | M |
| GR-7 | The page is shown in the guest's browser language when it is DE, FR or IT, with a manual switch. | R3 | M |
| GR-8 | The guest can see the songs they requested tonight and whether each was played. | R2 | S |
| GR-9 | The DJ can choose to show guests the public queue (titles and vote counts, no nicknames). Off by default. | R2 | C |
| GR-10 | The guest can "+1" a song already in the public queue instead of typing it. | R2 | C |

### 7.2 Guest identity and anti-spam (module `Requests`)

Guests never log in. The server identifies a browser by an anonymous cookie. Bypassing it with a private window or by clearing cookies is an **accepted risk**: the attacker is a tipsy guest, not a hacker.

| ID | Requirement | Release | Pri |
|---|---|---|---|
| AS-1 | On the first visit the server issues `guest_token` (UUID): `HttpOnly; Secure; SameSite=Lax; Max-Age=86400`. Guests can't choose their own token. | R1 | M |
| AS-2 | **Cooldown:** a guest must wait between two requests to the same event (default 5 minutes). The error says how long is left. | R1 | M |
| AS-3 | **Per-guest cap:** a guest can send at most N requests per event (default 3). | R1 | M |
| AS-4 | **Per-event cap:** the DJ can set a maximum number of requests for an event. When reached, the page behaves like Stop Requests. | R2 | M |
| AS-5 | **Duplicates merge:** a request for a song already in the event's open queue adds one vote to it instead of a new row. Matching ignores case, surrounding whitespace and repeated spaces in title and artist. A duplicate still counts against the guest's cooldown and cap. | R1 | M |
| AS-6 | **Silent mute:** the DJ can block a guest from the queue in one tap. Later requests from that token are dropped without error; the guest still sees "Request sent!". A block applies to the whole DJ account, not just the event. | R1 | M |
| AS-7 | **IP backstop:** at most 30 requests per IP per hour per event, to catch scripted abuse. Set high because venue Wi-Fi and mobile carriers share IPs. | R1 | S |
| AS-8 | Cooldown, caps and duplicate merging are enforced on the server inside one database transaction, and hold under concurrent submits (database constraints, not only application checks). | R1 | M |
| AS-9 | Device IDs, browser fingerprinting and SMS verification are **not** used. | — | — |

Defaults for AS-2/AS-3 are starting values. Whether DJs can change them per event is **Open**.

### 7.3 DJ queue (module `Requests`)

| ID | Requirement | Release | Pri |
|---|---|---|---|
| DQ-1 | The DJ sees the open requests for an event, sorted by votes (desc), then by time of first request (asc). Tipped requests are marked; whether they also sort first is **Open** (Skip the Line). | R1 | M |
| DQ-2 | Each row shows title, artist, vote count, nickname(s), age ("4 min ago") and tip amount if any. | R1 | M |
| DQ-3 | One tap marks a request **played** or **skipped**. It leaves the open queue and appears in a "done" list, where it can be undone. | R1 | M |
| DQ-4 | One tap **blocks the guest** behind a request (see AS-6), with an undo in the account's block list. | R1 | M |
| DQ-5 | A **Stop Requests / Resume** toggle for the event. | R1 | M |
| DQ-6 | The queue refreshes on its own at least every 5 seconds without a full page reload (Turbo Frame polling). | R1 | M |
| DQ-7 | The queue is usable one-handed on a phone and readable at a glance on a laptop next to the decks. | R1 | M |
| DQ-8 | A new request shows a visual cue. Sound is opt-in. | R2 | S |
| DQ-9 | After an event, the DJ sees a summary: number of requests, guests, top songs, played vs skipped, tips total. | R4 | C |

### 7.4 Events (module `Events`)

| ID | Requirement | Release | Pri |
|---|---|---|---|
| EV-1 | A DJ creates an event with a name and optional date and venue. The system generates a unique, hard-to-guess slug. | R2 | M |
| EV-2 | A DJ opens and closes an event. Only open events accept requests. Closing is permanent for that event's link; old QR photos stop working. | R1 | M |
| EV-3 | Each event has a QR code that the DJ can show full screen, download as PNG/SVG, and print. | R1 | M |
| EV-4 | **Permanent QR:** each DJ has one permanent link and QR code (e.g. for a booth sticker) that always leads to their currently open event, or to a "not taking requests right now" page. | R2 | M |
| EV-5 | A DJ sees a list of their events (upcoming/open, past) with request counts. | R2 | M |
| EV-6 | Limits per event: max requests (AS-4), and **Open**: cooldown and per-guest cap overrides. | R2 | M |
| EV-7 | Optional password or PIN on the event page for private events (weddings). | R3+ | C |
| EV-8 | Events auto-close after a DJ-set time or 12 hours after opening, so forgotten events don't stay open. | R2 | S |

### 7.5 DJ accounts and access (module `Accounts`)

| ID | Requirement | Release | Pri |
|---|---|---|---|
| AC-1 | Signup with email and password, then email verification before the first event can be opened. | R2 | M |
| AC-2 | Login with email and password, "remember me", and login throttling. | R2 | M |
| AC-3 | Password reset by email. | R2 | M |
| AC-4 | Profile: DJ/stage name (shown to guests), contact email, language. | R2 | M |
| AC-5 | **Tenant isolation:** a DJ can only see and change their own events, requests, guests' blocks, subscription and tip data. Every tenant query filters by `account_id`; a voter guards every DJ action. Tests prove DJ A can't reach DJ B's data by guessing IDs or slugs. | R2 | M |
| AC-6 | Account deletion: the DJ can delete their account. Personal data is removed or anonymised; Stripe objects are cancelled/detached. Billing records kept as required by law. | R3 | M |
| AC-7 | Data export of the DJ's events and requests (CSV). | R3+ | C |
| AC-8 | Sign in with Google for DJs. | Later | C |

### 7.6 Plans and subscriptions (module `Billing`)

| ID | Requirement | Release | Pri |
|---|---|---|---|
| BI-1 | Two plans at launch: **Free** (request cap, no tips) and **Pro** (monthly, CHF). Yearly billing, prices and exact caps are **Open**. | R3 | M |
| BI-2 | "Subscribe" opens Stripe Checkout in subscription mode, localized, with **TWINT and cards**, CHF. | R3 | M |
| BI-3 | "Manage subscription" opens the Stripe Customer Portal (change payment method, cancel, invoices). | R3 | M |
| BI-4 | Exactly one Stripe customer per DJ account. Plan changes modify the existing subscription; they never start a second Checkout (TWINT allows one active mandate per merchant–customer pair). | R3 | M |
| BI-5 | A Stripe webhook endpoint verifies the signature, stores the event ID (idempotency), and processes the event asynchronously. Handles `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`. | R3 | M |
| BI-6 | A local `subscriptions` table is the source of truth for plan limits. **No Stripe API call happens during a guest request.** | R3 | M |
| BI-7 | Failed renewals (e.g. the DJ revoked TWINT in their app) use Stripe's dunning emails and hosted invoice page. The DJ keeps Pro for a grace period, then falls back to Free. Grace period length is **Open**. | R3 | M |
| BI-8 | Downgrading never deletes data. Features above the Free limits stop working; existing events and history stay visible. | R3 | M |
| BI-9 | Free trial of Pro or free tier only: **Open**. If a trial exists, it needs no payment method up front. | R3 | S |
| BI-10 | Plan limits are shown in the app where they bite ("12 of 100 requests this month"), with an upgrade link. | R3 | S |

### 7.7 Guest tips (module `Tips`)

| ID | Requirement | Release | Pri |
|---|---|---|---|
| TI-1 | **DJ onboarding:** a "Get paid: enter your bank details" button starts Stripe Connect onboarding (Stripe-hosted, DE/FR/IT). Never labelled "Connect TWINT". The app explains why Stripe asks for ID. | R4 | M |
| TI-2 | The app syncs `charges_enabled` and `payouts_enabled` from `account.updated` webhooks and shows the onboarding state ("Finish setup", "Ready", "Action needed"). | R4 | M |
| TI-3 | Tipping is available only when the DJ is on a paid plan, `charges_enabled` is true, and the DJ turned tips on for the event. | R4 | M |
| TI-4 | After (or while) sending a request, the guest can tap "Tip CHF X with TWINT". This creates a Stripe Checkout Session as a **direct charge on the DJ's connected account**, CHF, TWINT. The DJ's name appears in the TWINT app. | R4 | M |
| TI-5 | Minimum tip **CHF 5** (fees make smaller tips poor value). Preset amounts (e.g. 5 / 10 / 20) plus a custom amount ≥ the minimum. | R4 | M |
| TI-6 | The `checkout.session.completed` / `payment_intent.succeeded` webhook marks the tip as paid and the request as tipped. The DJ queue shows it within one refresh. A tip is shown to the DJ only after the webhook confirms it, never on redirect alone. | R4 | M |
| TI-7 | The guest returns to "Request sent + tipped ✓". If they abandon or the payment fails, the request stays as a normal untipped request. | R4 | M |
| TI-8 | Money flows guest → DJ's Stripe account → DJ's bank. The platform never holds it. An optional platform fee uses `application_fee_amount`; whether to charge one is **Open**. | R4 | M |
| TI-9 | Connect pricing model: **"Stripe handles pricing"**, so the platform pays no per-account Connect fees. | R4 | M |
| TI-10 | **Skip the Line:** a tip can move a request to the top of the queue and/or bypass the cooldown. **Open.** | R4 | S |
| TI-11 | Refund policy if a tipped song isn't played (no refunds vs. DJ refund button). **Open.** | R4 | S |
| TI-12 | Apple Pay, Google Pay and cards as tip methods next to TWINT. | Later | C |

### 7.8 Notifications and email

| ID | Requirement | Release | Pri |
|---|---|---|---|
| NO-1 | Transactional emails (verify email, password reset, welcome, subscription started/ended) are sent asynchronously through Messenger, in the DJ's language. | R2 | M |
| NO-2 | Billing emails (receipts, failed payment, card expiring) come from Stripe, not from us. | R3 | M |
| NO-3 | Live push to the DJ queue (Mercure/SSE) replaces polling only if polling proves too slow or too heavy. | Later | C |

### 7.9 Public site

| ID | Requirement | Release | Pri |
|---|---|---|---|
| PS-1 | Landing page in DE/FR/IT: what it does, how tips work, pricing, signup. | R3 | M |
| PS-2 | Legal pages: imprint, privacy policy (naming the hosting provider and country), terms of service for DJs, a short notice for guests. | R3 | M |
| PS-3 | FAQ focused on "How do I get paid?" and "What does it cost?". | R3 | S |

### 7.10 Operator tooling

There is no admin UI at launch. The operator uses the Stripe dashboard, `bin/console` commands and the database.

| ID | Requirement | Release | Pri |
|---|---|---|---|
| OP-1 | Console commands to look up an account by email, disable/enable an account, and resend verification. | R2 | S |
| OP-2 | Failed Messenger messages go to the `failed` transport and can be retried. | R1 | M |
| OP-3 | Errors are reported to Sentry. An external uptime monitor checks `/health`. | R5 | M |

## 8. Key flows

### 8.1 Guest sends a request

```
Scan QR ──► /r/{slug} ──► server sets guest_token cookie (first visit)
                │
                ├─ event closed / stopped ──► "Not taking requests right now"
                │
                └─ form: title, artist?, nickname? ──► POST
                        │
                        ├─ invalid input        ──► 422, form re-rendered with errors
                        ├─ cooldown / cap hit   ──► friendly message ("try again in 3 min")
                        ├─ guest muted          ──► "Request sent!" (silently dropped)
                        ├─ song already queued  ──► +1 vote ──► "Request sent!"
                        └─ new song             ──► new row ──► "Request sent!"
```

### 8.2 Guest tips (R4)

```
"Request sent!" ──► "Tip CHF 5 with TWINT" ──► Stripe Checkout (DJ's connected account)
        ──► TWINT app opens ──► guest confirms ──► back to "Request sent + tipped ✓"
        ──► webhook (async) ──► tip paid, request marked tipped ──► DJ queue shows it
```

### 8.3 DJ onboarding to first tip

```
Sign up ──► verify email ──► set stage name ──► create & open event ──► show QR
   ──► (later) Subscribe: Checkout, TWINT, ~30 s
   ──► (later) Get paid: Stripe Connect onboarding, ~5 min ──► charges_enabled
   ──► turn on tips for the event
```

### 8.4 Subscription lifecycle

```
Free ──Subscribe──► Pro (active) ──invoice.payment_failed──► past_due (grace)
                       ▲                                        │
                       └────────── invoice.paid ────────────────┤
                                                                ▼
                                            subscription.deleted ──► Free
```

## 9. Non-functional requirements

### Performance and capacity

- Guest page and request POST: p95 server time < 200 ms on the production VPS.
- Design load: 1,000 DJs with events open at the same time on a Saturday night, ~100 guests each, one request every few minutes per guest, and every DJ queue polling every 5 s. That is roughly 200 queue polls/s plus a low rate of writes. The ~$5 VPS must handle the first hundreds of concurrent events; the scaling path is a bigger VPS, then Postgres on its own server, then Redis for sessions and rate limits.
- Guest pages stay small (no JS framework, few assets) so they load on weak mobile data.

### Availability

- Weekend nights are when it matters. Deploys avoid Friday 16:00 – Sunday 06:00.
- Stripe webhooks are processed asynchronously and idempotently, so a short outage loses nothing: Stripe retries.
- Nightly `pg_dump` to off-server storage. A restore is tested before R2 and after any backup change.

### Security

- HTTPS only (Let's Encrypt). HSTS.
- DJ auth: Symfony session login, hashed passwords, login throttling, CSRF on every form.
- Guest token: `HttpOnly`, `Secure`, `SameSite=Lax`, generated by the server.
- Tenant isolation as in AC-5, covered by automated tests.
- Stripe webhook signatures verified; Stripe secret keys only in server env, never in the repo.
- No card or TWINT data touches our servers (Stripe-hosted Checkout and onboarding only).
- VPS: SSH keys only, firewall (22/80/443), automatic security updates.

### Privacy (Swiss nDSG, GDPR where it applies)

- Guests: we store a random token, the songs they asked for, an optional nickname, and for rate limiting an IP address for a short time. No device IDs, no fingerprinting, no tracking pixels, no third-party analytics on guest pages.
- DJs: account data, billing state (Stripe holds payment details), Connect state.
- Retention of guest data (tokens, nicknames, IPs) after an event: **Open**. Proposed: drop IPs after 24 h, anonymise guest tokens and nicknames 30 days after the event closes.
- Privacy policy names the hosting provider and country. A DPA/SCCs or Swiss-US DPF covers providers outside Switzerland/EU.

### Localization

- UI languages: German, French, Italian. Launch order and whether English is included are **Open**.
- Prices in CHF. Dates and numbers in Swiss formats.
- Stripe Checkout, Customer Portal and Connect onboarding are shown in the DJ's or guest's language.

### Accessibility and devices

- Guest page: works on iOS Safari and Android Chrome from the last ~4 years; works without JS; WCAG 2.1 AA contrast; tap targets ≥ 44 px.
- DJ queue: phone and laptop; readable in a dark booth.

### Engineering quality

Defined in `CLAUDE.md` and `.claude/rules/`: modular monolith with enforced module boundaries, command bus for writes, PHPStan level 7, three test suites, hand-written SQL migrations, CI gate on lint and tests.

## 10. Data model

Postgres 17. All IDs are UUIDv7. Times are `TIMESTAMPTZ`. Money is stored in rappen (integer). Every tenant table carries `account_id`.

| Table | Module | Purpose | Key fields |
|---|---|---|---|
| `accounts` | Accounts | One per DJ (tenant) | `stage_name`, `locale`, `stripe_customer_id`, `stripe_account_id`, `charges_enabled`, `payouts_enabled`, `permanent_slug` |
| `users` | Accounts | Login identity | `account_id`, `email` (unique), `password_hash`, `verified_at` |
| `events` | Events | A gig | `account_id`, `slug` (unique), `name`, `status` (open/stopped/closed), `max_requests`, `tips_enabled`, `opened_at`, `closed_at` |
| `guests` | Requests | Anonymous browser (not per tenant: one token spans DJs) | `id`, `token` (unique, the `guest_token` cookie value), `created_at` |
| `requests` | Requests | A song in an event's queue | `account_id`, `event_id`, `title`, `artist`, `title_normalized` / `artist_normalized` (generated columns; blank artist = no artist), `votes` (counter, ≥ 1), `status` (new/played/skipped), `tipped`, `created_at`; unique on (event, normalised title, normalised artist) with `NULLS NOT DISTINCT`, only while `status = 'new'` (partial index), so a played song can be requested again |
| `request_votes` | Requests | Who asked for what (for per-guest limits, cooldown and "my requests") | `account_id`, `event_id`, `request_id`, `guest_id`, `nickname`, `created_at`; unique on (request, guest): one vote per guest per song |
| `account_guest_blocks` | Requests | Muted guests per DJ | `account_id`, `guest_id`, `created_at`; unique on (account, guest) |
| `subscriptions` | Billing | Local mirror of Stripe Billing | `account_id`, `stripe_subscription_id`, `plan`, `status`, `current_period_end` |
| `stripe_events` | Billing | Webhook idempotency | `stripe_event_id` (unique), `type`, `received_at`, `processed_at` |
| `tips` | Tips | One tip payment | `account_id`, `request_id`, `amount_rappen` (≥ 500), `currency`, `stripe_checkout_session_id`, `stripe_payment_intent_id`, `status` |

`request_votes` is new compared with the roadmap's migration list: per-guest caps and "my requests" need to know which guest asked for which song, which a single `votes` counter can't tell. Decided in `core-schema` research (2026-10-01):

- `request_votes` replaces `requests.guest_token`. Every accepted request, new or merged, writes one vote row. `requests.votes` stays as a counter for sorting and the duplicate merge.
- Guests are referenced by `guests.id`, not by the cookie token, so anonymising a token touches one row and the DJ's block action never exposes the cookie value.
- The nickname is stored per vote (what the guest typed with that request), not per guest.
- Every table also has `id` (UUIDv7), `created_at` and `updated_at`. Columns are added by the cycle that needs them, so the R1 migration leaves out `locale`, `stripe_*`, `charges_enabled`, `payouts_enabled`, `permanent_slug`, `verified_at`, `max_requests`, `tips_enabled` and `tipped`.

## 11. Dependencies and risks

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Stripe rejects "tips for DJs" as a Connect business type | No in-app TWINT tips (R4 blocked) | Medium | Ask Stripe support before building R4. Fallback: each DJ contracts a Swiss PSP (Payrexx, Wallee, Datatrans), or show the DJ's TWINT QR sticker at the booth only. |
| Swiss AMLA/GwG applies to the platform | Licensing cost or redesign | Low with direct charges | Short fintech-lawyer check before R4. Keep money flow direct to the DJ. |
| TWINT mandate collision (one per merchant–customer) | Failed upgrades/resubscribes | Medium | One Stripe customer per account; modify subscriptions, never re-checkout (BI-4). |
| DJs revoke TWINT mandates | Involuntary churn | Medium | Dunning emails, grace period, easy "update payment method" in the portal. |
| Connect onboarding drop-off at the ID step | Fewer DJs with tips | Medium | Explain why before sending them to Stripe; "Finish setup" reminders. |
| Single VPS fails on a Saturday night | Gigs disrupted | Low | Uptime monitor, tested restores, documented rebuild, provider snapshots. |
| Anti-spam bypass (private windows) | Some duplicate spam | High, low impact | Accepted. Duplicate merging and DJ mute limit the damage. |
| Employer IP: recreating `sb-skeleton` conventions | Legal | Low | Conventions rewritten from scratch; check the employment contract. |
| Competitor adds TWINT/CHF | Weaker positioning | Low–medium | Win on Swiss languages, simplicity, local support. |

## 12. Open questions

| # | Question | Needed by |
|---|---|---|
| Q1 | Pro price in CHF; yearly option; exact Free tier caps (requests/month, events) | R3 |
| Q2 | Free tier only, or also a Pro trial (length)? | R3 |
| Q3 | Grace period after a failed renewal | R3 |
| Q4 | Can DJs change cooldown and per-guest cap per event, or only max requests? | R2 |
| Q5 | Permanent QR when a DJ has two events open at once: block it, or pick the most recent? | R2 |
| Q6 | Skip the Line: do tips reorder the queue, bypass the cooldown, both, or neither? | R4 |
| Q7 | Platform fee on tips (`application_fee_amount`), or subscription-only revenue? | R4 |
| Q8 | Refund policy for tipped songs that aren't played | R4 |
| Q9 | Retention period for guest tokens, nicknames and IPs | R2 |
| Q10 | Launch language order; English yes/no | R3 |
| Q11 | Stripe confirmation that DJ tips are an accepted Connect business type | Before R4 |
| Q12 | AMLA/GwG legal check | Before R4 |
| Q13 | VPS provider and off-server backup target | R5 |
| Q14 | Product name and domain | R3 |

## 13. Later and out of scope

**Later (after R4, driven by DJ feedback):**

- Live queue push via a standalone Mercure hub
- Song search/autocomplete against a music catalogue
- Serato/VirtualDJ integration, or the streaming-playlist workaround for rekordbox/Traktor
- Optional Google sign-in for guests who want higher limits
- Family-friendly / explicit-content filter
- Big-screen view of the queue for the venue
- Multi-user accounts (agencies, assistants)
- Wedding mode (pre-event request collection from invited guests)
- Apple Pay, Google Pay and cards for tips
- Markets outside Switzerland (EUR, other local payment methods)

**Out of scope:** native mobile apps, merch, ticketing, fan CRM and email marketing, tracking pixels, photo walls, unverified tips (showing a DJ's payment handle without confirmation).

## 14. Glossary

| Term | Meaning |
|---|---|
| **Account** | A DJ's tenant. All their data belongs to it. |
| **Event** | One gig. Has its own link, QR code and queue. |
| **Permanent QR** | One QR code per DJ that always points to their currently open event. |
| **Guest token** | Anonymous UUID in a cookie that identifies a guest's browser for one night. |
| **Vote** | One guest asking for a song. Duplicates add votes instead of rows. |
| **Mute / block** | DJ hides a guest's future requests without telling them. |
| **Stop Requests** | Pauses new requests for an event without closing it. |
| **Direct charge** | Stripe Connect payment made on the DJ's own Stripe account; the DJ is the seller. |
| **Mandate** | TWINT's stored permission for recurring charges. One active per merchant–customer pair. |
| **Rappen** | 1/100 CHF. Money is stored in rappen as integers. |
