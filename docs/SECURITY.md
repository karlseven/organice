# Security

What is in place, and — more usefully — what is deliberately not.

## Authentication

- **bcrypt, cost 12.** `Core\Auth::hash()`.
- **No user enumeration.** A wrong password, a missing account and a
  deactivated one all return the same message, and `attempt()` runs
  `password_verify` against a dummy hash when no user was found so the three
  take the same time.
- **No public signup.** Accounts exist because an admin made one.
- **Throttling** (`Core\Throttle`): 6 failures per account and 12 per IP in a
  15-minute window. Two counters because they catch different attacks — one IP
  working a password list, versus a botnet spread thin enough that no single
  address looks unusual.
  The per-account counter is also a denial-of-service tool, since anyone can
  fail against a known address. Two things bound that: a correct password
  clears the account's failures, and the window is minutes rather than hours.
- **The IP is `REMOTE_ADDR` only.** `X-Forwarded-For` is never read, because a
  header the client sets is a header the client can forge — trusting it would
  let one attacker present a fresh address on every request and walk straight
  through the throttle. Behind a reverse proxy, have the *proxy* overwrite
  `REMOTE_ADDR`.

## Sessions

- Regenerated **before** the user id is written, so a session id planted in the
  browser beforehand is never the one that ends up authenticated.
- `use_strict_mode=1` — a session id the app did not issue is refused.
- Cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the request
  arrived over TLS.
- **Idle timeout 8 hours, absolute timeout 30 days.** The absolute one matters
  independently: without it a session touched daily never expires, so a cookie
  stolen once is valid forever.
- Bound to a user-agent hash. Weak on its own — a header is forgeable — but the
  common case is a cookie replayed from a different client, and it costs one
  string comparison.
- Sign-out expires the cookie explicitly. `session_destroy()` alone drops the
  server-side data and leaves the browser holding the identifier.
- A deactivated account is checked on **every** request, not only at sign-in.

## Request handling

- **CSRF on every POST**, enforced centrally in the front controller before
  dispatch, so a new POST route is protected by existing rather than by
  remembering. Token accepted from the form field or `X-CSRF-Token`, compared
  with `hash_equals`.
- **All SQL is stored procedures.** The application's MySQL user can be granted
  `EXECUTE` and nothing else (`database/setup.sql`), making this a server-side
  guarantee rather than a convention.
- **Authorisation is declared per route** (`false` / `auth` / `admin`) and
  re-checked per space in `Core\Perm`.
- **A space you may not read returns 404, not 403.** A 403 confirms the space
  exists, which is what someone probing for it wants to learn.
- Open-redirect guard on `?next=` — only same-site paths are followed.
- **Anonymous requests to a protected route go to the sign-in page**, whatever
  level the route needs. Answering 403 was both unhelpful (an admin who had
  been signed out hit a dead end) and a small signal, distinguishing "exists,
  needs admin" from "no such route". A signed-in non-admin still gets a 403,
  because that is the truth.
- `X-Powered-By` is removed. PHP advertises its exact version there by default,
  which tells an attacker precisely which CVEs to try.

## Output

- **Author input is never emitted as markup.** `Core\Markdown` escapes raw HTML
  rather than passing it through, which is what makes caching rendered HTML in
  the database safe: there is no path from author text to live markup, so no
  post-hoc sanitiser is required. `Core\Highlight` likewise escapes everything
  it emits.
- **Link scheme allow-list** — `http`, `https`, `mailto`, and relative URLs.
  An allow-list, not a deny-list: a deny-list is defeated by a new scheme or by
  case and control-character tricks. Control characters are stripped before the
  check.
