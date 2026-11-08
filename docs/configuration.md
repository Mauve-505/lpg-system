# Configuration

Runtime configuration lives in `api/config.php`, which is ignored by Git. Start
from `api/config.example.php`:

```powershell
Copy-Item api\config.example.php api\config.php
```

`api/config.php` may return literal values or read environment variables. The
example file uses environment variables with safe local fallbacks. To keep the
configuration outside the web root, place it anywhere readable by PHP and set:

```apache
SetEnv LPG_CONFIG_FILE "C:/secure/lpg/config.php"
```

or set `LPG_CONFIG_FILE` in the PHP-FPM/Apache environment. Without that
variable the API looks for `api/config.php`.

> **Never commit `api/config.php`.** It contains database credentials and the
> session secret.

## Backend settings

| Config key | Env variable | Default | Purpose |
|------------|--------------|---------|---------|
| `env` | `LPG_ENV` | `production` | Label only (`local`, `test`, `production`). |
| `debug` | `LPG_DEBUG` | `false` | Adds exception detail to HTTP 500 responses. Keep `false` in production. |
| `timezone` | `LPG_TIMEZONE` | `Asia/Manila` | PHP timezone. MySQL is set to `+08:00` by the PDO connection. |
| `db.host` | `LPG_DB_HOST` | `127.0.0.1` | Database host. |
| `db.port` | `LPG_DB_PORT` | `3306` | Database port. |
| `db.name` | `LPG_DB_NAME` | `lpg_db` | Database name. |
| `db.user` | `LPG_DB_USER` | `root` | Use a least-privilege app user in production. |
| `db.pass` | `LPG_DB_PASS` | empty | Database password. |
| `db.charset` | — | `utf8mb4` | Leave as is. |
| `db.mysqldump_path` | `LPG_MYSQLDUMP_PATH` | empty | Optional absolute path to `mysqldump` for `database/migrate.php`. |
| `session.name` | `LPG_SESSION_NAME` | `lpg_session` | Cookie name. |
| `session.lifetime` | `LPG_SESSION_LIFETIME` | `7200` | Idle lifetime in seconds; also the absolute cap is `4 ×` this value. |
| `session.secure` | `LPG_SESSION_SECURE` | `null` | `null` = auto (Secure on HTTPS). Force `true` behind a TLS-terminating proxy. |
| `session.samesite` | `LPG_SESSION_SAMESITE` | `Lax` | Cookie SameSite value. |
| `session_secret` | `LPG_SESSION_SECRET` | empty | Server-side secret used for OTP and password-reset token hashes. **Set a unique random value.** |
| `security.csrf` | — | `true` | Keep enabled. Disables CSRF checks only if explicitly set false. |
| `security.password_min_length` | — | `8` | Minimum password length. Raise for stronger policy. |
| `security.login_max_attempts` | — | `5` | Failed logins per username+IP inside the window. |
| `security.login_max_user_attempts` | `LPG_LOGIN_MAX_USER_ATTEMPTS` | `10` | Failed logins per username across all IPs. |
| `security.ip_max_attempts` | — | `30` | Failed logins per IP inside the window. |
| `security.login_window_minutes` | — | `15` | Rate-limit window. |
| `security.lockout_minutes` | — | `15` | Informational lockout duration shown in retry messages. |
| `security.signup_max_attempts` | — | `5` | Signups per IP inside the window. |
| `security.max_body_bytes` | — | `1048576` | Maximum JSON body size (1 MiB). |
| `bootstrap.admin_username` | `LPG_ADMIN_USERNAME` | `admin` | Owner created by `database/seed.php` when no owner exists. |
| `bootstrap.admin_password` | `LPG_ADMIN_PASSWORD` | `admin123` | Temporary bootstrap password; forced to change at first login. Change before real seeding. |
| `otp.provider` | `LPG_OTP_PROVIDER` | empty | Empty = OTP disabled for public signup. A non-empty value requires implementing `send_otp()`. |
| `otp.ttl_minutes` | — | `10` | OTP lifetime. |
| `otp.max_attempts` | — | `5` | OTP verification attempts. |

Generate a session secret:

