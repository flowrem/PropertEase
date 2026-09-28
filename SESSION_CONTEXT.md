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

**The app is deployed and publicly live** at **`https://occuplace.org`** (custom domain since 2026-09-28; `https://occuplace.onrender.com` still works; Render free tier, Supabase for database and storage). The old service `propertease` (`propertease-wv4j.onrender.com`) was replaced by a hand-created `occuplace` service on 2026-09-24. See the Deployment section — it has its own set of hard-won gotchas.

## Professor Revisions Round 2 — IN PROGRESS (resume here)

Implementing `c:\Users\Rem\Downloads\PROPERTY_REVISIONS_PROMPT.md` (**not in the repo** — read it in full before resuming), a 10-phase brief ("Professor Revisions, Round 2") built from `Additional-system-functions.pdf` plus the professor's note about input limits. Branch **`feature/property-revisions`**, never `main`. Same ground rules as Round 1 (below): plan first and wait for approval at the start of each phase, stop and summarize at the end, additive migrations only, one commit per logical step, suite + PHPStan + Pint green, server-side enforcement with a test for every locked field or limit, no new dependencies without asking.

**Progress:**
- ✅ **Phase 1 (property and unit structure, locked fields, floor area, capacity)** — done, committed. Property type locked after creation; an address edit sends the property's Approved listings back to `PendingReview`. Units got `floor_area_sqm` (nullable decimal); floor level (picked from `Unit::floorLevelOptions()`), bedrooms, bathrooms and floor area lock once saved (`Unit::hasLockedDetails()` = floor area is set), with a confirm modal before saving (`<x-confirm-unit-modal>`) and read-only display afterwards. Legacy units without a floor area are completed once, and their listings can't be submitted until then. Limits live in `config/occuplace.php`. **The user lowered the floor area maximum from the brief's 300 to 70 m²** (commit `c1966d0`).
- **Capacity rules, as they stand now** (`Unit::maxCapacityFor(floorArea, type, bedrooms, bedSpaces)`, always capped by `floor(area ÷ area_per_tenant)` and `max_capacity` 20, never below 1):
  1. **Beds listed** → capacity = what the beds sleep (`Σ quantity × amenities.sleeps`). Chosen by the user on 2026-09-27 ("beds decide capacity") to answer "where does each tenant sleep?".
  2. **No beds listed** (unfurnished, tenants bring their own) → bedroom estimate: `bedrooms × tenants_per_bedroom` (2 for apartment/condo/rental home, 4 for dorm/boarding house; the user's numbers).
  3. **Studio/bedspace (0 bedrooms) with no beds** → floor area alone.
  - Room checks set aside a **6 m² common area** (kitchen/living, `common_area` config) whenever bedrooms ≥ 1, plus at least one bathroom's 1.2 m² even when bathrooms = 0. So a 24 m² unit fits at most 2 bedrooms. Bathrooms are capped at bedrooms + 1.
  - `capacity()`, `hasRoomForAnotherTenant()`, `scopeHasRoom()` (SQL twin, uses the `BED_SPACES_SQL` subquery constant) and `slotsAvailable()` are still the single rule; change them together. List queries eager-load the bed total with `Unit::withBedSpaces()` (same pattern as `withCount`), otherwise `bedSpaces()` queries per unit.
- ✅ **Phase 2 (amenities module)** — done, committed.
  - Tables `amenities` (`team_id` nullable = platform default, `name`, `category` string + `AmenityCategory` enum, `sleeps`, `is_active`) and `amenity_unit` (`quantity` 1–20). 37 platform defaults are seeded **by a migration** (`2026_09_27_085939_seed_default_amenities`) so production gets them on deploy; beds: Single bed sleeps 1, Double deck 2, Double bed 2; everything else 0 (includes TV, gas/electric stove, induction cooker, washing machine, Wi-Fi, CCTV...).
  - **`sleeps` is deliberately NOT fillable**: custom amenities always sleep 0, so a landlord can't invent a "bed that sleeps 10". New bed types go into the platform list.
  - Unit form (Properties page + setup wizard, shared `UnitForm` + `<x-unit-form-fields>`): checkboxes grouped by category (`Amenity::groupByCategory()`), quantity per ticked item. Only platform + the team's own active amenities are accepted (`Amenity::availableTo($team)`); an inactive one already on the unit stays selectable. Removing beds is refused when fewer would no longer sleep the tenants + reservations already holding the unit (only when the edit shrinks capacity, so already-over-capacity legacy units can still be edited).
  - Once any bed is ticked the form says "Counting only the ticked beds: 2 double decks and 1 single bed sleep 5 people. Tick every bed in the unit..." (ticking the first bed switches from the bedroom estimate and can LOWER capacity; the note explains it). A shared unit's tenant limit **follows the maximum** when beds change while it is empty or sits at the old maximum; a limit set lower on purpose stays unless the new maximum forbids it (`UnitForm::followMaxCapacity()`, driven by the pages' `updating()`/`updated()` hooks).
  - `{team}/amenities` page (`amenities` route, sidebar item after Properties): landlords add their own (name unique within the team's list incl. platform defaults, case-insensitive) and deactivate/reactivate; Staff view-only; `AmenityPolicy`.
  - Public: listing page shows "What's included" with quantities; browse has an Amenities filter (plain `<details>`, no JS) matching units that have ALL ticked amenities, platform amenities only.
  - **Quantity limits** (added 2026-09-27 after the user's screenshot of 13 double decks in a 24 m² unit; the professor is strict about practicality). `amenities.quantity_basis` (`AmenityQuantityBasis`: Single, PerUnit, PerRoom = bedrooms + 1 for the living area, PerBathroom = max(bathrooms, 1), PerTenant = the unit's max capacity, FloorSpace = beds), `quantity_per` and `footprint_sqm`, set for the platform list by migration `2026_09_27_133016_set_quantity_limits_on_default_amenities`, not fillable (custom amenities stay PerTenant × 1). Beds: footprint 1.7 m² single/double deck, 2.6 m² double bed; beds together may cover at most `bed_floor_coverage` (0.5) of the sleeping area (`Unit::bedFloorSpaceFor()`: area − common area − bathrooms), and may not sleep more than `Unit::tenantsThatFitIn()` (area ÷ area per tenant). Each bed's limit is computed after the other beds ticked. `UnitForm::amenityQuantityLimits()` drives the "Up to N" hint, the clamp on typing (`clampAmenityQuantity()`, called from the pages' `updated()`), and validation. Yes-or-no amenities have no quantity box. The Amenities page shows each limit. The 0.5 coverage and per-unit numbers are judgment calls, not code citations.