- **CSP with no `unsafe-inline`.** A per-request nonce is issued for BOTH
  `script-src` and `style-src`, and the layout and editor use it to pass
  server-side values (base path, CSRF token, translated strings) into the page.
  Two consequences worth knowing:
  - `style=""` attributes do not work. Dynamic styling goes through `css_add()`
    and the nonced `<style>` block. Positioning from JavaScript uses CSSOM
    setters (`el.style.top = …`), which CSP does not restrict.
  - `script-src` must keep the nonce. It was briefly `'self'` alone, which
    silently refused every inline block — no console error, no failed request,
    just an editor whose buttons did nothing because `window.ED` was never
    assigned. Curl could not see it; only a browser could.
- `Permissions-Policy` denies camera, microphone, geolocation and payment;
  `COOP`/`CORP` are `same-origin`; HSTS is sent only over real TLS.

## Uploads

- Stored **outside the webroot** and served through `AssetController`, which
  checks the owning space's visibility first — otherwise a private space's
  screenshots are public to anyone who learns the URL.
- Type decided by `finfo` reading the bytes; the browser-supplied
  `Content-Type` is ignored entirely. Allow-list of types, 10 MB cap.
- Named on disk by SHA-256 — the original filename never touches a filesystem
  path, so traversal is not possible.
- SVG is served under `default-src 'none'; sandbox`, because an SVG opened
  directly is a document in this origin and can carry script.

## Audit

`audit_log` records sign-ins and failures, deletions, and every change to roles,
visibility and membership. Page edits are deliberately **not** logged — each is
already a row in `page_revisions` with an author and a timestamp, and
duplicating them would bury the security-relevant entries.

The actor's display name is stored alongside the id, and the foreign key is
`ON DELETE SET NULL`, so deleting an account cannot erase the record of what it
did.

## Known gaps

Be aware of these before putting this on the public internet:

1. **No password reset flow.** No email is sent anywhere. An admin sets
   passwords in `/admin/users`. If the only admin forgets theirs, recovery means
   writing a bcrypt hash into the database by hand.
2. **No two-factor authentication.**
3. **No password strength check** beyond a 10-character minimum — `password` and
   `aaaaaaaaaa` are both accepted.
4. ~~No per-request rate limiting outside the login form.~~ **Fixed** —
   `Core\Throttle::guard()` applies a per-address fixed-window limit to
   `/search` (60/min), `/api/search` (180/min — the as-you-type box makes
   several calls per sentence), `/api/preview` (240/min) and
   `/api/pages/*/translate` (20/min, because each call costs money upstream).
   It fails OPEN: a limiter that takes the site down when its own table is
   unavailable has become the denial of service it was meant to prevent.
5. ~~Uploaded blobs are never garbage-collected.~~ **Fixed** —
   `scripts/gc-assets.php` sweeps both unreferenced assets and files orphaned by
   a deleted space. Dry-run by default. It treats a file referenced by ANY
   revision as live, including old ones, because history stays readable and
   restorable.
6. **`Core\Diff` is O(n·m)** in lines. Bounded at 4000 lines per side, but that
   bound is a limit, not a cheap operation.
7. **No CSP reporting endpoint**, so violations in the wild are invisible.
8. ~~Session data uses PHP's default file handler.~~ **Fixed** — sessions live
   in MySQL (`Core\Session`) on their OWN database connection, with a row lock
   held read-to-write so concurrent requests cannot lose each other's writes.
   The separate connection is essential: on a shared one the session's
   transaction encloses every application query, and releasing the session lock
   rolls back the page the request just saved.

## Adversarial testing

The app has been probed with a scripted attack suite covering SQL injection
(including time-based), stored and reflected XSS, CSRF, IDOR and authorisation,
path traversal, open redirect, security headers and information disclosure.

XSS is verified by **parsing the delivered DOM**, not by grepping the HTML.
Grep produces false positives — an escaped `&lt;img onerror=…&gt;` sitting
inside a meta description matches a naive pattern while being completely inert.
The check instead walks the parsed document and asserts: no element carries an
`on*` attribute, every `<script>` is first-party and nonced, no `href`/`src`
uses a `javascript:`/`vbscript:`/`data:` scheme, no iframes are delivered, and
no form posts cross-origin.

Current result: clean on all of the above.
