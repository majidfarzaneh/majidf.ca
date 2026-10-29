# majid.farzaneh — site, client portal & admin

| Path | What |
| --- | --- |
| `majidf.local` (prod: `majidf.ca`) | One-page landing |
| `panel.majidf.local` | Client portal: dashboard, services, invoices (Stripe), support tickets, new project requests, profile |
| `admin.majidf.local` | Super admin: clients, services, invoice builder, payments, tickets, project requests |

One React build serves all three and picks the site from the hostname (`frontend/src/lib/sites.ts`). The sign-in cookie is shared across the subdomains (`SESSION_DOMAIN`). On `localhost` everything falls back to one host with `/portal` and `/admin` paths.

**Stack:** React 19 + Vite + TypeScript (`frontend/`), Laravel 13 API with Sanctum cookie auth (`backend/`), MySQL 8.4, Redis (sessions, cache, queue), Caddy, Docker Compose.

Sign-in is passwordless for everyone: enter an email, get a 6-digit code by email (10 min, 5 attempts). The super admin uses the same screen with `ADMIN_EMAIL` and lands on `/admin`.

## Local development

```bash
cp backend/.env.example backend/.env      # already done once; set APP_KEY with: php artisan key:generate
docker compose up -d                      # first boot installs composer + npm deps and runs migrations
docker compose exec app php artisan db:seed   # admin account + demo client (local only)
```

