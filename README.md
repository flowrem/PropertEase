# Occuplace

A Laravel + Livewire application for landlords to manage properties, tenants,
billing, maintenance and complaints in one shared place, and for people to find
and reserve a unit. Formerly named PropertEase.

`SESSION_CONTEXT.md` holds the architecture notes, deployment gotchas and the
project's design system. Read it before changing anything non-trivial.

## Roles

| Role | Who | How the account is created |
|---|---|---|
| **Super Admin** | The developers running the platform | Only from the command line, see below |
| **Landlord** | A property owner. Owns a team | Self-registers, uploads a valid ID, then waits for a Super Admin to approve |
| **Manager** and **Staff** | People a landlord invites to their team | By email invitation from the landlord. No ID needed |
| **Tenant** | Someone renting a unit | Created when a landlord approves their reservation, or by email invitation |

- **Super Admin** reviews landlord IDs and listings, and sees platform-wide counts.
  It does not see inside a landlord's tenant data.
- **Landlord** and **Manager** can change listings, payment settings, contract terms,
  reservations and transfers, and reply to reports. **Staff** can view them, record unit
  checks and log repairs, but not decide.

## Creating a Super Admin

Super Admin is deliberately not something the app can grant. Anyone with shell
access runs:

```
php artisan app:create-super-admin
```

- **New email:** it asks for a name and a password, and creates an account with no team.
- **Existing email:** it asks to confirm promoting that account. It does not change the password.

Render's Shell is not available on the free plan, so in production use one of these:

- **Register normally, then promote in Supabase:** register the account on the site, then
  open Supabase's **Table Editor**, the `users` table, and set that row's `is_super_admin`
  to `true`. Log out and back in.
- **Run the command from your own machine against the production database:** set
  `DB_CONNECTION=pgsql`, `DB_URL` (the Supabase *Session pooler* string) and
  `DB_SSLMODE=require` in your shell only, run the command, then clear them. Environment
  variables in the shell override `.env`. Turn shell history off first so the password is
  not saved.

## How the main flows work

- **Landlord sign-up:** register with a business name and a valid ID (JPG, PNG or PDF, up
  to 5 MB). The landlord sees only a review page until a Super Admin approves the ID.
  A rejected landlord sees the reason and can upload a new ID. IDs are deleted 30 days
  after a decision.
- **Properties and units:** addresses are picked from the official PSGC list (region,
  province, city or municipality). A unit's type, floor, rooms and floor area are fixed
  once saved; its capacity comes from its floor area and the beds it lists. Each unit has
  a page with a photo, its details, and its inventory and condition checks.
- **Listings:** a landlord adds a payment channel, then submits a unit listing with photos.
  A Super Admin approves or rejects it. Only approved listings with room left appear at
  `/find-a-place`, which filters by region, city, type, price and amenities.
- **Reservations:** reserving is free. An applicant sends their details and ID. When the
  landlord accepts, the unit is held for the team's hold days (default 3) and the
  applicant is emailed a link to their reservation page (`/reservation`), where they pay
  and send proof of the downpayment. The landlord confirms it, which creates the tenant
  account and emails a temporary password (valid 72 hours). With nothing sent by the
  deadline, the unit is released at once and `reservations:expire` (hourly) marks it
  expired.
- **Moving in:** the landlord moves a tenant in from the reservation or the Tenants page,
  after a clean move-in check (or with a reason). This starts the lease and makes the
  tenant's contract from the landlord's contract terms; the tenant reads it and clicks
  "I agree". Contracts are snapshots and print cleanly for a signed copy.
- **Transfers:** a tenant asks to move to another unit of the same landlord and sees
  the rent change. An approved transfer holds the new unit; the landlord completes the
  move on the day after a move-out check, which starts a new lease and contract.
- **Maintenance:** tenants report problems (optionally about an inventory item, with a
  photo); landlords reply and resolve them, and each item keeps its repair history.
- **Billing:** invoices are generated per lease by `invoices:generate`. Payments are
  recorded by the landlord. There is no payment gateway.

## Running it locally

Requirements: PHP 8.4, Composer, Node, and Laravel Herd (the site is served at
`http://Occuplace.test`, no `artisan serve` needed). Local development uses SQLite.

```
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
```

- `composer run dev` starts the queue worker and the asset server together, or run
  `npm run dev` and `php artisan queue:listen --tries=1 --timeout=0` yourself. Emails
  are queued, so without the worker they are never sent.
- Restarting the dev server recreates `public/hot`, which makes the site follow the dev
  server instead of the committed build.
- Tests: `php artisan test --compact`. Static analysis: `vendor/bin/phpstan analyse`.
  Formatting: `vendor/bin/pint`.

### Demo data

```
php artisan db:seed --class=DemoSeeder
```

Adds one complete landlord account next to your existing data: a Lipa dorm with four
units, two public listings, a settled tenant (contract agreed, invoices, repair history),
a tenant with a pending transfer, and one reservation in each state. It prints the
logins; every account's password is `password` and every email ends in
`@demo.occuplace.test`. Emails go to the log. It refuses to run in production, and
running it again does nothing.

### PSGC data

`database/data/psgc.json` holds the regions, provinces and cities/municipalities, taken
from the Philippine Statistics Authority's PSGC Publication Datafile (2Q 2026, as of
30 June 2026). A migration loads it. Acknowledge the PSA as the source wherever the
list is shown or cited.

## Production

Live at https://occuplace.org (the custom domain of the Render service
`occuplace.onrender.com`, which still works), deployed to Render as one Docker service that
auto-deploys from `main`. The service was created by hand and its environment variables
are managed in the Render dashboard. `render.yaml` documents that setup but is not linked
to Render (the Blueprint was disconnected), so editing it does not change the deployment.

- **Database:** Supabase Postgres, through the **Session pooler** (port 5432). Set `DB_URL`
  by hand in Render and set `DB_SSLMODE=require`.
- **File storage:** Supabase Storage over its S3 API. `occuplace-media` is public (listing
  photos, unit photos, payment QR codes) and `occuplace-sensitive` is private (IDs,
  payment proofs, report photos). Unit photos are saved as WebP, so the media bucket must
  allow `image/webp`. Render's own disk is wiped on every deploy, so nothing important
  may be stored there.
- **Queue and scheduler:** the container runs `queue:work` and `schedule:work` in the
  background, because Render's free plan has no cron or worker service. Scheduled:
  `invoices:generate`, `reservations:prune-files`, `landlord-ids:prune` (daily) and
  `reservations:expire` (hourly). A missed run is harmless: they can be run by hand.
- **PHP extensions:** the Docker image installs `pdo_pgsql`, `mbstring`, `zip`, `gd`
  (JPEG and WebP, for shrinking unit photos) and `exif`. It has no `intl`, so never use
  Laravel's `Number` helper.
- **Assets:** built locally and committed. After any CSS or JS change run `npm run build`
  and commit `public/build/`, or production serves stale styles.
- **Email:** Resend's HTTP API, sending as `no-reply@occuplace.org` from the verified
  domain `occuplace.org`. Its DKIM, SPF and DMARC records live in Z.com's DNS; if they are
  removed, Resend falls back to delivering only to the account owner's address.
- **Domain:** `occuplace.org`, registered at Z.com. DNS (Z.com's Manual DNS page) holds
  an A record for `@` to Render's `216.24.57.1`, a `www` CNAME to `occuplace.onrender.com`,
  and the Resend records. `APP_URL` must match the domain, since email links and signed
  reservation links are built from it.
- **Free tier limits:** the service sleeps after 15 minutes idle and takes up to a minute
  to wake. A free Supabase project pauses after a week without activity.
