# Occuplace — Session Context

Paste this whole file into a new Claude Code conversation (in this repo) to restore context if a session forgets what happened.

Formerly named PropertEase — the product was renamed to Occuplace as part of the professor-revisions branch. Infrastructure identifiers created under the old name (the Herd site, the GitHub repo, the Render service and its URL) were deliberately left unchanged; see the note wherever they're referenced below.

## What Occuplace Is

A Laravel webapp automating landlord-tenant management for apartments/dormitories: rent billing, maintenance/complaint tracking, and announcements. Built from real research: a 48-respondent tenant survey (`Tenant Research Questionnaire`, collected 2026-09-09 to 2026-09-19) drives feature priority. Key findings that shaped decisions:

- 71% of tenants are satisfied with their landlord — the pain is **process** (nothing tracked), not the relationship.
- Maintenance is reported informally (Messenger 26, in-person 23, SMS 17 respondents) with zero record-keeping. Response speed is fine; traceability is the gap.
- Payment methods already in use: GCash (19), Cash (18), Bank Transfer (10), Cheque (1).
- Strongest single demand (29/47): one system to see balance, due date, payment history, and status.
- 19% of complaints got no response/follow-up.
- Adoption concerns, ranked: unreliable internet (27) > privacy/security (14) > prefers talking to the owner directly (11) > tech difficulty (8). **This is why UI stays lightweight — no heavy JS, no scroll animations.**