Add the local domains once (IPv4 + IPv6 entries avoid macOS's slow `.local` mDNS lookup):

```bash
sudo sh -c 'printf "127.0.0.1 majidf.local panel.majidf.local admin.majidf.local\n::1 majidf.local panel.majidf.local admin.majidf.local\n" >> /etc/hosts'
```

- Site http://majidf.local · portal http://panel.majidf.local · admin http://admin.majidf.local
- Fallback without hosts entries: http://localhost (`/portal`, `/admin`)
- Caddy proxies every host to the Vite dev server and the Laravel API, so cookies and CSRF work like production.
- Uses host port 80, so stop other local stacks that bind it first (`docker stop <container>`). To use another port, set `APP_HOST_PORT` in a root `.env` and add `:<port>` to the URLs and `SANCTUM_STATEFUL_DOMAINS` in `backend/.env`.
- Mailpit (every email, including sign-in codes): http://localhost:8025
- MySQL from the host: `localhost:3316` (portal / portal)
- Ports are configurable: `APP_HOST_PORT`, `MAILPIT_HOST_PORT`, `MYSQL_HOST_PORT`.

Demo logins: `majix.fa@gmail.com` (admin) and `sara@oliveandfig.ca` (client). Grab the code from Mailpit.

Tests: `cd backend && php artisan test` · Frontend checks: `cd frontend && npx tsc -b && npx oxlint`

### Stripe in development

1. Put test keys in `backend/.env` (`STRIPE_KEY=pk_test_…`, `STRIPE_SECRET=sk_test_…`).
2. `docker compose --profile stripe up -d stripe` and copy the `whsec_…` from `docker compose logs stripe` into `STRIPE_WEBHOOK_SECRET`.
3. `docker compose restart app queue`.

Without keys the pay dialog shows e-Transfer instructions instead, and you mark invoices paid from the admin.

## How billing works

- **Invoices** start as drafts (hidden from the client). *Send* issues them: due date = issued + `INVOICE_TERMS_DAYS`, email with PDF attached. Overdue is computed from the due date.
- **Pay with card** uses the Stripe Payment Element inside the portal; **Secure checkout** redirects to Stripe Checkout (Apple Pay / Google Pay / Link). "Pay all due" bundles invoices into one payment. Card data never touches the server.
- The **webhook** (`/api/stripe/webhook`) marks invoices paid; the portal also syncs right after confirmation, so it updates without waiting. Both paths are idempotent.
- **Offline payments:** in the pay dialog clients can choose Interac e-Transfer, cheque or bank transfer. They see your details (`PAY_*` in `backend/.env`; bank transfer only appears when `PAY_BANK_DETAILS` is set) and upload a photo or PDF of the receipt (max 10 MB). The invoice shows **In review**: no Pay button, reminders paused. You get an alert, then use **Payments → To review** to view the receipt and **Approve**, which marks it paid and emails the normal receipt, or **Reject** with a reason, which emails the client and reopens the invoice. Expired invoices can still be paid this way. Receipts are stored privately in `storage/app/private/receipts` and served only to that client and to you.
- **Manual payments** (e-Transfer, cheque, cash…) can still be recorded directly on the invoice page in the admin.
- **Tax per client and per service:** the client form sets a client's tax (business default from `INVOICE_TAX_*`, a custom rate/label, or exempt); a service can override it. Renewal invoices (daily job, *Renewal invoice*, auto-pay) use service → client → default; a new manual invoice starts with the client's tax and stays editable. A 0% invoice shows no tax row.
- **Invoice a renewal early:** in the admin, open a recurring service → *Renewal invoice* → *Invoice & send* (or *Save as draft*). It bills the next period, charges the saved card for auto-pay clients, and moves the renewal date forward one cycle so the daily job doesn't bill that period again.
- **Auto-pay:** a client saves a card in Profile → Payment method (Stripe SetupIntent) or ticks "Save this card" while paying, consenting to renewal charges. The card stays in Stripe; we keep only brand, last 4 and expiry. When `billing:run` auto-invoices one of their services it charges that card off-session instead of emailing the invoice: success sends the receipt; a decline (or a bank asking to confirm) leaves the invoice unpaid, emails the client an "automatic payment failed" invoice with a pay link, and alerts you. Clients can pause auto-pay, replace or remove the card; the admin client page shows its state.
- **Scheduler** (`scheduler` container):
  - `billing:run` daily 08:00 Toronto: auto-invoices services that have *auto-invoice* on when they hit their renewal date (then rolls the date forward), sends renewal reminders 14 days ahead, and sends the invoice reminders below.
- **Payment window:** each invoice has *payable after due · days* (default `INVOICE_GRACE_DAYS=3`, set per invoice in the editor). The client can pay online until due date + grace days; after that it shows as **Expired**: still owed, no Pay button, "Contact me" instead. You can still record a payment, or extend the due date or grace days (which restarts the reminders).
- **Invoice reminders**, each sent once per invoice and only while unpaid:
  - `INVOICE_REMINDER_DAYS_BEFORE` (3) days before the due date: "due on Oct 15"
  - on the due date: "due today"
  - the day after the due date, during the grace period: "overdue, pay by Oct 18"
  - the day after the grace period: "online payment closed", plus an *expired unpaid* alert to you
  - `uptime:check` every 5 min: pings each service's monitor URL and feeds the 30-day uptime bars and the "all systems up" indicator.
- Clients choose which emails they get (Profile → Notifications). Sign-in codes and receipts always send. Invoice reminders follow the *New invoices* switch; your *expired unpaid* alert always sends.

## Admin activity log & notifications

- **Activity log** (admin → *Activity*, `audit_logs`, kept forever): sign-ins (and wrong codes) with IP and browser, client actions (profile, notification settings, tickets, project requests, payment proofs, saved cards/auto-pay, invoice downloads), your own admin actions (clients, services, invoices, payments, tickets, requests, timeline posts) and the system's (auto-invoices, reminders, renewal reminders, Stripe payments, auto-pay). Filter by client, who did it, category or text. `App\Support\Audit::log()` writes it; it never breaks the action it records.
- **Notifications** (the bell in the admin header, Laravel database notifications, checked every 30 s): new ticket / ticket reply, new project request, invoice paid, card payment failed, payment proof to review, auto-pay failed / card removed / auto-pay on or off, invoice due today, overdue, and expired unpaid. `App\Support\AdminAlert::send()` notifies every active admin. Admin alerts are in-app only (no emails).

## Production — majidf.ca

Runs on `91.99.207.212` (shared box, see `/www/wwwroot/SERVER-RUNBOOK.md`) from `/www/wwwroot/majidf.ca`.

- **Deploys are automatic.** Every push to `main` runs `.github/workflows/deploy.yml`: backend tests + frontend checks → builds the PHP and web (Caddy + SPA) images and pushes them to GHCR tagged with the commit SHA → SSHes to the server, whose forced command (`deploy/ci-deploy.sh`) runs `deploy/deploy.sh <sha>`: `git reset` to the commit, `docker compose pull`, `up -d --wait`, health check. Nothing is built on the server.
- **Roll back / redeploy by hand:** `bash /www/wwwroot/majidf.ca/deploy/deploy.sh <sha>` (no argument = latest `origin/main`).
- **Layout:** host networking, loopback only — Caddy `127.0.0.1:8094`, php-fpm `:9094`, Redis `:6394`, MySQL 8.4 `:3307` (a container with the `mysql_data` volume, `majidf_db` / `majidf_db_user`; the host's own MySQL is not used). The host nginx (`deploy/nginx/majidf.ca.conf` → `/etc/nginx/sites-available/majidf.ca`) terminates TLS for `majidf.ca`, `panel.`, `admin.` and redirects `www.`. One Let's Encrypt cert for all four names, renewed by `certbot.timer`.
- **Config:** `/www/wwwroot/majidf.ca/.env.production` (mode 600, never committed; template `.env.production.example`). After editing it run `docker compose -f docker-compose.prod.yml up -d --force-recreate app queue scheduler` from the app dir.
- **Migrations** run when the `app` container starts. Create the admin once: `docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force`.
- **Backups:** nightly `deploy/backup.sh` (`/etc/cron.d/majidf-backup`) → `/var/backups/multisite/majidf/`, database (dumped from the mysql container) + uploaded files, 14 days.
- **Logs:** `docker compose -f docker-compose.prod.yml logs -f app queue web` from the app dir.
- In the Stripe dashboard add the webhook `https://majidf.ca/api/stripe/webhook` with events `payment_intent.succeeded`, `payment_intent.payment_failed`, `checkout.session.completed` and `checkout.session.async_payment_succeeded`.

## Layout

```
backend/            Laravel API (app/Http/Controllers/{Auth,Portal,Admin}, app/Services, resources/views/{mail,pdf})
frontend/           React app (src/pages = landing, src/portal, src/admin, src/components, src/lib)
docker/php          PHP-FPM image (dev + prod targets), shared by app / queue / scheduler
docker/caddy        Caddyfile.dev, production Caddyfile + image that bundles the built SPA
deploy/             deploy + backup scripts, host nginx vhosts
docker-compose.yml  development · docker-compose.prod.yml  production
```
