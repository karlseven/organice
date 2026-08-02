# Deploying to a VPS

No Composer, no npm, no build step. Copy the tree, create the database, point the
web server at it.

Two layouts are supported and both are verified:

- **Subdirectory** — `https://your.server/organice/`, the folder dropped into a
  shared web root alongside other projects. Needs the shipped root `.htaccess`
  and `APP_BASE=/organice`. See §5.
- **Virtual host** — DocumentRoot straight at `public/`, `APP_BASE=` blank.
  Better isolation; use it if you can.

Run `php scripts/security-check.php` after every deploy — most of what it looks
for is a deployment mistake rather than a coding one, and it exits non-zero so
it can gate the deploy.

## 1. Requirements

- **PHP 7.4 or newer** — tested on 7.4.33 and 8.2.12; nothing in between should
  differ, but only those two were actually run — with `pdo_mysql`, `mbstring`,
  `fileinfo`, `json`
- MySQL 8.0+ (the schema uses `utf8mb4_0900_ai_ci` and an ngram FULLTEXT parser)
- Apache or nginx

The three PHP 8 string functions the app uses are polyfilled in
`app/polyfill.php`, and `config/config.php` refuses to run below 7.4 with a
plain message rather than a fatal deep inside an unrelated file. Verified by
running the whole suite on a real PHP 7.4.33: 80/80 security probes and 51/52
functional checks, identical to 8.2, with no warnings or deprecations.

Enable **opcache**. The bundled icon set is a ~400 KB PHP array; with opcache it
is compiled once instead of on every request.

## 2. Files

```
/var/www/organice/          app/ config/ database/ docs/ scripts/ storage/ public/
```

**Only `public/` may be reachable over HTTP.** How that is enforced depends on
the layout:

- **Virtual host** — by the filesystem. `.env`, `app/` and `storage/` are above
  the document root and unreachable no matter what the config says.
- **Subdirectory** — by the root `.htaccess`. The source really is inside the
  document root, and a list of rewrite rules is what keeps it private. That is
  weaker: a directory added later and not named in those rules would be served.
  If you add a top-level directory to this project, add it to that file.

Either way, `scripts/security-check.php` verifies that `index.php` is the only
PHP file in `public/` and that uploads are not under the web root.

Ownership: the web user needs **write** access to `storage/` and nothing else.

```bash
chown -R root:www-data /var/www/organice
chmod -R o-rwx /var/www/organice
chown -R www-data:www-data /var/www/organice/storage
chmod 640 /var/www/organice/.env
```

## 3. Database

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/procedures.sql
```

`schema.sql` starts with `ALTER DATABASE ... COLLATE utf8mb4_0900_ai_ci`. Do not
remove it: stored-procedure parameters inherit the database's default collation
at CREATE time, and a mismatch makes every `column = p_param` comparison fail
with error 1267.

Give the application user **EXECUTE only**. Every query in the app goes through
an `sp_` procedure, so it never needs table rights — which means SQL injection
has nothing to reach even if a bug let one through.

```sql
CREATE USER 'organice_app'@'localhost' IDENTIFIED BY '<long random>';
GRANT EXECUTE ON organice.* TO 'organice_app'@'localhost';
FLUSH PRIVILEGES;
```

> The one exception is `sessions`, written through `sp_session_*`. Still EXECUTE.

## 4. `.env`

```ini
APP_ENV=prod          # REQUIRED. `dev` prints stack traces to visitors.
APP_BASE=             # '/organice' for a subdirectory install; blank for a vhost
DB_HOST=127.0.0.1
DB_NAME=organice
DB_USER=organice_app
DB_PASS=<long random>
SITE_NAME=Your docs
MT_KEY=               # machine-translation key, if used
```

`MT_KEY` lives here and **never** in the settings table — that table is editable
from a web form and readable by every admin.

## 5. Web server

Everything routes through `public/index.php`.

**nginx**

```nginx
server {
    listen 443 ssl http2;
    server_name docs.example.com;
    root /var/www/organice/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    # Nothing outside public/ is reachable, but be explicit.
    location ~ /\.(env|git) { deny all; return 404; }

    client_max_body_size 12M;   # uploads are capped at 10 MB in Uploader
}
```

**Apache** — set `AllowOverride All` for `public/` and use the shipped
`public/.htaccess`. Enable all five modules:

```bash
a2enmod rewrite headers deflate filter expires
systemctl reload apache2
```

`filter` matters more than it looks: `AddOutputFilterByType` is defined by
`mod_filter`, not `mod_deflate`. With `deflate` enabled and `filter` missing,
compression is **silently skipped entirely** — the sprite ships at 660 KB
instead of 91 KB.

Also set these in `apache2.conf` (they are server-config only and cannot go in
`.htaccess`):

```apache
ServerTokens Prod
TraceEnable Off
```

### Installing in a subdirectory — `https://your.server/organice/`

The common setup when one server hosts many projects in one web root, with the
folder name at the end of the URL. It works, and it needs exactly two things.

**1. The shipped `.htaccess` at the project root.** It refuses `app/`, `config/`,
`database/`, `docs/`, `scripts/`, `storage/`, dotfiles and loose source files,
then routes everything else into `public/`. Both halves matter: routing alone
would leave `.env` sitting in the document root, one request away.