```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### OTP and SMS

`api/src/auth.php` contains the OTP design:

- codes are 6 digits, hashed with SHA-256 + `session_secret`, single use and
  time-limited;
- verification attempts are counted;
- `send_otp()` currently logs the code to `api/logs/error.log` when no provider
  is configured;
- if `otp.provider` is set, `send_otp()` throws until a real SMS gateway is
  implemented.

When `otp.provider` is empty, signup does **not** require an OTP. This is the
current default. Do not enable OTP in production until a provider is wired in
and tested.

### Session storage

Sessions use PHP's native file handler in
`<system temp>/lpg_sessions` (created with mode `0700`). This keeps session
files outside the web root. On a multi-server deployment, use a shared session
store or sticky sessions; otherwise a user can be logged out when routed to a
different node.

### Database values, not configuration

The following are managed in the database, not in config files:

- branch names, codes, addresses, phones, hours, cutoff times, service areas
  and maximum order quantities (**Branches** page);
- cylinder sizes and global prices (**Sizes**);
- per-branch price overrides (**branch prices**);
- stock quantities (via deliveries, sales, adjustments, transfers);
- customers, staff, credit limits and account status.

## Frontend build settings

Frontend values are compiled into the production bundle by Vite. Put them in
`frontend/.env.local`, `frontend/.env.production`, or the build environment,
then rebuild.

| Variable | Default | Purpose |
|----------|---------|---------|
| `VITE_BRAND_NAME` | `LPG Distributor` | Public brand name. Use a name you are authorized to use. |
| `VITE_COMPANY_NAME` | `Your Company Name` | Legal/owner name used in copy and legal pages. |
| `VITE_BRAND_TAGLINE` | `LPG supply for homes and businesses.` | Hero tagline. |
| `VITE_HERO_IMAGE` | empty | Path under `/images/...` for the hero photo. |
| `VITE_ABOUT_IMAGE` | empty | Path under `/images/...` for the about section. |
| `VITE_CONTACT_PHONE` | empty | Published phone; hidden when empty. |
| `VITE_CONTACT_EMAIL` | empty | Published email and default privacy contact. |
| `VITE_CONTACT_ADDRESS` | empty | Published address; hidden when empty. |
| `VITE_CONTACT_HOURS` | empty | Published office hours. |
| `VITE_SOCIAL_FACEBOOK` | empty | Facebook URL; hidden when empty. |
| `VITE_SOCIAL_INSTAGRAM` | empty | Instagram URL; hidden when empty. |
| `VITE_SOCIAL_YOUTUBE` | empty | YouTube URL; hidden when empty. |
| `VITE_PRIVACY_CONTACT` | `VITE_CONTACT_EMAIL` | Privacy contact shown in the policy. |
| `VITE_LEGAL_VERSION` | `Draft 1` | Legal document version label. |
| `VITE_LEGAL_EFFECTIVE` | `To be set before launch` | Legal effective date. |
| `VITE_API_PROXY` | `http://localhost` | Dev-server proxy target only; not used in production. |

Local development example (`frontend/.env.local`):

```dotenv
VITE_API_PROXY=http://127.0.0.1:8080
VITE_BRAND_NAME=Sample LPG
VITE_COMPANY_NAME=Sample LPG Trading
VITE_CONTACT_PHONE=09170000000
```

Do not put secret values in `VITE_*` variables. Everything prefixed with
`VITE_` is embedded in the public JavaScript bundle.

## Apache configuration

The repository ships two `.htaccess` files:

- root `.htaccess` — SPA fallback, security headers, blocks `database/`,
  `legacy/`, `tests/`, `docs/` and dangerous file extensions;
- `api/.htaccess` — routes all `/api/*` to `api/index.php`, blocks `src/`,
  `logs/`, `config*.php` and dotfiles.

Both require:

```apache
AllowOverride All
```

for the project directory. If your host disallows `.htaccess`, reproduce the
rules in the VirtualHost block and deny the internal folders explicitly.

## PHP settings

Recommended production values:

```ini
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
```

The API sets its own error display, logging and session cookie flags, but
server-level hardening is still required. See [deployment.md](deployment.md).
