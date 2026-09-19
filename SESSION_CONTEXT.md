# PropertEase — Session Context

Paste this whole file into a new Claude Code conversation (in this repo) to restore context if a session forgets what happened.

## What PropertEase Is

A Laravel webapp automating landlord-tenant management for apartments/dormitories: rent billing, maintenance/complaint tracking, and announcements. Built from real research: a 48-respondent tenant survey (`Tenant Research Questionnaire`, collected 2026-09-09 to 2026-09-19) drives feature priority. Key findings that shaped decisions:

- 71% of tenants are satisfied with their landlord — the pain is **process** (nothing tracked), not the relationship.
- Maintenance is reported informally (Messenger 26, in-person 23, SMS 17 respondents) with zero record-keeping. Response speed is fine; traceability is the gap.
- Payment methods already in use: GCash (19), Cash (18), Bank Transfer (10), Cheque (1).
- Strongest single demand (29/47): one system to see balance, due date, payment history, and status.
- 19% of complaints got no response/follow-up.
- Adoption concerns, ranked: unreliable internet (27) > privacy/security (14) > prefers talking to the owner directly (11) > tech difficulty (8). **This is why UI stays lightweight — no heavy JS, no scroll animations.**

Source docs (outside the repo, on the user's machine): `Tenant_Survey_Analysis_and_System_Features.pdf`, a DB ERD (`physical.png`), and a "Coastal Midnight" color palette image.

## Tech Stack

- Laravel 13, PHP 8.4, **Livewire 4** using **Single-File Components** with a `⚡` filename prefix (`resources/views/pages/⚡name.blade.php` for full pages, registered via `Route::livewire()`).
- **Flux UI** (free edition) for components. Some Flux internals were published into `resources/views/flux/` for customization beyond what props allow (project convention — check there before assuming a Flux default).
- **Fortify** for auth (login/register/2FA already scaffolded by the `laravel/livewire-starter-kit`).
- **Tailwind CSS v4** — CSS-first config in `resources/css/app.css` via `@theme`, no `tailwind.config.js`.
- **Teams-based multi-tenancy** (starter-kit default, repurposed — see Architecture below).
- Pest for tests, Larastan/PHPStan for static analysis, Pint for formatting. SQLite for local dev (`database/database.sqlite`).
- Fonts via Bunny Fonts (`vite.config.js`, `bunny()` helper) — currently **Plus Jakarta Sans**.

### Environment quirks (Windows)

- PHP is managed by **Laravel Herd**, not on the Bash tool's PATH. Run `php artisan ...` / `composer ...` / `vendor\bin\pint.bat` via the **PowerShell** tool, not Bash.
- Dev server: `composer run dev` (runs `php artisan serve` + queue listener + `npm run dev` concurrently). Runs at `http://localhost:8000`.
- Standard verification loop after any change: `npm run build` (if frontend touched) → `vendor\bin\pint.bat --dirty --format agent` → `composer types:check` (Larastan) → `php artisan test --compact`.
- Killing the dev server with a broad `taskkill /IM php.exe` also kills the Laravel Boost MCP connection as a side effect (it's a separate PHP process). Prefer killing by specific PID.

## Directory Structure (what matters here)

```
app/
  Enums/            TeamRole, TeamPermission, PropertyType, UnitStatus, LeaseStatus,
                     ServiceBillingType, InvoiceStatus, PaymentMethod, DocumentType,
                     ConcernCategory, ConcernPriority, ConcernStatus
  Models/            Team, User, Membership, TeamInvitation (starter kit)
                     Property, Unit, Lease, LeaseRent, Service, ServiceSubscription,
                     Invoice, Payment, Document, Concern, ConcernUpdate, Announcement (ours)
  Concerns/HasTeams.php   Team-membership helpers on User, incl. isLandlordOn(Team)
  Http/Middleware/
    EnsureTeamMembership.php   Gates {current_team}/{team} routes; accepts a
                                minimum-role param, e.g. ':member' blocks Tenant role
    SetTeamUrlDefaults.php     Sets URL::defaults(current_team) from the authed user

database/
  migrations/        12 new tables (see Architecture) + Laravel's notifications table
  factories/          One factory per new model, realistic Philippine-context fake data

resources/
  css/app.css        Tailwind v4 @theme block. Brand tokens + global --color-accent
  views/
    welcome.blade.php              Public landing page (rewritten, see below)
    dashboard.blade.php            DELETED — replaced by pages/⚡dashboard.blade.php
    layouts/
      auth/simple.blade.php        Auth page shell (login/register/etc.)
      app/sidebar.blade.php        Main authenticated app shell (role-aware nav)
    components/
      nav-card.blade.php           Reusable "go to this feature" card (Home page)
      feature-preview.blade.php    Reusable "Coming soon" panel w/ icon + bullet list
      app-logo-icon.blade.php      The abstract logo mark (recolored via brand tokens)
    flux/sidebar/item.blade.php    Published Flux override — enlarged nav items
    pages/
      ⚡dashboard.blade.php         Home. Role-aware: redirects landlord w/ 0 properties
                                    to /setup; shows different nav cards per role
      ⚡billing.blade.php ⚡maintenance.blade.php ⚡complaints.blade.php
      ⚡announcements.blade.php     Tenant-facing. All "Coming soon" — no backing UI yet
      landlord/
        ⚡setup.blade.php           4-step onboarding wizard (Property → Units →
                                    Invite tenant → Done). Also doubles as "add
                                    another property" (Properties page links here)
        ⚡properties.blade.php      Real listing: properties + units, inline add-unit
        ⚡tenants.blade.php         Real listing: tenant roster, assign vacant unit
                                    + rent + due day → creates Lease + LeaseRent
        ⚡inbox.blade.php           Read-only Concerns feed, team-scoped
      teams/
        ⚡edit.blade.php            Settings > Teams. STAFF-ONLY table (tenants
                                    excluded), landlord-gated, real <flux:table>
        ⚡invite-member-modal.blade.php   Shared invite modal — takes team,
                                    defaultRole, roles[], redirectTo props

routes/
  web.php            '/' welcome; {current_team}/{dashboard,billing,maintenance,
                     complaints,announcements} open to any team role;
                     {current_team}/{setup,properties,tenants,inbox} gated ':member'
  settings.php       settings/teams/{team} (teams.edit) now gated ':member' too

tests/Feature/
  Landlord/          SetupWizardTest, PropertiesPageTest, TenantsPageTest, InboxPageTest
  Teams/              TeamTest, TeamMemberTest, TeamInvitationTest (extended)
  DashboardTest.php   Role-aware assertions
  PropertyManagementModelsTest.php   Cross-model relationship + cascade-delete tests
  BillingTest.php MaintenanceTest.php ComplaintsTest.php AnnouncementsTest.php
```

## Architecture & Rules Established

**Team = one landlord's account**, not "the people running PropertEase." A Team can own many Properties. This was a point of real user confusion — see "Decisions & Corrections" below.

**Roles** (`App\Enums\TeamRole`, ordered by `level()`): `Owner` (3) > `Admin` (2) > `Member` (1) > `Tenant` (0). Landlord staff are Owner/Admin/Member. Tenants are `Tenant` role — same `users`/`team_members` tables, lowest privilege, attached to a team via the **same invite system** as staff (`TeamInvitation`, extended to support role-specific invite flows).

**Route gating pattern**: any landlord-only route uses
`Route::middleware(EnsureTeamMembership::class.':member')`
which requires `role->isAtLeast(Member)` — Tenant (level 0) fails this and gets a real 403. Tenant-facing routes (billing/maintenance/complaints/announcements) have no such restriction (open to any team role currently).

**Domain model** (12 tables, all under a Team indirectly via Property):
`Property` (belongs to Team) → `Unit` (belongs to Property) → `Lease` (belongs to Unit, belongs to tenant `User`) → `Invoice` (belongs to Lease) → `Payment` (belongs to Invoice). `LeaseRent` is a rent-amount-over-time history table on Lease. `Service`/`ServiceSubscription` model per-team billable extras (water/electricity/internet). `Concern` (belongs to Lease, `category` enum Maintenance|Complaint) → `ConcernUpdate` (belongs to Concern + author `User`). `Announcement` belongs to Property + author `User`. `Document` belongs to Lease (contracts/IDs — NOT used for maintenance photos, which live on `Concern.photo_path` directly).

**Deviations from the original ERD** (deliberate, documented at build time): merged `TenantUser`/`LandlordUser` into the existing `users` + `team_members` tables (per explicit user decision — see below); dropped custom `TenantNotifications`/`LandlordNotifications` tables in favor of Laravel's built-in polymorphic `notifications` table (User already has the `Notifiable` trait); `Unit.bedrooms/bathrooms` are real integers, not the ERD's loose `VARCHAR`; Lease's ambiguous `Due_Date:INT` became `due_day` (1–28, day of month); dropped manual `_CreatedAt`/`_PostedAt` columns for standard `timestamps()`; added `author_id` to `ConcernUpdate`/`Announcement` (missing from ERD, needed for accountability).

## Design System

**Palette — "Coastal Midnight"** (`resources/css/app.css`, `@theme` block):
- `--color-brand-900: #061222` — dominant background (~60% of any screen)
- `--color-brand-800: #123249` — card/panel surfaces (~30%)
- `--color-brand-600: #2d5b75` — secondary accent
- `--color-brand-500: #447794` — **primary accent**, used app-wide now (buttons, focus rings, links) via `--color-accent` in the global `.dark` theme override — not just auth pages, that was a bug fixed mid-session (see below)

**Font**: Plus Jakarta Sans (changed from Instrument Sans — chosen to read less neutral/generic, warmer for a product tenants with lower tech comfort need to trust).

**Contrast**: verified numerically (not eyeballed) with a small Node script computing WCAG relative luminance. `text-zinc-400` on `brand-900`/`brand-800` passes AA (7.33:1 / 5.19:1). `text-zinc-500` on `brand-900` FAILS (3.89:1) — caught once on the landing page footer, already fixed to `zinc-400`. **If adding new text on brand-900/800, check zinc-500 and darker against WCAG AA before shipping.**

**antislop skill is active in "during" mode** for this session (user chose this explicitly via `/antislop`) — apply its rules (no em dashes, no fabricated stats/testimonials, real nav destinations only, honest empty/"Coming soon" states, contrast-checked, etc.) while building UI, not as an afterthought.

## Chronological Build Log

1. **Read source docs** (ERD photo, survey PDF, palette image) to establish product direction.
2. **Styled login/register** with the Coastal Midnight palette (60/30/10), set `APP_NAME=PropertEase`.
3. **Built tenant navigation shell**: Home + Billing/Maintenance/Complaints/Announcements as real routes with honest "Coming soon" content (no backend yet) — deliberately scoped to navigation only per explicit user instruction that round.
4. **Built the database** — see Architecture above. User explicitly chose "extend existing Team/User login system" over "build TenantUser/LandlordUser exactly as drawn" when asked directly.
5. **Discussed onboarding flow** (exploratory): landlords self-register (existing flow), tenants get invited by the landlord (not self-serve) — user agreed, then asked for a guided setup wizard.
6. **Built landlord role**: setup wizard, Properties, Tenants, Inbox pages (see Directory Structure), role-aware Home/sidebar. Announcements-broadcast (landlord composing) and tenant-side Concern submission were explicitly **not** built this round — scoped out, mentioned as follow-up.
7. **Rebuilt the public landing page** (`welcome.blade.php`) — removed the default Laravel starter-kit branding entirely, wrote copy grounded in the survey findings, no fabricated claims.
8. **UI polish round**: user flagged the sidebar's "+ New team" option as confusing (correctly — not needed in our model), flagged the UI as looking generic/ChatGPT-like. Fixed: removed "New team" (collapsed switcher to static label when only 1 team), removed generic starter-kit "Repository"/"Documentation" links, changed font, enlarged sidebar nav items, extended the blue accent app-wide (previously only on auth pages).
9. **Deferred**: a platform-admin account type (for PropertEase's own operators, above all landlord Teams) — explicitly tabled by the user ("not yet, focus on improving the system first").
10. **Built staff/team-member management**: clarified Settings > Teams already had most of this; user confirmed scope (staff-only table, not tenants — tenants already have their own page); gated the route to landlords; converted to a real `<flux:table>`; extended the shared invite modal to support context-specific roles and redirects.

## Bugs Found & Fixed Mid-Session (worth knowing if debugging similar issues)

1. **`URL::defaults()` / `switchTeam()` test gotcha**: `User::factory()->create()` triggers `switchTeam()` in its `afterCreating` hook, which calls `URL::defaults(['current_team' => ...])` as a side effect — globally, regardless of authentication. This means `route('some-team-scoped-route')` only works in a test if **some** user was created (even an unrelated one) after the last relevant `switchTeam()` call. Creating a second/third user later in a test (e.g., a tenant) silently re-points the global default at *that* user's own personal team. **Fix pattern**: call `$landlord->switchTeam($landlord->currentTeam)` explicitly right before generating a route URL in any test that creates more than one user.
2. **`wherePivotNot()` is broken on this Laravel version** — it doesn't resolve as the real Eloquent method; falls through to a dynamic-where magic-method fallback and generates nonsense SQL (`where "pivot_not" = ?`), silently matching nothing. **Use `wherePivot($column, '!=', $value)` instead.** Confirmed via a throwaway tinker script comparing generated SQL before/after.
3. Redirecting after an action to a route under `{current_team}` requires the `current_team` param key specifically; a route under a plain `{team}` param (like `teams.edit`) needs `team`. Passing the wrong key either 404s or silently becomes a stray query-string parameter. The shared invite-member-modal now picks the right key based on its `redirectTo` prop.

## What's Deliberately NOT Built Yet (don't assume it exists)

- Tenant-side Concern (maintenance/complaint) **submission form** — Billing/Maintenance/Complaints/Announcements pages are still "Coming soon" placeholders for the tenant.
- Landlord Announcements **composer** (posting/broadcasting) — Inbox and the tenant Announcements feed are both read-only/placeholder right now.
- Any actual payment processing (GCash/bank integration) — Invoice/Payment tables exist, no UI writes to them yet outside factories/tests.
- A platform-admin account type above landlord Teams — explicitly deferred.
- SMS/low-bandwidth fallback mode (mentioned in the survey as a trust factor, not yet addressed).

## How To Resume

If picking this up fresh: run `composer run dev` (or ask the user to), confirm `php artisan test --compact` is green (currently 106/106), and check with the user which of the "not built yet" items to tackle next — the natural next step is wiring the tenant Concern submission form and/or the landlord Announcements composer, since Inbox and the tenant feed are currently empty by construction.