**2. `APP_BASE` in `.env`, matching the folder:**

```ini
APP_BASE=/organice
```

Without it every generated link is wrong. Auto-detection cannot help here — it
reads `dirname(SCRIPT_NAME)`, which after the rewrite is `/organice/public`, so
links come out as `/organice/public/some-page`. **Rename the folder and you must
change `APP_BASE` to match**; nothing else hardcodes the name.

Verified against Apache 2.4 with DocumentRoot on a shared parent holding ~80
project folders:

| | |
|---|---|
| `/organice/`, `/organice/installation`, `/organice/th/installation` | 200 |
| `sitemap.xml`, `robots.txt`, `login`, all assets | 200 |
| generated links | `/organice/…`, correctly prefixed |
| `.env`, `config/`, `app/`, `database/`, `scripts/`, `docs/`, `README.md` | **403** |
| directory listings anywhere | none |
| sibling project folders | untouched — the rules are scoped to this directory |

The same file is also correct if DocumentRoot is pointed **at the project root**
(also verified: app works, sensitive paths 403), and it is simply never read if
DocumentRoot is on `public/`. One file, all three layouts.

### A virtual host per site

If you do use vhosts, this is better: point DocumentRoot straight at `public/`
so the source is not under the web root at all, and set `APP_BASE=` (blank).

```apache
# /etc/apache2/sites-available/docs.example.com.conf
<VirtualHost *:443>
    ServerName docs.example.com
    DocumentRoot /var/www/organice/public

    <Directory /var/www/organice/public>
        AllowOverride All
        Require all granted
    </Directory>

    # A pool of its own: separate PHP user, separate session storage, and one
    # site cannot exhaust another's PHP workers.
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/organice.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog  ${APACHE_LOG_DIR}/organice-error.log
    CustomLog ${APACHE_LOG_DIR}/organice-access.log combined

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/docs.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/docs.example.com/privkey.pem
</VirtualHost>
```

```bash
a2ensite docs.example.com && systemctl reload apache2
```

Two things worth doing when sites share a server:

- **A PHP-FPM pool per site**, as above. Sessions here live in MySQL rather than
  on disk, so cross-site session file leakage is not a concern, but a separate
  pool user still keeps one site's code away from another's files.
- **`open_basedir`** per pool, if the sites are not all yours:
  `php_admin_value[open_basedir] = /var/www/organice:/tmp`

After deploying, confirm compression is actually on — the single biggest
transfer win here, and the easiest to lose:

```bash
curl -s -H 'Accept-Encoding: gzip' -D - -o /dev/null https://your.site/assets/icons/lucide.svg | grep -i content-encoding
curl -s -H 'Accept-Encoding: gzip' -D - -o /dev/null https://your.site/ | grep -i content-encoding
```

If the second returns nothing, your PHP handler puts the response in a different
directory context; move `AddOutputFilterByType DEFLATE text/html` into the
vhost. Measured on a test rig: HTML 8.7 KB → 2.7 KB, JS 29 KB → 10 KB, sprite
660 KB → 91 KB.

## 6. HTTPS

Get a certificate before opening the site up. Several protections only engage
over TLS:

- `Strict-Transport-Security` is sent **only** when the request arrived over
  HTTPS — sending it over plain HTTP is meaningless.
- The session cookie's `Secure` flag is set from the same condition, so over
  plain HTTP the session cookie travels in the clear.

Redirect HTTP → HTTPS at the web server.

## 7. Before opening it up

- [ ] `php scripts/security-check.php` passes
- [ ] `APP_BASE` matches the URL — `/organice` for a subdirectory, blank for a vhost
- [ ] `curl -I https://your.server/organice/.env` returns **403**
- [ ] `APP_ENV=prod`
- [ ] **Rotate the admin password.** `php scripts/set-credentials.php <email> --password=…`
- [ ] Database user has EXECUTE only
- [ ] `storage/` is writable by the web user and unreachable over HTTP
- [ ] TLS works and HTTP redirects to it
- [ ] `/`, `/sitemap.xml`, `/robots.txt` all respond
- [ ] Sign in, create a page, upload an image, search for it
- [ ] Set up backups: the database **and** `storage/`

## 8. Ongoing

```bash
php scripts/gc-assets.php --delete   # sweep uploads no page references
php scripts/check-links.php          # report broken internal links
php scripts/rerender.php             # re-render every page after a parser change
```

`gc-assets.php` is the one to schedule. Deleting a page does **not** delete its
uploaded blobs — deliberately, since the same file may be used by several pages
and a revision may still reference it.

## What this app does not have

Stated plainly, because a VPS is a public address:

- **No password reset.** There is no email path at all. A locked-out admin is
  recovered with `scripts/set-credentials.php` over SSH.
- **No 2FA.**
- **No password strength rule** beyond a 10-character minimum.
- **No CSP report endpoint**, so violations are invisible.
- Login is throttled (6 per account, 12 per IP, per 15 minutes) and `/search`,
  `/api/search`, `/api/preview` and `/api/pages/*/translate` are rate limited
  per address. Both **fail open** if their table is unavailable: a limiter that
  takes the site down when its own storage breaks has become the outage it was
  meant to prevent.

Put a CDN or reverse proxy in front if you expect real traffic — none of the
above is a substitute for one.