- ✅ **Phase 3 (unit inventory + move-in condition checklist)** — done, committed (2026-09-27). The user's choices: **one move-in check per incoming tenant** (each lease claims its own, so dorm roommates each acknowledge theirs) and **Staff can record checks** (only Owner/Manager edit the item list or override).
  - Tables `unit_items` (+ `removed_at`: an item on any check is hidden, not deleted; unchecked items are deleted), `condition_checks` (+ `tenant_acknowledged_at`; `lease_id` and the acknowledgment are not fillable, set by `claimFor()`/`acknowledge()`), `condition_check_items`, and `leases.move_in_override_reason`. Enums `UnitItemType`, `ConditionCheckKind`, `ItemCondition` (`blocksMoveIn()` = NotWorking/Missing).
  - `Unit::pendingMoveInCheck()` = newest MoveIn check with no lease, recorded no earlier than the start of the day of the unit's last ended lease. `UnitPolicy` (`viewInventory`, `manageInventory`, `recordConditionCheck`), `ConditionCheckPolicy::acknowledge` (lease's tenant only).
  - Page `{team}/units/{unit}/inventory` (`units.inventory`, clipboard icon on each unit row on Properties): readiness callout, items (add/edit/remove, "Add from amenities" one per piece like "Double deck 2", "Copy from another unit"), record a check (every active item needs a condition picked, no default; "Start from the last check"), history in `<details>`.
  - Tenants page `saveUnitAssignment()` (the only place leases are created) requires a clean pending check, or `proceedAnyway` + a 10–500 char reason (Owner/Manager only, else 403); the new lease claims the check. Test factory state `Unit::factory()->readyForMoveIn()`.
  - Tenant page `{team}/move-in-checklist` (`move-in-checklist`), linked from the dashboard's "Your unit" card with a "Please acknowledge" badge.
  - Move-out and routine checks can be recorded but nothing acts on them yet; Phase 8 (transfer) is meant to record a move-out check.
