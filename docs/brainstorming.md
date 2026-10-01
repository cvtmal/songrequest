# Song Request App: Brainstorming Notes

_Session date: 2026-09-29_

## Idea

A small web app for taking song requests while DJing:

- Guests scan a QR code and land on a request form.
- They fill in the request and send it.
- The DJ sees incoming requests in a simple view.
- Backend: basic web app + SQLite. Tech stack not decided yet.

## Problem: preventing request spam

How do we stop one guest from flooding the DJ with requests?

### Can we read a device ID?

No. Browsers deliberately hide hardware identifiers such as MAC addresses and IMEIs from websites. We don't need a real device ID anyway, because the "attacker" is a tipsy guest, not a hacker.

### Rejected approaches

| Approach | Why not |
|---|---|
| **IP address** | Everyone on the venue Wi-Fi shares one IP, and mobile carriers put thousands of phones behind shared IPs (CGNAT). Useful at most as a loose backstop, e.g. max 30 requests per IP per hour. |
| **Browser fingerprinting** | Unreliable (phones of the same model look nearly identical) and a privacy grey zone under GDPR. |
| **Phone/SMS verification** | Technically easy (e.g. Twilio Verify, about €0.05–0.10 per verification), but too much friction in a loud, dark club. It also brings SMS-pumping fraud risk and GDPR overhead. Overkill for this use case. |

## Decision: anonymous guest token in a cookie

Bypassing it with incognito or cleared cookies is an **accepted risk**.

### Cookie vs. localStorage: use a server-set cookie

- **No JavaScript needed.** The browser sends the cookie with every form submit automatically. localStorage would need JS to read the token and add it to each request.
- **`HttpOnly`,** so page scripts can't read or change it.
- **The server creates the token.** On the first visit with no cookie, the server generates a UUID and sets it, so guests can't invent their own.

Cookie settings:

```
guest_token=<uuid>; HttpOnly; Secure; SameSite=Lax; Max-Age=86400
```

One day is enough, since it only has to last the night.

Side effect: incognito guests still keep a cookie while that incognito session stays open. They only become "new" again after closing the incognito window.

## Anti-spam measures, built on the token

1. **Per-token limits, enforced on the server:** for example a 5-minute cooldown between requests and a maximum of 3 requests per night.
2. **Duplicate merging:** if the song is already in the queue, add a "+1" to it instead of creating a new row. This turns spam into a useful popularity signal.
3. **One-tap block (DJ side):** a "mute this guest" button flags the token. Later requests from that token are silently dropped, while the guest still sees "Request sent!".
4. **Per-event QR code:** put a code for each gig in the URL (e.g. `/r/gig-2026-10-03-xyz`) and close it when the night ends. Old QR photos stop working, and limits reset per event.
5. **Optional nickname field:** social pressure more than enforcement.

## Rough data model (SQLite)

```sql
CREATE TABLE events (
  id         INTEGER PRIMARY KEY,
  slug       TEXT UNIQUE NOT NULL,   -- used in the QR code URL
  name       TEXT,
  is_open    INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE guests (
  token      TEXT PRIMARY KEY,       -- UUID from the cookie
  nickname   TEXT,
  blocked    INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

CREATE TABLE requests (
  id          INTEGER PRIMARY KEY,
  event_id    INTEGER NOT NULL REFERENCES events(id),
  guest_token TEXT NOT NULL REFERENCES guests(token),
  artist      TEXT,
  title       TEXT NOT NULL,
  votes       INTEGER NOT NULL DEFAULT 1,  -- incremented on duplicates
  status      TEXT NOT NULL DEFAULT 'new', -- new | played | skipped
  created_at  TEXT NOT NULL
);
```

## Open questions

- Tech stack
- How the DJ gets notified (polling, server-sent events, push notification?)
- Where it's hosted (needs to be reachable from guests' phones over mobile data)
