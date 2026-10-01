# Song Request SaaS: Stack, Payments & Competitor Findings

> Exported from Claude Code session — 2026-09-29

Builds on `~/code/songrequest/brainstorming.md` (guest cookie token, anti-spam, SQLite data model).

## Product context

- Guests scan a QR code, send song requests, and **tip the DJ with TWINT**.
- Sold to DJs as a **subscription SaaS**. Switzerland is the first market (CHF, DE/FR/IT).
- Starting small, but the design has to scale. The hard parts are billing, DJ accounts, and keeping each DJ's data separate. Traffic is not the problem: even 1,000 DJs at gigs on a Saturday means only a few thousand guests, each sending a request every few minutes.
- The DJ customers are not technical. Every flow must be something they can finish without help.

## Decision 1: Stack = Symfony modular monolith

### Why not the work `sb-skeleton` as-is

`sb-skeleton` is a template for JSON API microservices that other services call, inside a big-team monorepo. The mismatches:

| Skeleton piece | Problem for this app |
|---|---|
| Keycloak JWT auth (M2M, needs `group_app`) | DJs need human signup/login/reset. Guests need an anonymous cookie. Running Keycloak alone is heavy. |
| Stateless, API-only, no UI | We need an HTML guest form (no JS) and a DJ dashboard. The OpenAPI listener returns JSON 400s, which is wrong for form posts. |
| RabbitMQ event bus | It exists for inter-service events, and there is only one app. Messenger's Doctrine transport covers background jobs. |
| OpenTelemetry + PECL extensions, K8s deploy image | Too much ops for one person. Use Sentry and a single VPS with Docker Compose instead. |
| Monorepo coupling (`libs/coding-standard`, `SevenEducation\` namespace, `*.sdux.de`, coverage gists) | Breaks outside the monorepo. |
| Filter/pagination/OpenAPI-split machinery | Built for public list APIs, which we don't have. |

⚠️ **IP risk:** the skeleton is employer code (`7education`). Recreate the conventions from scratch; don't copy files. Check the employment contract.

### Keep from the skeleton

- Symfony 7.4 / PHP 8.5, Doctrine, **PostgreSQL** (replaces the brainstorm's SQLite: concurrent writes, proper constraints). Self-hosted on the VPS, so off-server backups are our job.
- Command → Handler for real business rules. "Submit request" (cooldown, cap, duplicate merge, silent mute) is a perfect fit. Use Symfony `RateLimiter`.
- Tenant scoping (`organization_id` pattern → `account_id`; one DJ = one tenant).
- Quality tooling: `strict_types`, PHPStan level 7, PHP-CS-Fixer (`@Symfony`), **phpat architecture tests**, Unit/Integration/Application test suites, Foundry, Makefile, Docker Compose, CLAUDE.md + rules.
- UUIDv7 IDs. Hand-written SQL migrations are optional but a good habit.

### Target architecture

- **Web:** Twig + Symfony UX Turbo. The guest form works without JS. The DJ dashboard polls every 5s to start. Live Turbo Streams over Mercure (SSE) later via a standalone Mercure hub container (no FrankenPHP).
- **Auth:** security-bundle session `form_login` + `remember_me` for DJs. Guests aren't authenticated users: a request/response listener issues the anonymous cookie token and an argument resolver hands it to controllers (no separate firewall).
- **Async:** Messenger with the Doctrine transport (Stripe webhooks, emails).
- **Modules** (boundaries enforced with phpat): `Requests`, `Events`, `Accounts`, `Billing`, `Tips`.
- **Runtime:** php-fpm + nginx. FrankenPHP was rejected.
- **Hosting:** one ~$5 VPS running the same Docker Compose stack as local: nginx, php-fpm, a Messenger worker, and Postgres. PaaS options (Fly.io, Railway, Render), Kubernetes and Swiss shared hosting (cyon) were rejected. Any provider and location is fine; it doesn't have to be Swiss or EU. Deploy with a `make deploy` script over SSH (Kamal later if needed). To scale: first a bigger VPS, then move Postgres to its own server and add Redis for sessions and rate limits.
- Details and file-by-file skeleton reuse: `sb-skeleton-reuse-analysis.md`.
- **Alternative considered:** Laravel (Cashier, Reverb, Laravel Cloud) gets to paid features faster. We chose Symfony for familiarity; Stripe Checkout closes most of the billing gap.

## Decision 2: DJ subscriptions = Stripe Billing with TWINT

- Stripe has [supported TWINT for recurring payments since May 2026](https://docs.stripe.com/changelog/dahlia/2026-05-27/recurring-payments-twint): Billing subscriptions, Checkout in `subscription` mode, and charges without the customer present.
- Price in **CHF**. Offer cards as well as TWINT. Dropped Paddle and Lemon Squeezy (the merchant-of-record suggestion assumed EU customers). Swiss VAT registration only becomes mandatory above CHF 100k turnover.
- **TWINT limitation:** one active mandate per merchant-customer pair; a second one fails with `payment_intent_mandate_revoked_mandate_collision`. So map 1 DJ account to 1 Stripe customer, and handle upgrades by changing the existing subscription, never with a new Checkout.
- DJs can revoke the registration in the TWINT app, so a failed renewal is a normal event, not a rare error. Use Stripe's automatic dunning emails and hosted invoice pages.
- **DJ experience:** click "Subscribe", open Stripe Checkout (localized), pick TWINT, confirm in the app (which also approves future charges), done. About 30 seconds. After that, nothing to do.
- "Manage subscription" button opens the Stripe Customer Portal.
- Consider a **free trial or free tier with no payment method up front**. Stripe supports this.

### Implementation

1. `stripe/stripe-php`. Checkout Session in `subscription` mode with TWINT and cards.
2. One webhook controller: verify the signature, dispatch to a Messenger handler, store event IDs so a repeated delivery isn't processed twice.
3. Handle `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated` and `customer.subscription.deleted`, and keep a local `subscriptions` table in sync.
4. Check plan limits against the local table only. Never call Stripe during a guest request.

## Decision 3: Guest tips = Stripe Connect, direct charges, TWINT

### Hard requirement: guests pay with TWINT, verified by the app, tied to a request

- **The DJ never "connects their TWINT".** The DJ connects a **bank account (IBAN)** through Stripe onboarding. TWINT is only the guest's payment method. A DJ doesn't even need TWINT.
- Stripe supports TWINT with Connect (direct, destination, and separate charges and transfers), CHF only. The connected account's name is what guests see in the TWINT app.

### Money flow: guests pay the DJ, not the platform

```
Guest ──TWINT──► DJ's Stripe account ──payout──► DJ's bank
                        │
                        └─(optional) application fee ──► platform
```

- **Direct charges:** the DJ is the seller. The tip is the DJ's income and tax, and disputes go to the DJ.
- The platform never holds the money, which keeps it clear of the Swiss AMLA/GwG rules.
- Optional platform cut via `application_fee_amount`. Or take no cut and earn from subscriptions only (NoSongRequests' model).
- Rejected: destination charges or collecting and forwarding the money yourself. The platform would become the seller (VAT, disputes, platform name shown in TWINT, AMLA exposure).

### DJ onboarding (once)

1. "Get paid: enter your bank details" button (don't call it "Connect TWINT").
2. Stripe-hosted form in DE/FR/IT: name, date of birth, address, phone, IBAN, maybe an ID photo. About 5 minutes. Individuals are accepted; no registered business needed.
3. Main friction: the ID photo. Explain in the app why Stripe needs it.

### Guest tip flow

1. Pick a song, tap "Tip CHF 5 with TWINT", get redirected to Stripe Checkout (no JS needed on our side).
2. The TWINT app opens (guests are already on their phones, so this is the smoothest flow), confirm, return to "Request sent + tipped ✓".
3. The webhook marks the request as paid, and the DJ sees it live.

Checkout Session on the connected account, CHF, `payment_method_types: ['twint']` (cards and Apple Pay optional later).

### Fees (Stripe Switzerland)

- TWINT: **1.9% + CHF 0.30** per payment (+2% if currency conversion applies).
- Connect: choose the **"Stripe handles pricing"** model, so the platform pays no Connect fees and Stripe bills DJs directly. The other model costs CHF 2 per monthly active account plus 0.25% + CHF 0.55 per payout plus 0.25% routing.

| Tip | Fee | DJ keeps |
|---|---|---|
| CHF 2 | CHF 0.34 (17%) | CHF 1.66 |
| CHF 5 | CHF 0.40 (8%) | CHF 4.60 |
| CHF 10 | CHF 0.49 (5%) | CHF 9.51 |

→ **Minimum tip: CHF 5.**

### TWINT routes rejected

| Route | Why not |
|---|---|
| Guest pays the DJ person-to-person | Always needs the recipient's phone number; there's no P2P API; TWINT's terms forbid private accounts for business income |
| TWINT business QR sticker (1.3%, no minimum, free, needs a sole proprietorship + Swiss mobile number) | In-person only: may not be shown on websites, WhatsApp or email. The app can't confirm payments or tie them to requests. Fine as a physical "just tip" sign at the booth, nothing more. |
| Each DJ contracts a Swiss PSP (Payrexx, Wallee, Datatrans) | Too much paperwork for non-technical DJs, plus one integration per provider. Keep as a fallback only if Stripe rejects the business type. |

## Competitor: NoSongRequests.com

(`nosongrequest.com` redirects to `nosongrequests.com`. The site returns 403 to automated fetches, so read it in a browser.)

- **Scale (their claims):** 21k performers, 4M fans, 200k events, 160 countries. US-focused.
- **Pricing:** Starter free (no card, 100 requests/month, **no tips**), Pro $10/mo or $100/yr, Elite $20/mo or $200/yr (25 event pages and profiles). Extra event pages $5/mo per pack of 5. Tipping is the main reason to upgrade.
- **Tips:**
  - *Verified:* the DJ's own Stripe account (Connect; they say DJs can reuse it for merch and gigs, which suggests full Standard accounts). Card, Apple Pay, Google Pay, Cash App. No platform cut advertised. Only verified tips unlock minimum tip, required tip, and **Skip the Line** (paid priority).
  - *Unverified:* shows the DJ's Venmo, Zelle, PayPal or M-Pesa details, with no confirmation that money was sent.
- **Anti-spam:** per-guest limits per browser session, **optional Google sign-in** for stronger limits, max requests per event, blocking users, password-protected page, family-friendly filter, Stop Requests button, hide or show the public queue, duplicate handling, likes.
- **Headline features:** permanent QR code; Serato (own crate) and VirtualDJ integrations; Spotify/TIDAL/Apple Music playlist workaround for rekordbox, Traktor, etc.; Crate Mode (only songs in the DJ's library); BPM/key/energy/lyrics; custom event pages; wedding, karaoke and line-dance modes; shoutouts, photo wall and big screen; fan CRM and email; tracking pixels; merch (Fourthwall); tickets (Eventbrite).
- **Weaknesses:** no TWINT, CHF or Swiss localization advertised; feature overload; iOS app rated 4.2 from 18 ratings, with reviews citing crashes and support that doesn't respond; unverified tips confuse DJs.

### Takeaways

1. **Positioning:** "Song requests and TWINT tips for Swiss DJs", in CHF and DE/FR/IT, with local support. Deliberately simple.
2. **Pricing benchmark:** about $10/mo. A free tier with a request cap and no tips is a proven funnel.
3. **Copy for MVP:** permanent QR code, Stop Requests, max requests per event, blocking, minimum tip, paid Skip the Line (TWINT).
4. **Skip for now:** merch, tickets, fan CRM, tracking pixels, photo wall.
5. **Later, where DJs will notice:** Serato/VirtualDJ integration or the streaming-playlist workaround. Optional Google sign-in for stricter limits.

## Data model changes (vs brainstorm)

- New `accounts` (DJs; tenant): `stripe_customer_id`, `stripe_account_id`, `charges_enabled`, `payouts_enabled` (synced via `account.updated`).
- New `subscriptions` (synced from Billing webhooks) and plan limits for handlers to enforce.
- `events.account_id` (tenant scoping).
- Blocking per account, not global: e.g. `account_guest_blocks (account_id, guest_token)`.
- New `tips`: `request_id`, amount, currency, `stripe_payment_intent_id`, status.
- Postgres types: `UUID`, `TIMESTAMPTZ`, `JSONB`.

## Open questions & to-dos

- [ ] Check the employment contract regarding reusing `sb-skeleton` conventions/code.
- [ ] Ask Stripe support to confirm "tips for DJs" is an accepted business type for Connect.
- [ ] Short fintech-lawyer check on Swiss AMLA/GwG for the direct-charges setup.
- [ ] Refund policy if a tipped song isn't played ("a tip is a tip" + DJ refund button?). Hold-then-capture with TWINT is unverified.
- [ ] Do tipped requests bypass the cooldown and/or jump the queue (Skip the Line)?
- [ ] Platform cut on tips (application fee %) or subscription-only revenue?
- [ ] Free tier vs free trial; subscription price in CHF.
- [ ] DJ notification: start with polling; Mercure hub vs push later (from the brainstorm).
- [x] ~~Hosting provider choice~~ → ~$5 VPS with Docker Compose, any provider and location. Provider still to pick.
- [ ] Off-server backup target for nightly `pg_dump`.

## Sources

- [Stripe changelog: TWINT for recurring payments](https://docs.stripe.com/changelog/dahlia/2026-05-27/recurring-payments-twint)
- [Stripe: payment method support for Connect (TWINT)](https://docs.stripe.com/payments/payment-methods/payment-method-connect-support)
- [Stripe CH: local payment method pricing](https://stripe.com/en-ch/pricing/local-payment-methods)
- [Stripe CH: Connect pricing](https://stripe.com/en-ch/connect/pricing)
- [TWINT: General Terms and Conditions](https://www.twint.ch/en/app-conditions-use/)
- [TWINT FAQ: sending and receiving money](https://www.twint.ch/en/faq/what-should-i-pay-attention-to-when-sending-and-receiving-money/)
- [TWINT: QR code sticker](https://www.twint.ch/en/business-customers/our-solutions/qr-code-sticker/)
- [TWINT FAQ: QR sticker requirements (in-person only)](https://www.twint.ch/en/faq/what-are-the-requirements-for-using-a-qr-code-sticker/)
- [TWINT FAQ: private individuals and QR stickers](https://www.twint.ch/en/faq/can-i-as-a-private-individual-request-a-qr-code-sticker/)
- [NoSongRequests: homepage & pricing](https://nosongrequests.com/), [features & pay types](https://nosongrequests.com/qrcodes.html), [FAQs](https://nosongrequests.com/faqs.html), [Verified Tips](https://nosongrequests.com/verifiedtips)