- ✅ **Phase 4 (tenant reports + repair history + notifications)** — done, committed (2026-09-27). The user's choices: "repeated problems" = **3+ repairs/replacements in the last 6 months** (`config('occuplace.items.repeated_problems')`, inspections don't count); **Staff get the bell**; **tenants get notifications too, including when a routine check is recorded on their unit**. Staff can view reports but only Owner/Manager reply or resolve (the plan's default; the user didn't answer).
  - Tables `issue_types` (platform defaults seeded by migration + team's own, like amenities), `item_services` (Repaired/Replaced/Inspected, `performed_at`, `cost`, `notes`, `recorded_by`, nullable `concern_id`), and nullable `concerns.issue_type_id` / `unit_item_id`.
  - Tenant **Maintenance** page is real now (was a "Coming soon" stub): report form (issue type, optional item from own unit, urgency, description, optional photo on the sensitive disk via `concerns.photo`, 10 reports/hour) and their reports with the landlord's updates. Tenant **Complaints** is still a stub.
  - Landlord Maintenance page: rows open a panel (`?report=` in the URL); reply, change status; resolving a report about an item asks Repaired/Replaced/No work needed + date + cost and records an `ItemService` once. Page `requests/issue-types` (`issue-types`) to add/deactivate the team's own types.
  - Unit inventory page: per-item history in `<details>`, `UnitItem::fixSummary()` ("Replaced 2 times and repaired once, last on ..."), red "Repeated problems" badge (`withRecentFixCount()` + `hasRepeatedProblems()`), and "Log work" without a report.
  - **Notifications:** `<x-notification-bell>` (sidebar header + mobile header, everyone except the Super Admin) and page `notifications`. New notifications store `team_id`, `route` and `route_parameters` (a route name, not a URL, so renaming a team never breaks links); `ListingReviewed`/`ReservationSubmitted` gained them. New: `TenantReportSubmitted` (landlord, managers, staff), `ReportUpdated` and `RoutineCheckRecorded` (tenants). **`notifications.data` is a TEXT column, so team filtering is done in PHP (`HasTeams::notificationsForTeam()` / `unreadNotificationCountFor()`) — a `where('data->team_id')` query works on SQLite but fails on Postgres.** Old notifications without `team_id` show on every team.
  - The tenant move-in checklist page was renamed **Unit checks** (`unit-checks`): move-in checklist to acknowledge + routine checks since they moved in. `<x-check-items>` renders a check's items.
- ✅ **Phase 5 (contact number + stay type)** — done, committed (2026-09-28). `App\Rules\PhilippineMobileNumber` accepts `09XXXXXXXXX` / `+639XXXXXXXXX` (spaces/dashes ignored), stores `+639XXXXXXXXX`, shows `0917 123 4567` (`forDisplay()`). Columns `reservations.contact_number`/`stay_type`, `users.contact_number` (fillable, editable in Profile, optional), `leases.stay_type`; enum `StayType` (ShortTerm/LongTerm). Reservation form requires both; `ConfirmReservation` copies the number to the tenant. Tenants page: roster shows the number; the manage modal has a Stay picker (prefilled from the held reservation) and a "Stay" dropdown on the current lease that changes it without moving the tenant. The user confirmed **Staff stay view-only on maintenance reports**.
- ✅ **Move-in flow fixes** (after Phase 5): opening a tenant who holds a reservation pre-selects the reserved unit ("Reserved by this tenant"); moving them into ANY unit fulfils the reservation (releases the hold); the Tenants page keeps the open tenant in `?tenant=`; reservations show a **Move in** button linking there.
- ✅ **Properties UI rework** (the professor's sketch): an expanded property shows units as a **photo card grid** (photo or house placeholder, status + "Details needed"/"N open reports" badges on white chips, tenants/rent line) plus a dashed "+ Add unit" tile (adding stays on Properties). Each card opens the **unit page** with tabs (`<x-unit-header>`): **Overview** `units.show` (`units/{unit}`: photo add/change/remove, facts, tenants + current invoice, amenities, listing), **Details** `units.edit` (`units/{unit}/edit`: the edit form and Delete, moved off Properties), **Inventory & checks** `units.inventory`. `units.photo_path`; `App\Actions\Units\StoreUnitPhoto` shrinks uploads to ≤960 px WebP on the public media disk and rotates by EXIF, so **the Dockerfile now installs `gd` (jpeg, webp) and `exif`** (untested until the next Render build). The Supabase `occuplace-media` bucket must allow `image/webp`.
- ✅ **Amenity limit explanations**: each quantity box shows why in a plain sentence (`UnitForm::amenityLimitReasons()`, e.g. "2 for each tenant. The beds you checked sleep 1 person."); on-screen "tick" is now "check" everywhere.
- ✅ **Phase 6 (reservation hold vs downpayment)** — done, committed (2026-09-28). The user's choices: status page by **code + email** (or the signed link in emails), **landlords can extend** a deadline, **reserving is free** (no payment step on the form).
  - Flow: Pending → **Reserved** (`AcceptReservation`: `expires_at` = now + `teams.reservation_hold_days`, default 3, 1–14, set on Payment settings; emails `ReservationAccepted` with the signed status link) → applicant sends proof on the public page `reservation/{code}` (`reservations.status`; lookup `reservation` = `reservations.lookup`, rate limited; access kept in session key `Reservation::STATUS_ACCESS_SESSION_KEY`) via `SubmitDownpayment` (bell `DownpaymentSubmitted`) → **Confirmed** (`ConfirmReservation`, the renamed ApproveReservation: creates the tenant account) → **Fulfilled** on move-in. `ExtendReservation` (`ReservationExtended` email). Cancel covers Reserved and Confirmed. **Expired**: `reservations:expire` hourly + `ReservationExpired` email; pruning includes expired.
  - **Holding rule** (`Reservation::holdingSlotSql()`, shared by `heldReservations()`, `scopeHasRoom()`, `holdsSlot()`): Confirmed, or Reserved and (before the deadline OR downpayment already sent). A reserved one with nothing sent frees the slot the moment its deadline passes, even before the job runs (Render sleeps). Proof sent in time keeps the hold until the landlord confirms/cancels.
  - Migrations made `downpayment_amount/method/reference` nullable (a column change, no data dropped) and moved `approved` rows to `confirmed`. Deadlines display in `config('occuplace.display_timezone')` = Asia/Manila; **the app itself stays on UTC**.
  - Reservations page groups by next step (review / check downpayment / waiting with deadline / ready to move in) and opens `?reservation=`; the sidebar badge counts `needingLandlord()` (pending + downpayment sent).
- Phases 7–10 not started (the light-theme half of Phase 9 is already done): contract terms + generated contract (**PDF needs a new dependency, ask first**), unit transfer, PSGC region/city dropdowns, wrap-up.

**Not yet seen in a browser** (only covered by tests): the Phase 6 pages (status page, lookup, the reworked Reservations page, hold-days setting), the Tenants page stay picker, plus most Phase 3–4 screens (inventory, report resolve flow, repeated-problems badge, Unit checks). The user has seen and approved the unit card grid, the unit Overview page, and the amenity limit sentences.

**Nothing on this branch is pushed or merged yet** (the user wanted the domain set up first). Merging to `main` deploys and runs 18 migrations on Supabase; they have only ever run on SQLite (no local Postgres; the new SQL was reviewed and is standard). Existing production units have no beds and no floor area, so their capacity is unchanged until landlords complete them.

## Professor Revisions (Round 1) — COMPLETE

Implemented `c:\Users\Rem\Downloads\REVISIONS_PROMPT.md` (**not in the repo**), an 8-phase brief responding to a professor's feedback. All 8 phases are done and merged; the notes below are kept for context. Working on branch **`feature/professor-revisions`**, never `main` (`main` auto-deploys to the live Render app). Do not merge or rebase onto PR #1 (it branches from the first commit and would revert later auth-page work).

**Locked-in decisions** (the user's own choices, overriding the brief's `{{NEW_NAME}}` placeholder and a couple of open questions):
- Product name: **Occuplace** (Phase 1 done — renamed everywhere except the GitHub repo `flowrem/PropertEase`. The brief's rule 8 also kept the Render service, database and `APP_URL` under the old name, but the user overrode that on 2026-09-24 and moved to a new `occuplace` service).
- S3 dependency approved (`league/flysystem-aws-s3-v3`). Storage is **Supabase Storage** over its S3 API (buckets `occuplace-media` public and `occuplace-sensitive` private, both 5 MB limit; the sensitive one allows jpeg, png, webp and pdf). Verified with a real round trip on both buckets from a local `.env`; Laravel's default ACL header does not break uploads on Supabase.
- PHP upload limits will be raised for Phase 3 — both local Herd `php.ini` and a new `docker/php.ini` copied into the Dockerfile, since production currently ships PHP's stock 2MB/8MB with no `php.ini` in the image at all.
- Minimum applicant age: 18 (the brief's own default, lands in Phase 5).
- Super Admin promotion is **artisan-only** (`app:create-super-admin`), reachable by anyone with shell access to the server or a local machine — no extra permission layer beyond that. Confirmed with the user this is the intended trust boundary: teammates self-register as landlords, then whoever has shell access promotes them.
- Every other Open Decision in the brief uses its own stated default (tenant invite-by-email kept, reservation-based slot holding, manual downpayment verification, reject-if-email-already-exists, Owner+Manager can approve/submit and Staff view-only, payment QR set once per team not per listing). **Exception, later overridden by the user:** landlord accounts DO need Super Admin approval with a valid ID (see "Landlord ID verification and approval" below).

**Progress:**
- ✅ **Phase 1 (rename to Occuplace)** — done, committed.
- ✅ **Phase 2 (Super Admin + role hierarchy)** — done, committed. Added `users.is_super_admin` (kept out of `#[Fillable]`, artisan-only), `EnsureSuperAdmin` middleware, `/admin` route group (`admin.dashboard`, `admin.landlords`), fixed a sidebar-layout crash for teamless users (it called `route('dashboard')` and `isLandlordOn(null)`, both of which assumed a team), landlord-only registration with a business-name field, relabeled `TeamRole` (Owner→Landlord, Admin→Manager, Member→Staff; stored values unchanged).
- ✅ **Phase 3 (Unit Listings + payment channels)** — done, committed. `media` (public) and `sensitive` (private) disks chosen by `MEDIA_DISK`/`SENSITIVE_DISK` (`media_s3`/`sensitive_s3` for a bucket), S3 adapter, raised upload limits (`docker/php.ini` + local Herd). New tables `unit_listings`, `listing_photos`, `payment_channels` (+ nullable `properties.map_url`), `ListingStatus` enum, `UnitListingPolicy`/`PaymentChannelPolicy` (Owner+Manager write via `User::canManageListingsOn()`, Staff view-only with controls hidden). Pages: `{team}/listings`, `{team}/payment-settings`, `/admin/listings` review queue (approve, or reject with reason; each action re-checks `is_super_admin` because Livewire update requests do not reliably re-run route middleware). Submit needs an active payment channel and at least one photo; editing an Approved listing returns it to PendingReview. `ListingReviewed` is a database notification to the team Owner and Managers, but **no screen reads notifications yet**; landlords see the outcome via the listing status badge and rejection reason. `status`/`submitted_at` are deliberately not fillable, so tests use `forceFill`.
- ✅ **Phase 4 (public landing + browse pages)** — done, committed. Shared `<x-public-layout>`, landing page with "Find an apartment or dorm" / "I'm a landlord", `PublicListingController` (`/find-a-place` = `listings.index`, `/find-a-place/{listing}` = `listings.show`, plain GET filters, no JS). One visibility rule: `UnitListing::publiclyVisible()` = Approved + `Unit::scopeHasRoom()` (SQL twin of `hasRoomForAnotherTenant()`; **Phase 6 must update both** to count held reservations). `Unit::slotsAvailable()` drives the "N slots" display. The "Reserve this unit" button only renders once a `listings.reserve` route exists (Phase 5).
- ✅ **Phase 5 (public reservation form)** — done, committed. `Reservation` model + `ReservationStatus`, `users.username` (nullable, unique, lowercase, not fillable), `pages::reserve` at `find-a-place/{listing}/reserve` (`listings.reserve`). Pay-then-upload layout; ID and proof stored on the `sensitive` disk; `team_id` from the unit, `listingId` `#[Locked]`, channel must be active and belong to the listing team; honeypot; 5 submissions and 30 attempts per hour per IP; min age 18. `ReservationSubmitted` database notification goes to team Owner and Managers. **The brief also asks for a pending-count badge in the landlord nav; that is deferred to Phase 6 because it needs the `{team}/reservations` page to link to.** Gotcha: a `use Closure;` line at the top of a Livewire SFC breaks compilation (500), so write `Closure` inline instead.
- ✅ **Phase 6 (landlord review + tenant provisioning)** — done, committed. `Unit::hasRoomForAnotherTenant(excludingOwnHold)`, `scopeHasRoom()` and `slotsAvailable()` count `Approved` reservations as held slots; the Tenants assign flow lets the holder take their own slot and marks the reservation `Fulfilled`. `App\Actions\Reservations\{ApproveReservation,RejectReservation,ResendLoginDetails,CancelReservation}`; approval needs the downpayment-confirmed tick, runs in one `lockForUpdate` transaction, creates the tenant user (lowercase username, `must_change_password`, 72h `temporary_password_expires_at`) plus a Tenant membership WITHOUT calling `switchTeam` (that would repoint `URL::defaults` mid-request). `TenantAccountCreated` is `ShouldQueue` + `ShouldBeEncrypted`, mail only. Resend is refused once the tenant has set their own password. `reservations:prune-files` (daily) deletes ID/proof files of rejected/cancelled reservations after 30 days and sets `files_pruned_at`. Page `{team}/reservations` (`reservations`), files via `reservations.files` (`no-store`, team-scoped, private disk), `ReservationPolicy` (view = any landlord-side member, review = Owner+Manager), sidebar pending badge. Reservation `status` is not fillable, so use `forceFill`. `CancelReservation` (Approved only, reason required) releases the slot, sets `users.disabled_at`, and deletes the tenant membership. It also emails the applicant (`ReservationCancelled`) and deletes the account's `sessions` rows, and `EnsureAccountIsActive` (appended to the web group) logs out any disabled user on their next request. Phase 7's login also refuses users with `disabled_at` set.
- ✅ **Phase 7 (first login + forced password change)** — done, committed. `Fortify::authenticateUsing` (in `FortifyServiceProvider`) matches lowercased email or username; the form field is still named `email` so Fortify validation and the `login` limiter are unchanged. After the password is verified it refuses disabled accounts and expired temporary passwords. `EnsurePasswordIsChanged` (web group) redirects a `must_change_password` user everywhere except `password.change`, `password.change.store` and `logout`, which also blocks Livewire's update endpoint. The change page is plain Blade plus `ForcedPasswordChangeController` (deliberately not Livewire, so no Livewire exception is needed) and sits outside `verified`; on success it sets the password, clears the flag, sets `email_verified_at`, deletes the user's sessions, logs out and redirects to login.
- ✅ **Landlord ID verification and approval** — added after Phase 7 at the user's request (it overrides the brief's Open Decision 3, which defaulted to no landlord gate). Self-registering landlords upload a valid ID (jpg/png/pdf, 5 MB, sensitive disk) plus a consent tick; `CreateNewUser` requires both unless the registration matches a pending invitation (invited staff and tenants are exempt). `teams` got `approved_at`, `approved_by`, `rejected_at`, `rejection_reason`, `verification_id_path`, `verification_submitted_at`, `verification_id_pruned_at`; the migration backfills every existing team as approved, and `TeamFactory` defaults to approved (`awaitingApproval()` / `rejectedByAdmin()` states). `User::teamAwaitingApproval()` is the single rule: blocked only if the user has no approved team membership and owns an unapproved one. `EnsureLandlordIsApproved` (web group) redirects blocked users to `landlord.verification` (`/account-review`, plain Blade + `LandlordVerificationController`, where a rejected landlord sees the reason and resubmits); allowed routes are that page, `verification.*`, `logout`, `home` and `listings.*`. `CreateTeam` auto-approves further teams for a user who already owns an approved one. Super Admin side: `pages::admin.landlords` has an Awaiting review section (View ID via `admin.landlords.id`, `no-store`; Approve; Reject with reason) using `App\Actions\Landlords\{ApproveLandlord,RejectLandlord}` and queued `LandlordApproved`/`LandlordRejected` emails, plus a pending badge on the admin sidebar. `landlord-ids:prune` (daily) deletes the ID 30 days after approval or rejection. `Team::owner()` is now typed `?User`. The Super Admin landing page is `pages::admin.dashboard` (route `admin.dashboard`; it replaced the old Overview cards): a hero figure for what awaits review, headline counts, weekly column charts (new landlord teams, reservations), a stacked review bar, listing status bars and a unit-occupancy meter. Charts are server-rendered HTML/CSS in `resources/views/components/charts/{column-chart,bar-list,stacked-bar,meter}.blade.php` (shared by both dashboards) with native `title` tooltips and a table view, so no JS or chart library (deliberate, per the low-bandwidth research finding). The landlord Home (`pages::dashboard`) also has rent collected per month, invoices by state, open maintenance by priority and a units-occupied meter, each scoped to the current team; an all-zero column chart shows an empty message instead of a blank axis. `routes/admin.php` is required BEFORE the `{current_team}` group in `routes/web.php`, otherwise `/admin/listings` matches `{current_team}/listings` and 403s.
- ✅ **Phase 8 (wrap-up)** — done. Committed `public/build/`, rewrote `README.md` (roles, flows, local and production setup), updated this file, and changed infrastructure: the Dockerfile `CMD` now also backgrounds `php artisan schedule:work` (Render's free plan has no cron, so without it `invoices:generate`, `reservations:prune-files` and `landlord-ids:prune` never run) and passes `--no-reload` to `artisan serve` (otherwise `PHP_CLI_SERVER_WORKERS` is ignored and the server is single-threaded). `render.yaml` now documents Supabase: `DB_URL` is `sync: false` and set by hand, plus `DB_SSLMODE=require`, `MEDIA_DISK=media_s3`, `SENSITIVE_DISK=sensitive_s3`, `AWS_DEFAULT_REGION=us-west-2`, `AWS_USE_PATH_STYLE_ENDPOINT=true`. The unused `propertease-db` Render database block was later removed from the file, and the Blueprint itself was disconnected (see Deployment). **The branch is NOT merged**: merging to `main` auto-deploys, so that is the user's call. Order matters when they do: the Supabase storage variables must already be set in Render (they are, as of 2026-09-24) before the new code deploys, or uploads land on the ephemeral disk.

**Non-negotiable rules from the brief, apply to every remaining phase:** migrations are additive-only, never edit an existing migration; never `migrate:fresh`/`db:wipe` outside the test suite; keep `php artisan test --compact`, PHPStan, and Pint green after every phase; one commit per logical step, no catch-alls; never rename the Render service/database/`APP_URL`; present a short plan and wait for approval at the start of each phase, stop and summarize at the end; if an ambiguity isn't covered by the brief or its Open Decisions, stop and ask. Temporary tenant passwords (Phase 6) need the same discipline already applied to `is_super_admin`: never logged, never flashed, never stored in plaintext, and the notification carrying one must be both `ShouldQueue` and `ShouldBeEncrypted`.

**Test count as of 2026-09-27** (branch `feature/property-revisions`, commit `f6e5984`): 613/613 passing, PHPStan 0 errors, Pint clean.

## Tech Stack

- Laravel 13, PHP 8.4, **Livewire 4** using **Single-File Components** with a `⚡` filename prefix (`resources/views/pages/⚡name.blade.php` for full pages, registered via `Route::livewire()`).
- **Flux UI** (free edition) for components. Some Flux internals were published into `resources/views/flux/` for customization beyond what props allow (project convention — check there before assuming a Flux default).
- **Fortify** for auth. Registration is invitation-aware — see Architecture below.
- **Tailwind CSS v4** — CSS-first config in `resources/css/app.css` via `@theme`, no `tailwind.config.js`.
- **Teams-based multi-tenancy** (starter-kit default, repurposed — see Architecture below).
- Pest for tests (count is in "Test count" above), Larastan/PHPStan for static analysis (clean, 0 errors), Pint for formatting.
- **Database: SQLite locally, PostgreSQL (Supabase) in production.** Nothing in the codebase is SQLite-specific (verified — no raw SQLite SQL, no native `enum()` columns; enums are plain `string` columns + PHP enum casts, which is what makes this portable).
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
    Reservations/{Approve,Reject,Cancel}Reservation, ResendLoginDetails   Reservation lifecycle
    Landlords/{Approve,Reject}Landlord                                    Super Admin ID review
  Models (added by the professor revisions): UnitListing, ListingPhoto, PaymentChannel,
                     Reservation. Enums: ListingStatus, ReservationStatus.
  Round 2:           Amenity (+ AmenityCategory enum, AmenityPolicy); Unit::amenities(),
                     bedSpaces(), withBedSpaces(); Team::amenities()
  Livewire/Forms/UnitForm.php   Shared add/edit unit form: limits, locked details, amenities,
                     bed-based capacity preview, tenant-limit clamping/following
config/occuplace.php  All unit limits: floor area 6–70, rooms, common_area 6, area_per_tenant,
                     tenants_per_bedroom, amenity_quantity max 20, max_capacity 20
  Console/Commands/  GenerateInvoices (`invoices:generate`), PruneReservationFiles
                     (`reservations:prune-files`), PruneLandlordIds (`landlord-ids:prune`),
                     CreateSuperAdmin (`app:create-super-admin`). All scheduled daily except the last.
  Notifications/     Teams/TeamInvitation, TenantAccountCreated (queued + encrypted),
                     ReservationSubmitted/Rejected/Cancelled, ListingReviewed,
                     LandlordApproved/Rejected
  Concerns/HasTeams.php   Team-membership helpers on User, incl. isLandlordOn(Team),
                          canManageListingsOn(Team), teamAwaitingApproval()
  Http/Middleware/
    EnsureTeamMembership.php   Gates routes; ':member' blocks the Tenant role
    SetTeamUrlDefaults.php     Sets URL::defaults(current_team)
    EnsureSuperAdmin, EnsureAccountIsActive, EnsurePasswordIsChanged,
    EnsureLandlordIsApproved   The last three are appended to the web group and redirect
                               instead of 403 (see Phase 6 to 8 notes above)
  Http/Controllers/  PublicListingController, ReservationFileController,
                     ForcedPasswordChangeController, LandlordVerificationController,
                     LandlordIdController (plain controllers where a Livewire page would be blocked)

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
        ⚡maintenance.blade.php ⚡complaints.blade.php   Landlord concern lists split by category (replaced the old inbox)
      teams/…                   Team/staff management (unchanged)

routes/
  web.php            public pages (`/`, `find-a-place`, reserve), then `admin.php` (MUST come before the
                     team group), then {current_team}/{dashboard,setup,properties,tenants,invoices,
                     listings,payment-settings,reservations,requests/maintenance,requests/complaints};
                     `inbox` redirects to maintenance gated ':member'
  admin.php          /admin/{,landlords,landlords/{team}/id,listings}, Super Admin only
  console.php        Schedule: invoices:generate, reservations:prune-files, landlord-ids:prune (daily)

public/images/logo.png, logo-mark.png; public/{favicon.ico,favicon-32.png,icon-192.png,apple-touch-icon.png}   Brand assets cut from the supplied logo
resources/views/components/charts/   column-chart, bar-list, stacked-bar, meter (server-rendered, no JS)
resources/views/pages/admin/         dashboard, landlords, listings
resources/views/{auth,landlord}/     plain Blade pages: change-temporary-password, verification

Dockerfile          PHP 8.4-cli image; NO Node stage (assets are pre-built + committed);
                    CMD runs migrate, caches, queue:work and schedule:work in the background, then serve
docker/php.ini      Raised upload limits (the stock image ships none)
.dockerignore
render.yaml         Render Blueprint: web service + env vars; DB_URL and storage keys are set by hand
                    (Supabase Postgres + Storage)

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

Live at `https://occuplace.org` (custom domain on the Render service; `https://occuplace.onrender.com` still answers). GitHub repo `flowrem/PropertEase` (kept under its old name), the `occuplace` service auto-deploys on push to `main`. **The Render Blueprint was disconnected on 2026-09-24**, so `render.yaml` is documentation only and every environment variable is managed in the Render dashboard; changing the file changes nothing on Render. A Render `*.onrender.com` address cannot be changed on an existing service (renaming does not change it), which is why a new service was created instead of renaming `propertease`.

**Why Render and not Laravel Cloud**: Laravel Cloud signup requires a payment method the user doesn't have. Render's free tier does not. (Railway was also evaluated: it has a genuinely card-free trial, but its Limited Trial explicitly restricts outbound network access, so it may hit the same SMTP wall — not worth migrating for.)

**Stack shape**: single Docker web service on Render (region Oregon), defined declaratively in `render.yaml` (a Blueprint), with **Supabase** (project `Occuplace`, West US Oregon, free plan) providing both Postgres and file storage. No Redis, no separate worker service — session/cache/queue are all database-backed, and the container itself runs the queue worker and the scheduler in the background.

### Hard-won deployment gotchas

1. **Frontend assets are pre-built locally and committed** (`public/build/` is no longer gitignored). The `vite-plus`/rolldown toolchain fails inside Linux Docker with an error whose real message is swallowed (`errors: [Getter/Setter]` — Node's inspector refuses to evaluate a lazy getter when formatting). Switching Alpine → Debian and `npm ci` → `npm install` both failed to fix it. **Consequence: after any CSS/JS/asset change you must run `npm run build` locally and commit `public/build/` or production serves stale assets.**
2. **Postgres, not SQLite** — Render's filesystem is ephemeral, a SQLite file would be wiped each deploy. Render's own free Postgres **expires 30 days after creation**, which is why the app now uses Supabase Postgres (2026-09-24). Supabase's free plan instead **pauses a project after a week without activity** (data kept, restore from its dashboard), so ping the site before a demo. Connect through the **Session pooler** (port 5432, user `postgres.<project-ref>`, `DB_SSLMODE=require`): the direct connection is IPv6-only and Render cannot reach it, and the transaction pooler (6543) breaks Laravel's prepared statements. The project was created with the Data API off and automatic RLS on, since Laravel connects as the owning `postgres` role and never uses Supabase's REST layer.
3. **Production data is a separate, empty database.** Migrations create the schema; no local data carries over. This confused the user once ("my old accounts were removed") — expected, not a bug.
4. **No queue worker = no email.** `TeamInvitation` implements `ShouldQueue`, so with only `php artisan serve` running, jobs sat in the `jobs` table forever. Fixed by backgrounding `php artisan queue:work` in the container `CMD`.
5. **`php artisan serve` is single-threaded.** Making mail synchronous (`QUEUE_CONNECTION=sync`) to dodge #4 caused a worse bug: a slow SMTP send blocked *every* request including Render's health check, which timed out and got the instance flagged unhealthy mid-request. Fixed with `PHP_CLI_SERVER_WORKERS=4` **and** reverting to a real background worker.
6. **Render blocks outbound SMTP.** Gmail on port 587 fails with a flat `Connection timed out` (not refused, not an auth error — the signature of a firewall silently dropping packets). **Production therefore uses Resend's HTTP API** (`MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS=no-reply@occuplace.org`). Local dev still uses Gmail SMTP, which works fine there.
7. **Resend sandbox is lifted: `occuplace.org` is a verified sending domain** (2026-09-28, Resend region us-east-1, the existing API key named "PropertEase" kept). The domain was bought at **Z.com**; its Manual DNS page holds `resend._domainkey` (TXT, DKIM), `send` and `rsend` (CNAMEs to `*.forge.rmta.net`, Resend's current sending setup instead of MX+SPF TXT), `_dmarc` (`v=DMARC1; p=none;`), plus Render's `@` A → `216.24.57.1` and `www` CNAME → `occuplace.onrender.com`. Z.com's default Titan email rows were overwritten on purpose; never click "Auto-Configure MX for Titan Email". Production mail sends as `no-reply@occuplace.org` / "Occuplace". Before the domain, Resend only delivered to the account owner (`mendozaac@students.nu-lipa.edu.ph`).
8. **`APP_URL` must be set explicitly.** It wasn't, so Laravel fell back to its built-in `http://localhost` default and every invitation email linked to localhost. Now set in the Render dashboard (and recorded in `render.yaml`); since 2026-09-28 it is `https://occuplace.org`. It must match the address people use: email links and the signed reservation-status links are built from it.
9. **No cron on Render's free plan.** Fixed in Phase 8 by backgrounding `php artisan schedule:work` in the container `CMD`, so `invoices:generate`, `reservations:prune-files` and `landlord-ids:prune` do run. The scheduler dies with the container, and the instance sleeps after 15 minutes idle, so a job scheduled for a specific time can be missed while asleep; the commands are idempotent, so running one by hand is always safe. **Render's Shell tab is NOT available on the free plan** ("Shell is not supported for free compute plans"), so a manual run has to come from a local machine pointed at the Supabase database (see the README).
10. **Blueprint env var changes don't always sync** to an already-created service. If a var added to `render.yaml` doesn't appear in the dashboard's Environment tab, add it manually there.
11. **Free instances spin down after 15 min idle** and take 30-60s to wake. Load the URL before demoing.
12. **`PHP_CLI_SERVER_WORKERS` is ignored without `--no-reload`.** A deploy log showed `Unable to respect the PHP_CLI_SERVER_WORKERS environment variable without the --no-reload flag. Only creating a single server.`, i.e. gotcha #5's fix had silently stopped working. The `CMD` now passes `--no-reload`. Untested locally (no Docker), so confirm the warning is gone in the next deploy log.
13. **Uploads go to Supabase Storage, not Render's disk.** Set in Render (not in git): `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_ENDPOINT` (`https://<ref>.storage.supabase.co/storage/v1/s3`), `MEDIA_BUCKET`, `SENSITIVE_BUCKET`, `MEDIA_URL` (`https://<ref>.supabase.co/storage/v1/object/public/occuplace-media`). The S3 keys have full access to every bucket and bypass RLS: treat the secret like the database password. Path-style addressing must be `true`. A bucket's allowed MIME types must cover everything the app validates for that disk (a WebP payment proof is rejected by the bucket if `image/webp` is missing on `occuplace-sensitive`); a `.txt` test upload returns `415`.
14. **A route under `{current_team}` swallows same-named top-level paths.** `/admin/listings` matched `{current_team}/listings` and returned 403 until `routes/admin.php` was required first. Register any new fixed top-level prefix before the team group, and add a test that requests the URL over HTTP, since Livewire component tests do not exercise routing.
15. **Email verification is not enforced.** `User` does not implement `MustVerifyEmail`, so the `verified` middleware lets unverified users through everywhere, and Fortify sends no verification mail. Do not assume `email_verified_at` gates anything.
16. **The production image has no `intl` extension, but local PHP (Herd) does.** It installs only `pdo pdo_pgsql mbstring zip`. Anything needing intl, above all Laravel's `Number` helper (`Number::format`, `abbreviate`, `currency`, ...), passes every local test and returns a 500 in production. This is exactly what broke `/admin` right after the merge: the column chart's axis ticks used `Number::abbreviate`, which only ran once a chart had non-zero data. Use `number_format()` and plain PHP instead; `tests/Feature/ProductionEnvironmentTest.php` fails if the helper reappears in `app/` or `resources/views`. Add a PHP extension to the Dockerfile only if there is no plain-PHP alternative. When a 500 appears only in production, check the environment differences (extensions, Postgres versus SQLite, the missing `.env`) before suspecting the logic.
17. **Finding the real error behind a production 500.** `artisan serve`'s access log lines (`/path .... ~ 500.29ms`) show DURATIONS, not HTTP status, and Render's log view may not show the exception. The fast way is to set `APP_DEBUG=true` in Render's Environment tab, reproduce the error, read the page, then set it back to `false` immediately (it exposes internals to every visitor).
18. **A missing Render env var can surface as a TypeError, not a config error.** `AwsS3V3Adapter::__construct(): Argument #2 ($bucket) must be of type string, null given` on registration meant `SENSITIVE_BUCKET` (and probably `MEDIA_BUCKET`) was not set on the new `occuplace` service: the disks read `env('SENSITIVE_BUCKET', env('AWS_BUCKET'))`. When a service is recreated, compare its Environment key list against `render.yaml` and the README, including the `sync: false` ones.
19. **Logo files must have a real transparent background.** The supplied PNG had an OPAQUE white background (GD alpha 0 = opaque, 127 = transparent, easy to misread). It was cleaned by clearing the outside-connected white, the counters inside the letters, and unmixing the anti-aliased edges to the ink colour; the white disc inside the circle is part of the design and was kept. The mark is pale mint (167,214,198), so on a light surface it needs a navy square (`bg-brand-900`); in dark mode the square is transparent (`dark:bg-transparent`).

## Design System

**Palette — warm, light only** (chosen by the user on 2026-09-27 from a Color Hunt palette; replaced "Coastal Midnight"). `resources/css/app.css`, `@theme`:
- Palette colours: `brown #A47251`, `orange #DD9E59`, `sand #F0D8A1`, `sage #DCF0C3` (utilities `bg-sand`, `border-sand`, `bg-sage`, ...). Used for fills, borders, icons.
- `brand-50 #FDF9F1` page background, `brand-100 #F8EDD5` sidebar/header, `brand-500 #8A5C3F` links/icons/charts (`--color-accent-content`), `brand-600 #74492F` buttons (`--color-accent`), `brand-700 #5C3A25`.
- `zinc-*` is overridden with Tailwind's warm **stone** values, so every Flux neutral is warm.
- **Dark mode is removed** (the brief's Phase 9 "light theme" is effectively done): no `class="dark"`, no `@fluxAppearance` (without it Flux clears any saved appearance), no Appearance settings page/route. `dark:` classes remain in views but never apply. `BrandLogoTest` guards this.
  - **Gotcha hit right after the switch:** a tab opened before the change kept `class="dark"` on `<html>`, because `wire:navigate` swaps the body but never touches the `<html>` element, so text went white-on-cream. Fixed twice over: `@custom-variant dark (&:not(*))` in `app.css` makes every `dark:` utility match nothing, and a `data-navigate-once` script in `partials/head.blade.php` removes a leftover `dark` class on load and on `livewire:navigated` (Flux's own `flux.css` has three raw `.dark {}` rules the variant can't reach).
- The logo and icons were recolored with a GD script (inverse-distance blend from the five mint source colours to the palette): brown ring, sage windows, sand ground, deep-brown `#7A5034` wordmark; icons on a sand `#FAF1DD` square. The sidebar mark has no square behind it any more.

**Font**: Plus Jakarta Sans.

**Contrast** (WCAG AA): the raw brown `#A47251` is only 4.1:1 on white, so it is never used for text; `brand-500` is 5.7:1 and `brand-600` with white text 7.5:1. `text-zinc-500` (stone) is ~4.8:1 on white; **bare `text-zinc-400` fails on light backgrounds**, so it was replaced app-wide with `text-zinc-500`. Don't reintroduce it for text.

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
30. **Landlord Home redesign and Inbox split** (not in the brief, requested mid-project): Home is now ordered by importance (money owed and past due, then urgent maintenance and unanswered complaints, then occupancy, then links). The combined Inbox became separate Maintenance and Complaints pages (`landlord.maintenance`, `landlord.complaints`); `inbox` redirects. Added `ConcernPriority::color()`/`rank()` and an `<x-concern-list>` component. Then finished Phase 5 (reservation form), where writing the tests caught a 500 from a `use Closure;` line in the SFC.
31. **Finished the professor revisions, Phases 6 to 8.** Phase 6: reservation-aware capacity, approve/reject/resend/cancel actions, the reservations page and private file route. Phase 7: login by email or username, forced password change. Requested additions beyond the brief: a Cancel action for approved reservations (emails the applicant, ends their sessions, disables the account), landlord ID verification with Super Admin approval and 30-day ID deletion, and both dashboards rebuilt around server-rendered charts. Bugs found along the way: the `/admin/listings` route collision (gotcha #14), the `PHP_CLI_SERVER_WORKERS` warning (#12), and the finding that email verification is not enforced (#15).
32. **Moved production off Render's expiring Postgres and ephemeral disk** (2026-09-24). Created the Supabase project (Oregon, Data API off, automatic RLS on, GitHub integration skipped), pointed `DB_URL` at the Session pooler with `DB_SSLMODE=require`, and confirmed a deploy ran all migrations on the empty database. Created the two Storage buckets and S3 keys, and verified a real upload, read, public-URL check and delete on both from a local `.env` before touching Render. Evaluated Back4app as an alternative host and rejected it: its Parse plan cannot run Laravel, and its container tier states only 256 MB RAM and 0.25 CPU with disk, sleep and cron behaviour undocumented.
33. **Phase 8 wrap-up:** scheduler and `--no-reload` in the Dockerfile, `render.yaml` aligned with the Supabase setup, README rewritten, this file brought up to date, `public/build/` committed.
34. **Merged to `main` and moved to a new Render service** (2026-09-24). Merged `feature/professor-revisions` (merge commit `2635e1a`, 44 commits) and pushed, which redeployed the old service with the new code. The user then wanted a service URL matching the product name; Render cannot change an existing `onrender.com` address, so a new `occuplace` service was created by hand (environment variables pasted in), verified live at `https://occuplace.onrender.com` serving the new code, and the Render Blueprint was disconnected so `render.yaml` stops syncing (otherwise a rename would have created a duplicate service, and deleting the old one would have been undone on the next sync). Both services share the one Supabase database and buckets, so the old `propertease` service and the empty `propertease-db` are to be suspended and then deleted.
35. **First production run and fixes** (2026-09-24 to 25). After the merge, `/admin` returned 500: the column chart used `Number::abbreviate`, which needs the `intl` extension the image lacks (gotcha #16, fixed with plain PHP and a guard test). Render's Shell turned out to be paid-only, so the first Super Admin was made by registering on the site and setting `is_super_admin` to true in Supabase's Table Editor. Registration then returned 500 because `SENSITIVE_BUCKET` was missing on the new service (gotcha #18); the cause was found by temporarily setting `APP_DEBUG=true` (gotcha #17). The supplied logo was applied everywhere (gotcha #19): full logo on login and register, the mark beside the name on public pages, the mark in the sidebar, new favicons, and the sidebar's navy square made transparent in dark mode.
36. **Round 2, Phases 1–2** (2026-09-27, branch `feature/property-revisions`). Phase 1: locked unit details, floor area, config-driven limits. Then the user questioned the capacity math ("3 bedrooms but 4 tenants, where does the 4th sleep?"): capacity first became bedroom-based (2 or 4 per bedroom) with a 6 m² common area, then, in Phase 2, bed-based (a double deck sleeps 2). Phase 2 built the amenity catalog, unit-form amenities with quantities, the Amenities page and the public amenity display/filter. A follow-up added the "counting only the ticked beds" note and made the tenant limit follow the maximum as beds change. Gotchas hit: `sed` edits silently miss on this repo's CRLF files (use Edit or `perl -0pi`); the spread operator renumbers integer-keyed arrays (use `+`); PHPStan can't see `$amenity->pivot`, so read pivot values with `pluck('amenity_unit.quantity', 'amenities.id')` in PHP code (Blade is fine).
37. **Round 2 continued: amenity limits, Phases 3–4, new theme** (2026-09-27, same branch, nothing pushed). Per-amenity quantity limits after the user showed 13 double decks accepted in 24 m² (beds by floor space and tenants, everything else per unit/room/bathroom/tenant); then a layout fix (errors were grid cells of their own) and "Studio or bedspace" / "Shared bathroom" checkboxes replacing the typed 0. Phase 3 (inventory, condition checks, move-in rule, tenant acknowledgment). Switched to the user's warm Color Hunt palette, light only, with the logo and icons recolored by a GD script; fixed the stale `dark` class. Phase 4 (issue types, tenant report form, landlord resolve flow with repair history and the repeated-problems flag, notification bell for everyone, routine-check notices, Unit checks page). Gotchas: PowerShell 5.1 mangles `git commit -m` here-strings containing double quotes (commit from a message file in the scratchpad, or use Bash); the Bash heredoc also broke once on a test file full of `\'` (use the Write tool); `notifications.data` is TEXT, so no JSON queries on it (Postgres would fail).

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
- **Guaranteed scheduling in production** — `schedule:work` runs inside the container, but the free instance sleeps after 15 minutes idle, so a run can be skipped while it is asleep. The commands are idempotent, so run `invoices:generate` locally against the production database if a day was missed (Render's Shell is paid-only).
- **A screen for notifications** — `ListingReviewed` and `ReservationSubmitted` are stored as database notifications, but nothing reads them. The pending badges in the sidebars are the only surface.
- **Landlord-side charts for reservations or tenants**, date-range filters on any chart, and Super Admin actions beyond approving landlords and listings (no suspend, no edit).
- **Sending email to arbitrary recipients in production** — solved on 2026-09-28 by verifying `occuplace.org` in Resend (gotcha 7).
- **Any real payment processing** (GCash/bank integration). Payments are landlord-recorded only.
- **An outstanding-balance warning when removing/moving a tenant** — discussed and designed, not built. Since the Tenants page only shows active leases, a departing tenant's unpaid invoices currently drop out of that view (the data is intact under the ended lease, just not surfaced).
- **Truncating the final invoice when a lease ends mid-cycle** — an already-generated invoice keeps covering its full period even if the tenant leaves early. Arguably correct as a business policy, but it was never an explicit decision.
- **Tenant-side Concern submission form** — Maintenance/Complaints are still "Coming soon" stubs (Billing is real now).
- **Per-tenant custom rent amounts** — the equal split is currently forced; the user has signalled interest in unequal amounts (common in PH dorms), which would need a nullable per-lease override that the splitter skips.
- SMS/low-bandwidth fallback mode.

## How To Resume

Locally: the app is served by Herd at `http://Occuplace.test`. Run `php artisan queue:listen --tries=1 --timeout=0` if you need queued mail to send. Confirm `php artisan test --compact` is green (see the test count above) and `vendor/bin/phpstan.bat analyse` is clean (0 errors). The local `.env` also holds the Supabase storage keys (`AWS_*`, buckets, `MEDIA_URL`) for testing the S3 disks; `MEDIA_DISK`/`SENSITIVE_DISK` stay on `media`/`sensitive` locally. Use **PowerShell** for artisan/pint/pest/composer.

In production: pushing to `main` auto-deploys to Render. **If the change touches CSS/JS/assets, run `npm run build` and commit `public/build/` too** or production will serve stale assets. Nothing from the professor-revisions branch has been pushed to `main` yet — it all lives on `feature/professor-revisions`. Production already runs against Supabase (database and, once the branch is deployed, storage) using the OLD `main` code.

**Immediate next step (2026-09-27):** continue Round 2 on `feature/property-revisions` (see "Professor Revisions Round 2" at the top). Phases 1–4 are committed, nothing pushed. Next is **Phase 5 (contact number + stay type)**: read the brief's Phase 5, present a short plan and wait for approval. The user may first want to check the Phase 2 and 3 screens in a browser (listed at the top as not yet seen).

**Production open items (state at the end of the 2026-09-25 session, may already be done):** everything is merged and pushed. `origin/main` is at the logo commit `01647aa` or later, and `occuplace` auto-deploys from it. Open items, in this order:
1. **Set `APP_DEBUG` back to `false`** in Render's Environment tab if it is still `true`. It was turned on temporarily to debug the registration 500.
2. **Confirm the registration 500 is fixed.** The `occuplace` service must have all of these, with exactly these names: `SENSITIVE_BUCKET`, `MEDIA_BUCKET`, `MEDIA_URL`, `AWS_ENDPOINT`, `AWS_DEFAULT_REGION`, `AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `MEDIA_DISK=media_s3`, `SENSITIVE_DISK=sensitive_s3`.
3. **Run the full flow once on production:** register a landlord with an ID, approve it from `/admin/landlords` (View ID must load), confirm the file is in the `occuplace-sensitive` bucket in Supabase and survives a redeploy, then finish the setup wizard and check the landlord Home. One landlord (team `INF-241`) was approved and reached the setup wizard.
4. **Retire the old service:** suspend, then delete, `propertease` and the empty `propertease-db`.
5. **Not yet seen in a browser:** the logged-in sidebar logo after the dark-mode change, and the favicon in the tab.
6. **Known limits:** Resend only delivers to the account owner's address until a domain is verified, so approval and temporary-password emails to anyone else fail. The free Render instance sleeps after 15 minutes idle, and the free Supabase project pauses after a week idle.

Earlier note, kept for context: the professor revisions are merged and deployed (merge commit `2635e1a`, live at `https://occuplace.onrender.com`). Still to do by hand: check the deploy log for the migrations and that the `PHP_CLI_SERVER_WORKERS` warning is gone; create the first Super Admin (register on the site, then set `is_super_admin` to `true` on that row in Supabase's Table Editor, because Render's Shell is paid-only); register a test landlord with an ID, approve it, and confirm the file appears in the `occuplace-sensitive` bucket and survives a redeploy; suspend then delete the old `propertease` service and `propertease-db`.

Once the professor revisions wrap up (or if picking other work instead), natural next steps on the original feature set: the outstanding-balance warning on tenant removal/move (the designed-but-unbuilt piece that closes the "debt disappears from view" gap), per-tenant custom rent amounts, service charges on invoices, then the tenant-side Concern submission form so Inbox has real data.