Source docs (outside the repo, on the user's machine): `Tenant_Survey_Analysis_and_System_Features.pdf`, a DB ERD (`physical.png`), and a "Coastal Midnight" color palette image.

**The app is deployed and publicly live** at `https://propertease-wv4j.onrender.com` (Render free tier). See the Deployment section — it has its own set of hard-won gotchas.

## Professor Revisions — Multi-Phase Work In Progress

Implementing `c:\Users\Rem\Downloads\REVISIONS_PROMPT.md` (**not in the repo** — read it in full before resuming this work), an 8-phase brief responding to a professor's feedback. Working on branch **`feature/professor-revisions`**, never `main` (`main` auto-deploys to the live Render app). Do not merge or rebase onto PR #1 (it branches from the first commit and would revert later auth-page work).

**Locked-in decisions** (the user's own choices, overriding the brief's `{{NEW_NAME}}` placeholder and a couple of open questions):
- Product name: **Occuplace** (Phase 1 done — renamed everywhere except the Render service name, database name, `APP_URL`, and the GitHub repo `flowrem/PropertEase`, which stay per the brief's rule 8).
- S3 dependency approved (`league/flysystem-aws-s3-v3`). User doesn't have a bucket yet, so production uploads stay ephemeral until one's configured (Cloudflare R2 / Supabase Storage were floated as candidates, unconfirmed).
- PHP upload limits will be raised for Phase 3 — both local Herd `php.ini` and a new `docker/php.ini` copied into the Dockerfile, since production currently ships PHP's stock 2MB/8MB with no `php.ini` in the image at all.
- Minimum applicant age: 18 (the brief's own default, lands in Phase 5).
- Super Admin promotion is **artisan-only** (`app:create-super-admin`), reachable by anyone with shell access to the server or a local machine — no extra permission layer beyond that. Confirmed with the user this is the intended trust boundary: teammates self-register as landlords, then whoever has shell access promotes them.
- Every other Open Decision in the brief uses its own stated default (tenant invite-by-email kept, no landlord approval gate, reservation-based slot holding, manual downpayment verification, reject-if-email-already-exists, Owner+Manager can approve/submit and Staff view-only, payment QR set once per team not per listing).

**Progress:**
- ✅ **Phase 1 (rename to Occuplace)** — done, committed.
- ✅ **Phase 2 (Super Admin + role hierarchy)** — done, committed. Added `users.is_super_admin` (kept out of `#[Fillable]`, artisan-only), `EnsureSuperAdmin` middleware, `/admin` route group (`admin.dashboard`, `admin.landlords`), fixed a sidebar-layout crash for teamless users (it called `route('dashboard')` and `isLandlordOn(null)`, both of which assumed a team), landlord-only registration with a business-name field, relabeled `TeamRole` (Owner→Landlord, Admin→Manager, Member→Staff; stored values unchanged).
- 🔲 **Phase 3 (Unit Listings + payment channels)** — **plan presented to the user, not yet approved or started.** Covers: `media`/`sensitive` storage disks, the S3 adapter, raised upload limits, a `ListingStatus` enum, `unit_listings`/`listing_photos`/`payment_channels` tables, landlord `listings` + `payment-settings` pages, `/admin/listings` review queue, cross-team policies (Owner/Manager write, Staff view-only). One open question flagged to the user and still unanswered: whether Staff should see disabled Submit/Unlist buttons or have them hidden entirely.
- 🔲 Phases 4-8 not started: public landing/browse pages, public reservation submission, landlord review + tenant account provisioning, forced first-login password change, final wrap-up (asset build, `SESSION_CONTEXT.md`/`README.md` updates, manual-steps checklist).

**Non-negotiable rules from the brief, apply to every remaining phase:** migrations are additive-only, never edit an existing migration; never `migrate:fresh`/`db:wipe` outside the test suite; keep `php artisan test --compact`, PHPStan, and Pint green after every phase; one commit per logical step, no catch-alls; never rename the Render service/database/`APP_URL`; present a short plan and wait for approval at the start of each phase, stop and summarize at the end; if an ambiguity isn't covered by the brief or its Open Decisions, stop and ask. Temporary tenant passwords (Phase 6) need the same discipline already applied to `is_super_admin`: never logged, never flashed, never stored in plaintext, and the notification carrying one must be both `ShouldQueue` and `ShouldBeEncrypted`.

**Test count as of Phase 2**: 188/188 passing, PHPStan 0 errors, Pint clean.

## Tech Stack

- Laravel 13, PHP 8.4, **Livewire 4** using **Single-File Components** with a `⚡` filename prefix (`resources/views/pages/⚡name.blade.php` for full pages, registered via `Route::livewire()`).
- **Flux UI** (free edition) for components. Some Flux internals were published into `resources/views/flux/` for customization beyond what props allow (project convention — check there before assuming a Flux default).
- **Fortify** for auth. Registration is invitation-aware — see Architecture below.
- **Tailwind CSS v4** — CSS-first config in `resources/css/app.css` via `@theme`, no `tailwind.config.js`.
- **Teams-based multi-tenancy** (starter-kit default, repurposed — see Architecture below).
- Pest for tests (**175 passing** as of this session), Larastan/PHPStan for static analysis (clean, 0 errors), Pint for formatting.
- **Database: SQLite locally, PostgreSQL in production.** Nothing in the codebase is SQLite-specific (verified — no raw SQLite SQL, no native `enum()` columns; enums are plain `string` columns + PHP enum casts, which is what makes this portable).
- Fonts via Bunny Fonts (`vite.config.js`, `bunny()` helper) — currently **Plus Jakarta Sans**.
- **Frontend build is `vite-plus` / rolldown (Vite 8)** — `npm run build` runs `vp build`. This toolchain is very new and **fails inside Linux Docker containers** with an unreadable error (see Deployment). Assets are therefore built locally and committed.
- **Carbon is immutable app-wide**: `AppServiceProvider` calls `Date::use(CarbonImmutable::class)`. Any method returning a date **must** be typed `CarbonImmutable`, not `Illuminate\Support\Carbon` — a `Carbon` return type will throw a TypeError at runtime.
- **Mail**: local `.env` uses Gmail SMTP; **production uses Resend's HTTP API** (`MAIL_MAILER=resend`, `resend/resend-php`) because Render blocks outbound SMTP ports. See Deployment.

### Environment quirks (Windows)

- PHP is managed by **Laravel Herd**, not on the Bash tool's PATH. From Bash, use the `.bat` wrappers directly (`composer.bat`, `php.bat`) — plain `composer`/`php` resolve to nothing. **PowerShell resolves them natively and is strongly preferred** for `artisan`/`pint`/`pest`/`composer` one-offs.
- **Docker is not installed locally** — you cannot test-build the Dockerfile on this machine. Render's build is the first real test of any Dockerfile change.
- The project is `herd link`ed and served continuously at `http://Occuplace.test`.
- **Real device (phone) testing no longer needs a tunnel** — the app is deployed publicly now. The old Expose tunnel workflow (1-hour cap, 60-minute cooldown, `expose token <token>` to bypass the hanging device-code login) is obsolete but documented in git history if ever needed again.
- **`bootstrap/app.php` trusts all proxies** (`$middleware->trustProxies(at: '*')`). Without this, Laravel doesn't know a request arriving through a reverse proxy was originally HTTPS, so `asset()`/`url()` emit `http://` on an `https://` page — browsers silently block those as mixed content, so the page loads with zero CSS/JS. **This matters in production on Render too, not just tunnels.**
- Killing the dev server with a broad `taskkill /IM php.exe` also kills the Laravel Boost MCP connection. Prefer killing by specific PID.

## Directory Structure (what matters here)

```
app/
  Enums/            TeamRole, TeamPermission, PropertyType, UnitStatus, LeaseStatus,
                     ServiceBillingType, InvoiceStatus, PaymentMethod, DocumentType,
                     ConcernCategory, ConcernPriority, ConcernStatus,
                     BillingTiming (NEW: Advance | Arrears)
  Models/            Team, User, Membership, TeamInvitation (starter kit)
                     Property, Unit, Lease, LeaseRent, Service, ServiceSubscription,
                     Invoice, Payment, Document, Concern, ConcernUpdate, Announcement
                     Unit:    allows_multiple_tenants, tenant_limit, price
                              hasRoomForAnotherTenant(), activeLeaseCount(),
                              rentSharePerTenant(), splitRentAmongActiveTenants(),
                              resyncAfterLeaseEnded()
                     Lease:   billing_timing; currentInvoice(), currentRent() (ofMany
                              + closure, NOT plain latestOfMany), end(LeaseStatus),
                              nextDueDateAfter(), nextRentEffectiveDate(), rentEffectiveOn()
                     Invoice: scopeOutstanding(), balanceDue(), isPastDue()
  Actions/
    Teams/CreateTeam.php            Creates a team + Owner membership (personal teams)
    Teams/AcceptTeamInvitation.php  Joins a user to an invitation's team
    Fortify/CreateNewUser.php       Invitation-aware registration
    Invoices/GenerateDueInvoicesForLease.php  Walks billing cycles, creates Invoice rows
    Invoices/RecordInvoicePayment.php         Creates a Payment, recomputes invoice status
  Console/Commands/GenerateInvoices.php       `invoices:generate`, scheduled daily
  Notifications/Teams/TeamInvitation.php      Role-aware copy; implements ShouldQueue
  Concerns/HasTeams.php   Team-membership helpers on User, incl. isLandlordOn(Team)
  Http/Middleware/
    EnsureTeamMembership.php   Gates routes; ':member' blocks the Tenant role
    SetTeamUrlDefaults.php     Sets URL::defaults(current_team)

database/
  migrations/        12 original tables + notifications, plus:
                     units.allows_multiple_tenants, units.tenant_limit, units.price,
                     leases.billing_timing

resources/
  views/
    pages/
      ⚡dashboard.blade.php    Role-aware Home. Tenants with an active lease also get a
                               "Your unit" card with a Leave unit action (confirm modal)
      ⚡billing.blade.php      REAL now (was a stub): tenant's current balance, next due
                               date, outstanding invoices, and paid history
      ⚡maintenance.blade.php ⚡complaints.blade.php    Still "Coming soon" stubs
      ⚡announcements.blade.php  Real, role-aware
      landlord/
        ⚡setup.blade.php       4-step onboarding wizard
        ⚡properties.blade.php  Expand/collapse; inline add/edit unit incl. price + occupancy
        ⚡tenants.blade.php     Tenant roster; **every row is clickable** → manage modal
                                (assign / move to another unit / remove, + due day +
                                billing timing)
        ⚡invoices.blade.php    NEW: landlord ledger. Tenants sorted by who owes most;
                                click one → their full invoice collection split into
                                Outstanding and History, with a Record payment flow
        ⚡inbox.blade.php       Read-only Concerns feed
      teams/…                   Team/staff management (unchanged)

routes/
  web.php            {current_team}/{setup,properties,tenants,invoices,inbox} gated ':member'
  console.php        Schedule::command('invoices:generate')->daily()

Dockerfile          PHP 8.4-cli image; NO Node stage (assets are pre-built + committed)
.dockerignore
render.yaml         Render Blueprint: web service + free Postgres + env vars

tests/Feature/
  Invoices/GenerateDueInvoicesForLeaseTest.php  Proration, advance vs arrears, idempotency
  Invoices/GenerateInvoicesCommandTest.php
  InvoiceTest.php                  balanceDue / isPastDue / outstanding scope
  LeaseRentSchedulingTest.php      nextRentEffectiveDate branches + currentRent filtering
  Landlord/InvoicesPageTest.php    Ledger page: auth, search, payments, validation, isolation
  BillingTest.php                  Real now: tenant sees own balance/history, isolation
  Landlord/TenantsPageTest.php     Extended: move, remove, billing timing
  (plus all prior suites)
```

## Architecture & Rules Established

**Team = one landlord's account.** A Team can own many Properties.

**Roles** (`App\Enums\TeamRole`, by `level()`): `Owner` (3) > `Admin` (2) > `Member` (1) > `Tenant` (0). Landlord-only routes use `EnsureTeamMembership::class.':member'`.

**Domain model**: `Property` → `Unit` → `Lease` → `Invoice` → `Payment`. `LeaseRent` is rent-over-time history on Lease. `Concern` → `ConcernUpdate`. `Announcement` belongs to Property.

**Multi-tenant units are opt-in per unit.** `Unit::hasRoomForAnotherTenant()` is the single source of truth, enforced both in the assign dropdown and server-side.

**Rent lives on the Unit as one `price`, split equally.** `Unit::rentSharePerTenant()` = `price / active lease count`, written as a fresh `LeaseRent` row via `Unit::splitRentAmongActiveTenants()`. Runs on assign, move, remove, and price edit.

### Lease lifecycle (NEW this session)

**A lease can now end, and occupancy can go back down.** `Lease::end(LeaseStatus $status)` stamps `status` + `end_date`, then calls `Unit::resyncAfterLeaseEnded()`, which either re-splits rent among whoever remains or flips the unit back to `Vacant` if it's now empty. Two entry points, distinguished by status:

- **`LeaseStatus::Terminated`** — landlord/admin removed the tenant (Tenants page manage modal).
- **`LeaseStatus::Ended`** — tenant left voluntarily (dashboard "Leave unit"), **and also** when a tenant is moved to a different unit (their old lease ends, a new one is created). If you ever need to distinguish "moved" from "left entirely" in reporting, that needs a new enum case.

**Moving a tenant is end-old-lease + create-new-lease**, not an update to `unit_id`. This keeps the old unit's invoice history intact under the old lease, which is deliberate: debt belongs to the lease, so moving or removing a tenant never erases what they owed.

**Anything reading "the tenant's current unit" must filter `status = active`.** The Tenants page eager-load does this. Forgetting it means a terminated lease keeps showing as the tenant's current unit.

### Rent changes are never retroactive (NEW this session)

`Unit::splitRentAmongActiveTenants()` picks the effective date **per lease**:

- A lease with **no rent history** (brand new assignment or move) → effective **today**, immediately.
- A lease that **already has rent history** → effective on **its own next due day** (`Lease::nextRentEffectiveDate()`), so a billing cycle already in progress is never altered.

This applies uniformly: price edits, a roommate joining (everyone's share drops **next cycle**, not instantly), and a roommate leaving. `Lease::currentRent()` therefore uses `ofMany([...], closure)` filtered to `effective_date <= today()->endOfDay()` rather than plain `latestOfMany()`, so a future-dated row doesn't apply early. `Lease::rentEffectiveOn($date)` generalizes this for invoice generation.

### Billing timing is per-lease (NEW this session)

`leases.billing_timing` (`App\Enums\BillingTiming`: `advance` default, or `arrears`), set in the Tenants page manage modal next to due day. Chosen per-lease deliberately (not per-unit) because in PH dorms two tenants in the same unit can legitimately pay different amounts/terms.

- **Advance**: the invoice's due date is the **start** of the cycle it covers (pay before you occupy it).
- **Arrears**: the due date is the **end** of that cycle (pay after).

### Invoice generation (NEW this session)

`App\Actions\Invoices\GenerateDueInvoicesForLease` + `invoices:generate` (scheduled daily, also runnable by hand). Per active lease it walks forward from where it left off, creating one Invoice per cycle up to today:

- **Cycle boundaries**: `[cycleStart, nextDueDateAfter(cycleStart) - 1 day]`. The same computation handles both the prorated first stub and every full cycle after it.
- **Due date**: advance → `cycleStart`; arrears → `cycleEnd + 1 day`. (Applied uniformly, including to the stub — in advance mode the stub is due on the lease's start date, in arrears on the first due day. That detail was a judgment call: it's the only version that neither leaves a cycle unbilled nor double-bills the first month.)
- **Proration**: only the **first** cycle. `monthlyRent / daysInMonth(cycleStart) * daysOwed`.
- **Rent amount** comes from `rentEffectiveOn(cycleStart)`, **not** `Unit::price` — which is exactly why the deferred-effective-date work above matters.
- **Idempotent**: resumes from the last invoice's `billing_end + 1`, so re-running never duplicates.
- Skips any lease that isn't `Active`, so ended/terminated leases stop generating new invoices while keeping their old ones.
- `service_amount` and `penalty_amount` are **hardcoded to 0** — services and late fees are not implemented.

**Payments**: `App\Actions\Invoices\RecordInvoicePayment` creates a `Payment` row and recomputes the invoice's status from the sum paid (`Paid` if covered, else `PartiallyPaid`). Landlord-entered only; there is no payment gateway. Overpayment is blocked by validation (`max` = remaining balance).

**Overdue is computed, not stored.** Nothing flips invoices to `InvoiceStatus::Overdue` in the background; `Invoice::isPastDue()` derives it (unpaid/partial **and** `due_date` before today) for display. Note it uses `isBefore(today())`, so an invoice due *today* is not yet overdue.

## Deployment (Render) — read this before touching deploy config

Live at `https://propertease-wv4j.onrender.com`. GitHub repo `flowrem/PropertEase`, auto-deploys on push to `main`.

**Why Render and not Laravel Cloud**: Laravel Cloud signup requires a payment method the user doesn't have. Render's free tier does not. (Railway was also evaluated: it has a genuinely card-free trial, but its Limited Trial explicitly restricts outbound network access, so it may hit the same SMTP wall — not worth migrating for.)

**Stack shape**: single Docker web service + Render's free Postgres, defined declaratively in `render.yaml` (a Blueprint). No Redis, no separate worker service — session/cache/queue are all database-backed.

### Hard-won deployment gotchas

1. **Frontend assets are pre-built locally and committed** (`public/build/` is no longer gitignored). The `vite-plus`/rolldown toolchain fails inside Linux Docker with an error whose real message is swallowed (`errors: [Getter/Setter]` — Node's inspector refuses to evaluate a lazy getter when formatting). Switching Alpine → Debian and `npm ci` → `npm install` both failed to fix it. **Consequence: after any CSS/JS/asset change you must run `npm run build` locally and commit `public/build/` or production serves stale assets.**
2. **Free Postgres, not SQLite** — Render's filesystem is ephemeral, a SQLite file would be wiped each deploy. Also note the free database **expires 30 days after creation**.
3. **Production data is a separate, empty database.** Migrations create the schema; no local data carries over. This confused the user once ("my old accounts were removed") — expected, not a bug.
4. **No queue worker = no email.** `TeamInvitation` implements `ShouldQueue`, so with only `php artisan serve` running, jobs sat in the `jobs` table forever. Fixed by backgrounding `php artisan queue:work` in the container `CMD`.
5. **`php artisan serve` is single-threaded.** Making mail synchronous (`QUEUE_CONNECTION=sync`) to dodge #4 caused a worse bug: a slow SMTP send blocked *every* request including Render's health check, which timed out and got the instance flagged unhealthy mid-request. Fixed with `PHP_CLI_SERVER_WORKERS=4` **and** reverting to a real background worker.
6. **Render blocks outbound SMTP.** Gmail on port 587 fails with a flat `Connection timed out` (not refused, not an auth error — the signature of a firewall silently dropping packets). **Production therefore uses Resend's HTTP API** (`MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS=onboarding@resend.dev`). Local dev still uses Gmail SMTP, which works fine there.
7. **Resend sandbox only delivers to the account owner's own email** until a domain is verified via DNS. Currently that's `mendozaac@students.nu-lipa.edu.ph`. Inviting anyone else fails with an explicit API error. Verifying a domain needs a domain the user doesn't own, so this is an accepted limitation, not a bug to chase.
8. **`APP_URL` must be set explicitly.** It wasn't, so Laravel fell back to its built-in `http://localhost` default and every invitation email linked to localhost. Now pinned in `render.yaml`.
9. **No cron on Render's free plan**, so `invoices:generate` does **not** run automatically in production despite being scheduled. Run it by hand via the service's Shell tab, or point a free external pinger at a protected route.
10. **Blueprint env var changes don't always sync** to an already-created service. If a var added to `render.yaml` doesn't appear in the dashboard's Environment tab, add it manually there.
11. **Free instances spin down after 15 min idle** and take 30-60s to wake. Load the URL before demoing.

## Design System

**Palette — "Coastal Midnight"** (`resources/css/app.css`, `@theme`):
- `--color-brand-900: #061222` — dominant background (~60%)
- `--color-brand-800: #123249` — card/panel surfaces (~30%)
- `--color-brand-600: #2d5b75` — secondary accent
- `--color-brand-500: #447794` — **primary accent**, app-wide via `--color-accent`

**Font**: Plus Jakarta Sans.

**Contrast**: verified numerically against WCAG AA. `text-zinc-400` on `brand-900`/`brand-800` passes (7.33:1 / 5.19:1). `text-zinc-500` on `brand-900` **fails** (3.89:1). Check anything zinc-500 or darker before shipping.

**antislop skill is active in "during" mode** — no em dashes in UI copy, no fabricated stats/testimonials, real nav destinations only, honest empty states.

## Chronological Build Log

1-19. *(Earlier sessions)* Source docs → palette/auth styling → tenant nav shell → database → onboarding flow → landlord role (setup wizard, Properties, Tenants, Inbox) → landing page rewrite → UI polish → staff management → code-simplifier pass → expandable Properties UI → tenant search → real invitation emails (+ invitation-aware registration) → Herd/Expose device testing → Announcements → multi-tenant units → unit pricing with equal-split rent.

20. **Built the tenant lifecycle**: `Lease::end()` + `Unit::resyncAfterLeaseEnded()`, a landlord "Remove tenant" flow, and a tenant-side "Leave unit" action on the dashboard. Closed the gap where occupancy could only ever increase. Also fixed the eager-load that would have kept showing terminated leases as current.
21. **Made rent changes non-retroactive**: per-lease effective dates (immediate for a lease's first rent, next due day for any change to an existing one) and a `currentRent()` that ignores future-dated rows. Used Laravel's documented `ofMany` + closure pattern for this.
22. **Reworked the Tenants page into a manage modal**: every tenant row is clickable; one modal handles assign, move-to-another-unit, and remove, with rent re-split on both the old and new unit.
23. **Added per-lease billing timing** (advance/arrears) with a segmented control in that modal, after confirming with the user that per-lease beats per-unit for PH dorm scenarios.
24. **Built invoice generation**: cycle-walking action + `invoices:generate` command + daily schedule, with first-cycle proration and advance/arrears due dates, sourcing rent from `rentEffectiveOn()`.
25. **Built the landlord Invoices ledger**: outstanding balances per tenant, drill-down into each tenant's full invoice collection (Outstanding + History), and a Record payment flow supporting partial payments.
26. **Replaced the tenant Billing stub** with a real page: current balance, next due date, outstanding invoices, paid history — scoped across all of that tenant's leases, including units they've moved out of.
27. **Committed and pushed the entire backlog** (everything since "focused on landlord functions") as two scoped commits, then deployed.
28. **Deployed to Render** after abandoning Laravel Cloud (card required). Wrote the Dockerfile + `render.yaml`, then worked through: three consecutive Docker build failures ending in vendoring pre-built assets; a queue worker that didn't exist; a single-threaded server causing health-check timeouts; Render blocking SMTP (→ Resend); Resend's sandbox recipient restriction; and a missing `APP_URL` producing localhost links in emails.
29. **Started the professor-revisions rewrite** (see that section above for full status): reviewed an 8-phase brief for feasibility before touching anything, then Phase 1 (renamed PropertEase → Occuplace, including relinking the local Herd site) and Phase 2 (Super Admin role: guarded `is_super_admin` column, artisan-only promotion command, `/admin` routes, a sidebar-layout crash fix for teamless users, landlord-only registration, role relabeling). Phase 3's plan (unit listings + payment channels + first file uploads) is written and presented, awaiting approval.

## Bugs Found & Fixed Mid-Session (worth knowing if debugging similar issues)

Carried over from earlier sessions:

1. **`URL::defaults()` / `switchTeam()` test gotcha**: `User::factory()->create()` triggers `switchTeam()`, which sets `URL::defaults(['current_team' => ...])` globally. Creating a second user later in a test silently re-points it. **Fix**: call `$landlord->switchTeam($landlord->currentTeam)` right before generating a route URL in any test creating more than one user.
2. **`wherePivotNot()` is broken** on this Laravel version — generates nonsense SQL silently. Use `wherePivot($column, '!=', $value)`.
3. Redirecting to a route under `{current_team}` needs that exact param key; `{team}` routes need `team`.
4. **`withCount` on `hasManyThrough`** can produce ambiguous-column errors. Qualify: `->where('concerns.status', ...)`.
5. **Restarting `composer run dev` silently undoes a production build** (recreates `public/hot`, so `@vite` points at a dev server that isn't running → every page loses all styling).
6. **Behind a proxy, Laravel doesn't know the request was HTTPS** without `trustProxies` → mixed content → no CSS/JS.
7. **A long-running process holds its original `.env` values.** Editing `.env` doesn't affect an already-running `queue:listen`; restart it.

New this session:

8. **A `date` cast does NOT store a date-only string.** `effective_date` is cast `'date'`, but Laravel serializes it with the connection's full datetime format, so the column holds `2026-09-21 00:00:00` (or a real time-of-day if it was set from `now()`). Comparing that against a bare `today()->toDateString()` in a query breaks at the boundary: string-wise `"2026-09-21 00:00:00" > "2026-09-21"`, so same-day rows fall on the wrong side of `<=`. **Compare against `today()->endOfDay()`** (a Carbon instance — Laravel's `prepareBindings()` formats it correctly) rather than a bare date string.
9. **Carbon is immutable app-wide** (`Date::use(CarbonImmutable::class)` in `AppServiceProvider`), so `today()` returns `CarbonImmutable`. A method typed `: Carbon` (i.e. `Illuminate\Support\Carbon`) that returns it throws a TypeError. Type date-returning methods `CarbonImmutable`.
10. **`$lease->due_day` on a null lease**: `$lease->due_day ?? 1` is not null-safe when `$lease` itself is null. Use `$lease?->due_day ?? 1`.
11. **PHPStan flags `?->` before `??` as redundant** (`nullsafe.neverNull`) in some chains. Splitting into an explicit null check (`$rent = ...; $rent ? (float) $rent->amount : 0.0`) is clearer anyway.
12. **Docker/BuildKit builds stages in parallel**, so an unrelated stage showing `CANCELED` in a failed build log is a *symptom*, not the cause — find the stage that actually failed.
13. **Render's log viewer folds long `RUN` output**, showing only the tail. If an error's real message seems missing, it's collapsed, not absent — and a JS error printed as `errors: [Getter/Setter]` means Node declined to evaluate a lazy getter, so the message is genuinely unavailable from the log at all.

## What's Deliberately NOT Built Yet (don't assume it exists)

- **Service charges and late-fee penalties on invoices** — `service_amount` and `penalty_amount` are always 0. `Service`/`ServiceSubscription` tables exist and `ServiceBillingType` has `Metered`/`Variable` cases, but there's no meter-reading input anywhere, so only `Fixed` could realistically be automated.
- **Automatic overdue marking** — derived at display time via `Invoice::isPastDue()`; no job writes `InvoiceStatus::Overdue`.
- **Automatic invoice generation in production** — the schedule exists but Render's free plan has no cron. Run `invoices:generate` manually.
- **Sending email to arbitrary recipients in production** — blocked by Resend's sandbox until a domain is verified. Works to the Resend account owner's own address only.
- **Any real payment processing** (GCash/bank integration). Payments are landlord-recorded only.
- **An outstanding-balance warning when removing/moving a tenant** — discussed and designed, not built. Since the Tenants page only shows active leases, a departing tenant's unpaid invoices currently drop out of that view (the data is intact under the ended lease, just not surfaced).
- **Truncating the final invoice when a lease ends mid-cycle** — an already-generated invoice keeps covering its full period even if the tenant leaves early. Arguably correct as a business policy, but it was never an explicit decision.
- **Tenant-side Concern submission form** — Maintenance/Complaints are still "Coming soon" stubs (Billing is real now).
- **Per-tenant custom rent amounts** — the equal split is currently forced; the user has signalled interest in unequal amounts (common in PH dorms), which would need a nullable per-lease override that the splitter skips.
- SMS/low-bandwidth fallback mode.
- Unit listings, public browse/reservation flow, and tenant self-service account provisioning — see "Professor Revisions" above for current phase status.

## How To Resume

Locally: the app is served by Herd at `http://Occuplace.test`. Run `php artisan queue:listen --tries=1 --timeout=0` if you need queued mail to send. Confirm `php artisan test --compact` is green (**188/188** as of Phase 2 of the professor revisions) and `vendor/bin/phpstan.bat analyse` is clean (0 errors). Use **PowerShell** for artisan/pint/pest/composer.

In production: pushing to `main` auto-deploys to Render. **If the change touches CSS/JS/assets, run `npm run build` and commit `public/build/` too** or production will serve stale assets. Nothing from the professor-revisions branch has been pushed to `main` yet — it all lives on `feature/professor-revisions`.

**Immediate next step:** resume the professor revisions (see that section above) on branch `feature/professor-revisions`. Phase 3's plan was presented to the user but not yet approved — re-present it (or pick up mid-implementation if it was approved after this was written) rather than assuming it's done. Read `c:\Users\Rem\Downloads\REVISIONS_PROMPT.md` first if it's not still in context.

Once the professor revisions wrap up (or if picking other work instead), natural next steps on the original feature set: the outstanding-balance warning on tenant removal/move (the designed-but-unbuilt piece that closes the "debt disappears from view" gap), per-tenant custom rent amounts, service charges on invoices, then the tenant-side Concern submission form so Inbox has real data.
