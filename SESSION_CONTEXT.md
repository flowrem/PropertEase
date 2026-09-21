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
- **Fortify** for auth (login/register/2FA already scaffolded by the `laravel/livewire-starter-kit`). Registration is now invitation-aware — see Architecture below.
- **Tailwind CSS v4** — CSS-first config in `resources/css/app.css` via `@theme`, no `tailwind.config.js`.
- **Teams-based multi-tenancy** (starter-kit default, repurposed — see Architecture below).
- Pest for tests (136 passing as of this session), Larastan/PHPStan for static analysis, Pint for formatting. SQLite for local dev (`database/database.sqlite`).
- Fonts via Bunny Fonts (`vite.config.js`, `bunny()` helper) — currently **Plus Jakarta Sans**.
- **Mail is real**, not the `log` driver: `.env` is configured for Gmail SMTP (`MAIL_MAILER=smtp`, `smtp.gmail.com:587`, the user's own account + an app password). Tenant invitation emails actually deliver now.

### Environment quirks (Windows)

- PHP is managed by **Laravel Herd**, not on the Bash tool's PATH. From Bash, use the `.bat` wrappers directly (`composer.bat`, `php.bat`) — plain `composer`/`php` resolve to nothing. PowerShell resolves them natively and is preferred for `artisan`/`pint`/`pest` one-offs.
- **The project is now `herd link`ed** and served continuously at `http://PropertEase.test` by Herd's own nginx — you do not need to run `php artisan serve` for local access. `herd link` also auto-updated `APP_URL` in `.env` and added a "Laravel Herd" rules block to this project's `CLAUDE.md`.
- **`composer run dev` (server + queue + `npm run dev`) still works but is now mostly redundant** for the web-serving piece since Herd already serves the app. Its bundled `npm run dev` recreates `public/hot`, which forces Laravel's `@vite` directive back into unbundled dev-server asset references — fine if Vite's dev server is actually running, but if you only need the queue worker, prefer running `php artisan queue:listen --tries=1 --timeout=0` standalone and leave `public/hot` deleted (production build in `public/build/`) so pages stay styled.
- **Real device (phone) testing requires a public tunnel** — `http://PropertEase.test` only resolves via Herd's local DNS on this machine. Use `herd share` (backed by Expose): the interactive device-code browser login can hang indefinitely — if so, grab the token from the account's Expose dashboard and run `expose token <token>` directly instead. Expose's **free plan caps each tunnel at ~1 hour with a 60-minute cooldown** before starting a new one. While tunneling: point `APP_URL` at the tunnel's `https://...` URL (mail links and `asset()`/`url()` calls need it), and **restart the queue worker** after changing `APP_URL` — a long-running `queue:listen` process keeps using whatever env values it booted with, because phpdotenv won't override an already-exported OS env var, so editing `.env` alone doesn't affect it. Revert `APP_URL` to `http://PropertEase.test` and restart the worker again once done.
- **`bootstrap/app.php` now trusts all proxies** (`$middleware->trustProxies(at: '*')`). Without this, Laravel doesn't know a request arriving through a reverse proxy/tunnel was originally HTTPS, so `asset()`/`url()` emit `http://` on an `https://` page — browsers silently block those as mixed content, so the page loads but zero CSS/JS applies (looks like a totally unstyled app, giant raw SVG icons, browser-default fonts).
- Killing the dev server with a broad `taskkill /IM php.exe` also kills the Laravel Boost MCP connection as a side effect (it's a separate PHP process). Prefer killing by specific PID. Separately, stopping one background Bash task in this environment has occasionally killed an unrelated sibling background task sharing the same console/process group — double check other running processes after stopping one.

## Directory Structure (what matters here)

```
app/
  Enums/            TeamRole, TeamPermission, PropertyType, UnitStatus, LeaseStatus,
                     ServiceBillingType, InvoiceStatus, PaymentMethod, DocumentType,
                     ConcernCategory, ConcernPriority, ConcernStatus
  Models/            Team, User, Membership, TeamInvitation (starter kit)
                     Property, Unit, Lease, LeaseRent, Service, ServiceSubscription,
                     Invoice, Payment, Document, Concern, ConcernUpdate, Announcement (ours)
                     Unit: allows_multiple_tenants, tenant_limit, price + helpers
                     hasRoomForAnotherTenant(), rentSharePerTenant(), splitRentAmongActiveTenants()
                     Lease: currentInvoice(), currentRent() (latestOfMany pattern)
  Actions/
    Teams/CreateTeam.php            Creates a team + Owner membership, used for personal teams
    Teams/AcceptTeamInvitation.php  Joins a user to an invitation's team (role + accepted_at + switchTeam);
                                     shared by the pending-invitations modal and registration
    Fortify/CreateNewUser.php       Invitation-aware: joins the inviting team directly when registering
                                     through a valid, matching pending invitation (no personal team)
  Notifications/Teams/TeamInvitation.php   Role-aware copy: tenants get "become a tenant under
                                            :team", staff get "join :team" (no more doubled "team team")
  Concerns/HasTeams.php   Team-membership helpers on User, incl. isLandlordOn(Team)
  Http/Middleware/
    EnsureTeamMembership.php   Gates {current_team}/{team} routes; accepts a
                                minimum-role param, e.g. ':member' blocks Tenant role
    SetTeamUrlDefaults.php     Sets URL::defaults(current_team) from the authed user

database/
  migrations/        12 original tables (see Architecture) + Laravel's notifications table,
                     plus later additions: units.allows_multiple_tenants, units.tenant_limit, units.price
  factories/          One factory per new model, realistic Philippine-context fake data

resources/
  css/app.css        Tailwind v4 @theme block. Brand tokens + global --color-accent
  views/
    welcome.blade.php              Public landing page (rewritten, see below)
    dashboard.blade.php            DELETED — replaced by pages/⚡dashboard.blade.php
    layouts/
      auth/simple.blade.php        Auth page shell (login/register/etc.)
      app/sidebar.blade.php        Main authenticated app shell (role-aware nav, now
                                    includes Announcements in the landlord section)
    components/
      nav-card.blade.php           Reusable "go to this feature" card (Home page)
      feature-preview.blade.php    Reusable "Coming soon" panel w/ icon + bullet list
      app-logo-icon.blade.php      The abstract logo mark (recolored via brand tokens) —
                                    it's an isometric cube/hexagon line-art shape, looks like
                                    an abstract squiggle at nav size but renders as a large
                                    geometric pattern if a size class is ever missing
      team-invitation-alert.blade.php   Shown on login/register when ?invitation=code is
                                        present; copy fixed to drop a doubled "team team"
    flux/sidebar/item.blade.php    Published Flux override — enlarged nav items
    pages/
      ⚡dashboard.blade.php         Home. Role-aware: redirects landlord w/ 0 properties
                                    to /setup; shows different nav cards per role (landlord
                                    now also gets an Announcements card)
      ⚡billing.blade.php ⚡maintenance.blade.php ⚡complaints.blade.php
                                    Tenant-facing. Still "Coming soon" — no backing UI yet
      ⚡announcements.blade.php     REAL now, role-aware in one page (not tenant-only):
                                    landlord composes (pick a property or "All properties",
                                    which creates one Announcement row per property) and can
                                    delete (confirm modal); tenant sees a read-only feed
                                    scoped to their own property via their active lease
      landlord/
        ⚡setup.blade.php           4-step onboarding wizard (Property → Units →
                                    Invite tenant → Done). Also doubles as "add
                                    another property" (Properties page links here)
        ⚡properties.blade.php      Expand/collapse per property (click header); each unit
                                    shows tenant name(s), payment status, open-concern count
                                    (links to Inbox), and price. Inline add/edit unit form
                                    incl. Single/Multiple-tenant occupancy radio, tenant
                                    limit (required when Multiple), and monthly rent (₱)
        ⚡tenants.blade.php         Real listing: tenant roster + search/filter (name, email,
                                    property, unit), assign vacant/available unit → creates
                                    Lease + auto-splits rent across all active tenants on
                                    that unit; shows each tenant's current rent
        ⚡inbox.blade.php           Read-only Concerns feed, team-scoped
      teams/
        ⚡edit.blade.php            Settings > Teams. STAFF-ONLY table (tenants
                                    excluded), landlord-gated, real <flux:table>
        ⚡invite-member-modal.blade.php   Shared invite modal — takes team,
                                    defaultRole, roles[], redirectTo props
        ⚡pending-invitations-modal.blade.php   Accept/decline; accept now delegates to
                                    App\Actions\Teams\AcceptTeamInvitation

routes/
  web.php            '/' welcome; {current_team}/{dashboard,billing,maintenance,
                     complaints,announcements} open to any team role;
                     {current_team}/{setup,properties,tenants,inbox} gated ':member'
  settings.php       settings/teams/{team} (teams.edit) now gated ':member' too

tests/Feature/
  Landlord/          SetupWizardTest, PropertiesPageTest, TenantsPageTest, InboxPageTest
  Teams/              TeamTest, TeamMemberTest, TeamInvitationTest (extended)
  Auth/RegistrationTest.php   Extended: invitation-aware registration (joins team directly,
                     falls back to personal team on email mismatch)
  DashboardTest.php   Role-aware assertions
  PropertyManagementModelsTest.php   Cross-model relationship + cascade-delete tests
  AnnouncementsTest.php   Real feature now: compose/delete/visibility/authorization/isolation
  BillingTest.php MaintenanceTest.php ComplaintsTest.php   Still placeholder-page tests
```

## Architecture & Rules Established

**Team = one landlord's account**, not "the people running PropertEase." A Team can own many Properties. This was a point of real user confusion — see "Decisions & Corrections" below.

**Roles** (`App\Enums\TeamRole`, ordered by `level()`): `Owner` (3) > `Admin` (2) > `Member` (1) > `Tenant` (0). Landlord staff are Owner/Admin/Member. Tenants are `Tenant` role — same `users`/`team_members` tables, lowest privilege, attached to a team via the **same invite system** as staff (`TeamInvitation`, extended to support role-specific invite flows).

**Route gating pattern**: any landlord-only route uses
`Route::middleware(EnsureTeamMembership::class.':member')`
which requires `role->isAtLeast(Member)` — Tenant (level 0) fails this and gets a real 403. Tenant-facing routes (billing/maintenance/complaints/announcements) have no such route-level restriction (open to any team role); `⚡announcements.blade.php` instead branches its own UI/authorization internally based on `isLandlordOn()`, same pattern as the dashboard.

**Domain model** (12 original tables, all under a Team indirectly via Property):
`Property` (belongs to Team) → `Unit` (belongs to Property) → `Lease` (belongs to Unit, belongs to tenant `User`) → `Invoice` (belongs to Lease) → `Payment` (belongs to Invoice). `LeaseRent` is a rent-amount-over-time history table on Lease. `Service`/`ServiceSubscription` model per-team billable extras (water/electricity/internet). `Concern` (belongs to Lease, `category` enum Maintenance|Complaint) → `ConcernUpdate` (belongs to Concern + author `User`). `Announcement` belongs to Property + author `User`. `Document` belongs to Lease (contracts/IDs — NOT used for maintenance photos, which live on `Concern.photo_path` directly).

**Unit now also carries occupancy/pricing fields added this session**: `allows_multiple_tenants` (bool, default false), `tenant_limit` (nullable int, required when the former is true), `price` (nullable decimal — the unit's total monthly rent). See "Multi-tenant units" and "Rent lives on the Unit" below.

**Deviations from the original ERD** (deliberate, documented at build time): merged `TenantUser`/`LandlordUser` into the existing `users` + `team_members` tables (per explicit user decision — see below); dropped custom `TenantNotifications`/`LandlordNotifications` tables in favor of Laravel's built-in polymorphic `notifications` table (User already has the `Notifiable` trait); `Unit.bedrooms/bathrooms` are real integers, not the ERD's loose `VARCHAR`; Lease's ambiguous `Due_Date:INT` became `due_day` (1–28, day of month); dropped manual `_CreatedAt`/`_PostedAt` columns for standard `timestamps()`; added `author_id` to `ConcernUpdate`/`Announcement` (missing from ERD, needed for accountability).

**Multi-tenant units are opt-in per unit, not global.** A unit is assignable to a new tenant if it's genuinely vacant, OR `allows_multiple_tenants` is true and its active-lease count is below `tenant_limit`. Enforced twice: once filtering the "assign a tenant" dropdown (`Tenants` page `vacantUnits()`), and again server-side inside `assignUnit()` (`abort_unless($unit->hasRoomForAnotherTenant(), 403)`) so a tampered request can't bypass a full unit. `Unit::hasRoomForAnotherTenant()` is the single source of truth for this check.

**Rent lives on the Unit as one `price`, not per-lease.** Every active tenant on a unit gets an equal share (`Unit::rentSharePerTenant()` = `price / active lease count`), recorded as a fresh `LeaseRent` row (history preserved, not mutated) via `Unit::splitRentAmongActiveTenants()`. This runs whenever a tenant is assigned to the unit, or whenever the landlord edits the unit's price on the Properties page — so everyone currently living there gets recalculated automatically, not just the new arrival.

**Registering through a valid pending invitation skips the personal-team detour.** Previously every new user (including one who clicked an invite email) got their own personal Team first (Jetstream-style), and had to separately accept the invitation afterward via a dashboard modal. `App\Actions\Fortify\CreateNewUser` now checks the submitted `invitation` code (hidden field on the register form) against a pending `TeamInvitation` matching the submitted email; if found, `AcceptTeamInvitation` joins that team directly (role from the invitation, no personal team, marks it accepted) in the same request as account creation. Falls back to the old personal-team behavior for plain public signups, or if the invitation is missing/expired/already-accepted/email-mismatched (defensive, not an error state). The register page also locks the email field to the invited address when a valid invitation is present.

**Announcements are per-Property, not per-Team.** Posting to "All properties" creates one `Announcement` row per property the landlord owns (not one team-wide row), so each property's own feed genuinely contains it independently. A tenant only ever sees announcements for the property tied to their own active lease (via `whereHas('unit.property', ...)` scoping), and sees nothing (clean empty state) if they have no unit yet.

## Design System

**Palette — "Coastal Midnight"** (`resources/css/app.css`, `@theme` block):
- `--color-brand-900: #061222` — dominant background (~60% of any screen)
- `--color-brand-800: #123249` — card/panel surfaces (~30%)
- `--color-brand-600: #2d5b75` — secondary accent
- `--color-brand-500: #447794` — **primary accent**, used app-wide now (buttons, focus rings, links) via `--color-accent` in the global `.dark` theme override — not just auth pages, that was a bug fixed mid-session (see below)

**Font**: Plus Jakarta Sans (changed from Instrument Sans — chosen to read less neutral/generic, warmer for a product tenants with lower tech comfort need to trust).

**Contrast**: verified numerically (not eyeballed) with a small Node script computing WCAG relative luminance. `text-zinc-400` on `brand-900`/`brand-800` passes AA (7.33:1 / 5.19:1). `text-zinc-500` on `brand-900` FAILS (3.89:1) — caught once on the landing page footer, already fixed to `zinc-400`. **If adding new text on brand-900/800, check zinc-500 and darker against WCAG AA before shipping.**

**antislop skill is active in "during" mode** for this project (user chose this explicitly via `/antislop`) — apply its rules (no em dashes, no fabricated stats/testimonials, real nav destinations only, honest empty/"Coming soon" states, contrast-checked, etc.) while building UI, not as an afterthought.

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
11. **Ran the app for the first time this session** — sorted out the Windows/Herd `.bat`-wrapper quirk for the Bash tool, got `composer run dev` running.
12. **Code-simplifier cleanup pass** on the previous session's landlord code: enums gained `color()` methods, fixed an N+1 on the Tenants page, removed a dead `mount()`, and fixed a real security gap (the setup wizard's unscoped `Property::find($this->propertyId)` let a landlord point the wizard at another team's property — now scoped through `$this->team->properties()`).
13. **Built the expandable Properties UI**: click a property to reveal its units; each unit shows current tenant name(s), payment status (new `Lease::currentInvoice()`), open-concern count linking to Inbox, and an inline edit form — grounded directly in the survey's #1 finding (balance/due-date/status visibility) and the traceability gap.
14. **Added tenant search/filter** on the Tenants page (name, email, property, or unit) — chosen over full grouping-by-property as the lighter-weight fix, since most landlords in the survey's demographic manage only 1–3 properties.
15. **Wired up real tenant invitation emails.** The invite feature already existed but only wrote to the `log` driver; configured real Gmail SMTP in `.env`. Along the way: fixed a "join the :team team" doubled-word copy bug and made the copy role-aware (tenant vs staff); fixed a mixed-content bug blocking all CSS/JS behind an HTTPS tunnel (`trustProxies`); simplified registration so accepting an invitation joins the team directly instead of creating a throwaway personal team first (see Architecture).
16. **Set up Herd linking + Expose tunneling** to test the full invite-by-email flow from an actual phone end to end, working through Expose free-tier quirks (hanging device-code login → use `expose token` directly; 1-hour cap + 60-minute cooldown between tunnels).
17. **Built the Announcements feature**: landlord composes (one property or all) and can delete (confirm modal); tenant sees a read-only feed scoped to their own property. Reused the same doubled-word copy fix.
18. **Added multi-tenant units**: opt-in per unit via a Single/Multiple-tenant radio plus a required tenant limit when Multiple is chosen; assignment flow and the Properties display both updated to handle more than one active lease per unit, with a defensive server-side check against a full unit.
19. **Added unit pricing with automatic equal-split rent**: landlords set one `price` per unit; every active tenant's rent is recalculated to an equal share whenever someone joins or the price changes, shown live as a preview during assignment and confirmed afterward on the Tenants page.

## Bugs Found & Fixed Mid-Session (worth knowing if debugging similar issues)

1. **`URL::defaults()` / `switchTeam()` test gotcha**: `User::factory()->create()` triggers `switchTeam()` in its `afterCreating` hook, which calls `URL::defaults(['current_team' => ...])` as a side effect — globally, regardless of authentication. This means `route('some-team-scoped-route')` only works in a test if **some** user was created (even an unrelated one) after the last relevant `switchTeam()` call. Creating a second/third user later in a test (e.g., a tenant) silently re-points the global default at *that* user's own personal team. **Fix pattern**: call `$landlord->switchTeam($landlord->currentTeam)` explicitly right before generating a route URL in any test that creates more than one user.
2. **`wherePivotNot()` is broken on this Laravel version** — it doesn't resolve as the real Eloquent method; falls through to a dynamic-where magic-method fallback and generates nonsense SQL (`where "pivot_not" = ?`), silently matching nothing. **Use `wherePivot($column, '!=', $value)` instead.** Confirmed via a throwaway tinker script comparing generated SQL before/after.
3. Redirecting after an action to a route under `{current_team}` requires the `current_team` param key specifically; a route under a plain `{team}` param (like `teams.edit`) needs `team`. Passing the wrong key either 404s or silently becomes a stray query-string parameter. The shared invite-member-modal now picks the right key based on its `redirectTo` prop.
4. **`withCount` on a `hasManyThrough` can produce an ambiguous-column SQL error** when the filter closure references a column name (like `status`) that exists on both the through-table and the target table — SQLite (and others) can't tell which `status` you mean. Qualify it: `->where('concerns.status', ...)` not `->where('status', ...)`.
5. **Restarting a `composer run dev`-style script silently undoes a production build.** Its bundled `npm run dev` recreates `public/hot` on every restart — even if you deleted it and ran `npm run build` moments earlier — because Vite's normal cleanup hook (which removes `public/hot` on graceful shutdown) doesn't fire when the parent process is killed via a hard stop. The app then silently falls back to unbundled dev-server asset references with no dev server actually listening: every page loses all styling (raw oversized SVG icons, browser-default fonts), which looks like a rendering bug but is a config/process-lifecycle issue.
6. **Behind a reverse proxy or tunnel, Laravel doesn't know the original request was HTTPS** unless proxies are trusted. Without `bootstrap/app.php`'s `$middleware->trustProxies(at: '*')`, `asset()`/`url()` emit `http://` even when the outer connection is `https://` — browsers silently block those as mixed content, so the page itself loads but zero CSS/JS applies.
7. **A long-running process holds onto its original `.env` values.** `queue:listen` (or any other process started before an `.env` edit) keeps using whatever it booted with for every job it processes, because child processes it spawns inherit its already-exported OS environment, and phpdotenv refuses to overwrite an environment variable that's already set. Editing `.env` alone does not affect an already-running worker — it must be restarted.

## What's Deliberately NOT Built Yet (don't assume it exists)

- Tenant-side Concern (maintenance/complaint) **submission form** — Billing/Maintenance/Complaints pages are still "Coming soon" placeholders for the tenant.
- Any actual payment processing (GCash/bank integration) — Invoice/Payment tables exist, no UI writes to them yet outside factories/tests.
- **Automated Invoice generation from `LeaseRent`** — rent tracking and equal-splitting across tenants is now real (`LeaseRent`), but nothing turns that into billing-cycle `Invoice` rows yet; that's still a manual/factory-only concept.
- **Removing/unassigning a tenant from a unit** — you can assign, but there's no "end this lease" flow, so `hasRoomForAnotherTenant()`/occupancy counts can only go up during a session, never back down through the UI.
- A platform-admin account type above landlord Teams — explicitly deferred.
- SMS/low-bandwidth fallback mode (mentioned in the survey as a trust factor, not yet addressed).
- Production-grade tunnel/hosting story — real device testing currently depends on Expose's free tier (1-hour sessions, 60-minute cooldowns); nothing has been deployed to Laravel Cloud yet.

## How To Resume

If picking this up fresh: the app is already served by Herd at `http://PropertEase.test` — no need to run anything to view it locally. Run `php artisan queue:listen --tries=1 --timeout=0` (or `composer run dev` if you also want Vite's HMR) if you need queued mail to actually send. Confirm `php artisan test --compact` is green (currently 136/136), and check with the user which of the "not built yet" items to tackle next — the natural next steps are automated Invoice generation from `LeaseRent`, an unassign/end-lease flow (now that occupancy can only increase), and/or the tenant-side Concern submission form, since Inbox is currently populated only by test/seed data.
