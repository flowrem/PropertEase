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
- **Landlord** and **Manager** can change listings, payment settings and reservations.
  **Staff** can view them but not change them.

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
- **Listings:** a landlord adds a payment channel, then submits a unit listing with photos.
  A Super Admin approves or rejects it. Only approved listings with room left appear at
  `/find-a-place`.
- **Reservations:** an applicant fills the public form (ID, downpayment proof, chosen
  payment channel). The landlord confirms the downpayment arrived and approves. That
  creates the tenant account and emails a temporary password (valid 72 hours). The
  tenant must choose a new password on first login. An approved reservation holds a
  unit slot until the tenant is assigned or the landlord cancels it.
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

## Production

Live at https://occuplace.onrender.com, deployed to Render as one Docker service that
auto-deploys from `main`. The service was created by hand and its environment variables
are managed in the Render dashboard. `render.yaml` documents that setup but is not linked
to Render (the Blueprint was disconnected), so editing it does not change the deployment.

- **Database:** Supabase Postgres, through the **Session pooler** (port 5432). Set `DB_URL`
  by hand in Render and set `DB_SSLMODE=require`.
- **File storage:** Supabase Storage over its S3 API. `occuplace-media` is public (listing
  photos, payment QR codes) and `occuplace-sensitive` is private (IDs, payment proofs).
  Render's own disk is wiped on every deploy, so nothing important may be stored there.
- **Queue and scheduler:** the container runs `queue:work` and `schedule:work` in the
  background, because Render's free plan has no cron or worker service.
- **Assets:** built locally and committed. After any CSS or JS change run `npm run build`
  and commit `public/build/`, or production serves stale styles.
- **Email:** Resend's HTTP API. Until a sending domain is verified, Resend only delivers
  to the account owner's own address, so approval, rejection and temporary-password
  emails to anyone else fail.
- **Free tier limits:** the service sleeps after 15 minutes idle and takes up to a minute
  to wake. A free Supabase project pauses after a week without activity.
