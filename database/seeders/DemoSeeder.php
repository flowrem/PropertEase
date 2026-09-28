<?php

namespace Database\Seeders;

use App\Actions\Contracts\GenerateLeaseContract;
use App\Actions\Invoices\GenerateDueInvoicesForLease;
use App\Actions\Invoices\RecordInvoicePayment;
use App\Actions\Leases\MoveTenantIntoUnit;
use App\Actions\Transfers\RequestTransfer;
use App\Actions\Units\StoreUnitPhoto;
use App\Enums\BillingTiming;
use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use App\Enums\ConditionCheckKind;
use App\Enums\ItemCondition;
use App\Enums\ItemServiceAction;
use App\Enums\ListingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PropertyType;
use App\Enums\ReservationStatus;
use App\Enums\StayType;
use App\Enums\TeamRole;
use App\Enums\UnitItemType;
use App\Enums\UnitStatus;
use App\Models\Amenity;
use App\Models\City;
use App\Models\ConditionCheck;
use App\Models\IssueType;
use App\Models\Lease;
use App\Models\ListingPhoto;
use App\Models\PaymentChannel;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitItem;
use App\Models\UnitListing;
use App\Models\User;
use App\Notifications\DownpaymentSubmitted;
use App\Notifications\ReservationSubmitted;
use App\Notifications\TenantReportSubmitted;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One complete demo landlord account showing every feature, added beside
 * any existing data: `php artisan db:seed --class=DemoSeeder`. It goes
 * through the app's own actions, so contracts, holds and notifications come
 * out as in real use. Every account uses PASSWORD. Emails are written to
 * the log, never sent. Running it again does nothing.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const LANDLORD_EMAIL = 'landlord@demo.occuplace.test';

    private Team $team;

    private User $landlord;

    private PaymentChannel $channel;

    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $logins = [];

    public function run(
        MoveTenantIntoUnit $moveTenantIntoUnit,
        GenerateLeaseContract $generateLeaseContract,
        GenerateDueInvoicesForLease $generateInvoices,
        RecordInvoicePayment $recordPayment,
        RequestTransfer $requestTransfer,
        StoreUnitPhoto $storeUnitPhoto,
    ): void {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo data is for local use only.');
        }

        if (User::query()->where('email', self::LANDLORD_EMAIL)->exists()) {
            $this->command->warn('The demo data is already there. Log in as '.self::LANDLORD_EMAIL.' / '.self::PASSWORD.'.');

            return;
        }

        // Send nothing: queued emails run now and go to the log.
        config(['queue.default' => 'sync', 'mail.default' => 'log']);

        $this->landlordAndTeam();

        $property = $this->property();
        [$room101, $dorm102, $dorm103, $room104] = $this->units($property, $storeUnitPhoto);

        $this->listUnit($dorm103, 'Bedspace in a 4-person dorm near NU Lipa', 3000);
        $this->listUnit($room104, 'Private room with its own bathroom', 5000);

        $juanaLease = $this->settledTenant($room101, $moveTenantIntoUnit, $generateLeaseContract, $generateInvoices, $recordPayment);
        $this->reports($juanaLease);

        $pedroLease = $moveTenantIntoUnit->handle(
            $this->tenant('Pedro Santos', 'pedro@demo.occuplace.test', '+639181112233'),
            $dorm102,
            15,
            BillingTiming::Advance,
            StayType::ShortTerm,
            $this->landlord,
        );
        $requestTransfer->handle($pedroLease, $room104, 'I would like a private room so I can study at night without waking my dorm mates.', now()->addWeek());

        $this->reservations($dorm103);

        $this->command->info('Demo data added. Password for every account: '.self::PASSWORD);
        $this->command->table(['Role', 'Name', 'Email'], $this->logins);
    }

    private function landlordAndTeam(): void
    {
        $this->landlord = User::factory()->create([
            'name' => 'Demo Landlord',
            'email' => self::LANDLORD_EMAIL,
            'contact_number' => '+639170000001',
        ]);
        $this->team = $this->landlord->currentTeam;
        $this->team->update(['name' => 'Sunrise Residences']);
        $this->logins[] = ['Landlord', $this->landlord->name, $this->landlord->email];

        $this->member('Staff Sam', 'staff@demo.occuplace.test', TeamRole::Member);

        $this->channel = PaymentChannel::factory()->for($this->team)->create([
            'method' => PaymentMethod::Gcash,
            'account_name' => 'Demo Landlord',
            'account_number' => '09170000001',
            'qr_path' => $this->demoImage('GCash QR (demo)', 'qr', config('filesystems.media_disk'), 400, 400),
        ]);

        $this->team->contractTemplate()->create([
            'advance_months' => 1,
            'deposit_months' => 2,
            'minimum_stay_months_short' => 5,
            'minimum_stay_months_long' => 12,
            'notice_days' => 30,
            'late_fee' => 200,
            'house_rules' => "Quiet hours from 10 PM to 6 AM.\nNo smoking inside the building.\nVisitors leave by 9 PM.",
        ]);
    }

    private function property(): Property
    {
        $lipa = City::query()->with('province')->where('name', 'City of Lipa')->firstOrFail();

        return Property::factory()->for($this->team)->create([
            'name' => 'Sunrise Residences Lipa',
            'type' => PropertyType::Dormitory,
            'address_line' => 'Blk 4 Lot 12, Brgy. Sabang',
            'region_code' => $lipa->region_code,
            'province_code' => $lipa->province_code,
            'city_code' => $lipa->code,
            'city' => $lipa->name,
            'province' => $lipa->province?->name,
            'postal_code' => '4217',
        ]);
    }

    /**
     * @return array{0: Unit, 1: Unit, 2: Unit, 3: Unit}
     */
    private function units(Property $property, StoreUnitPhoto $storeUnitPhoto): array
    {
        $single = [
            'status' => UnitStatus::Vacant, 'bedrooms' => 1, 'bathrooms' => 1, 'allows_multiple_tenants' => false, 'tenant_limit' => null,
        ];
        $shared = [
            'status' => UnitStatus::Vacant, 'bedrooms' => 1, 'bathrooms' => 1, 'allows_multiple_tenants' => true, 'tenant_limit' => 4,
        ];

        $units = [
            $this->unit($property, '101', ['floor_level' => 'Ground floor', 'floor_area_sqm' => 20, 'price' => 5500] + $single, ['Single bed' => 1, 'Study table' => 1, 'Electric fan' => 1, 'Wi-Fi' => 1]),
            $this->unit($property, '102', ['floor_level' => '2nd floor', 'floor_area_sqm' => 28, 'price' => 12000] + $shared, ['Double deck' => 2, 'Cabinet' => 4, 'Electric fan' => 2, 'Wi-Fi' => 1]),
            $this->unit($property, '103', ['floor_level' => '2nd floor', 'floor_area_sqm' => 30, 'price' => 12000] + $shared, ['Double deck' => 2, 'Cabinet' => 4, 'Study table' => 2, 'Wi-Fi' => 1]),
            $this->unit($property, '104', ['floor_level' => '3rd floor', 'floor_area_sqm' => 18, 'price' => 5000] + $single, ['Single bed' => 1, 'Electric fan' => 1]),
        ];

        foreach ([0 => 'Unit 101', 2 => 'Unit 103'] as $index => $label) {
            $path = tempnam(sys_get_temp_dir(), 'occ').'.jpg';
            $this->drawImage($label.' (demo photo)', $path, 800, 600);
            $storeUnitPhoto->handle($units[$index], new UploadedFile($path, 'room.jpg', 'image/jpeg', null, true));
        }

        return $units;
    }

    /**
     * A unit with its amenities, its inventory, and a clean move-in check.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $amenities
     */
    private function unit(Property $property, string $number, array $attributes, array $amenities): Unit
    {
        $unit = Unit::factory()->for($property)->create(['unit_number' => $number, ...$attributes]);

        foreach ($amenities as $name => $quantity) {
            $unit->amenities()->attach(Amenity::query()->whereNull('team_id')->where('name', $name)->value('id'), ['quantity' => $quantity]);
        }

        $check = ConditionCheck::factory()->for($unit)->create([
            'kind' => ConditionCheckKind::MoveIn,
            'checked_by' => $this->landlord->id,
            'checked_at' => now()->subMonths(3),
        ]);

        foreach (['Ceiling light, main room' => UnitItemType::Lighting, 'Faucet, bathroom' => UnitItemType::Plumbing, 'Door lock' => UnitItemType::Fixture, 'Electric outlet' => UnitItemType::Electrical] as $name => $type) {
            $item = UnitItem::factory()->for($unit)->create(['name' => $name, 'item_type' => $type]);
            $check->items()->create(['unit_item_id' => $item->id, 'condition' => ItemCondition::Working]);
        }

        return $unit;
    }

    private function listUnit(Unit $unit, string $title, int $downpayment): void
    {
        $listing = UnitListing::factory()->for($unit)->create([
            'title' => $title,
            'description' => 'Walking distance to NU Lipa and the public market. Water and electricity are billed separately.',
            'contact_name' => 'Demo Landlord',
            'contact_phone' => '09170000001',
            'contact_email' => self::LANDLORD_EMAIL,
            'downpayment_amount' => $downpayment,
        ]);
        $listing->forceFill(['status' => ListingStatus::Approved, 'submitted_at' => now()->subDays(5), 'reviewed_at' => now()->subDays(4)])->save();

        ListingPhoto::factory()->for($listing, 'listing')->create([
            'path' => $this->demoImage($title, 'listings', config('filesystems.media_disk')),
        ]);
    }

    /**
     * Juana: moved in two months ago, acknowledged the move-in check,
     * agreed to her contract, and paid all but the latest invoice.
     */
    private function settledTenant(
        Unit $unit,
        MoveTenantIntoUnit $moveTenantIntoUnit,
        GenerateLeaseContract $generateLeaseContract,
        GenerateDueInvoicesForLease $generateInvoices,
        RecordInvoicePayment $recordPayment,
    ): Lease {
        $juana = $this->tenant('Juana Dela Cruz', 'juana@demo.occuplace.test', '+639171234567');
        $lease = $moveTenantIntoUnit->handle($juana, $unit, 5, BillingTiming::Advance, StayType::LongTerm, $this->landlord);

        $movedIn = now()->subMonths(2)->startOfMonth()->addDays(4);
        $lease->forceFill(['start_date' => $movedIn])->save();
        $lease->rents()->update(['effective_date' => $movedIn]);
        $lease->moveInCheck?->acknowledge();

        $contract = $generateLeaseContract->handle($lease->fresh(), $this->landlord);
        $contract->accept('127.0.0.1');

        $invoices = $generateInvoices->handle($lease->fresh())->sortBy('due_date')->values();

        foreach ($invoices->slice(0, -1) as $invoice) {
            $recordPayment->handle($invoice, (float) $invoice->total_amount, PaymentMethod::Gcash, (string) random_int(1000000000, 9999999999));
        }

        return $lease->fresh();
    }

    /**
     * A resolved faucet report with its repair, and an open one about a light.
     */
    private function reports(Lease $lease): void
    {
        $unit = $lease->unit;
        $faucet = $unit->items()->where('name', 'Faucet, bathroom')->firstOrFail();
        $light = $unit->items()->where('name', 'Ceiling light, main room')->firstOrFail();

        $fixed = $lease->concerns()->create([
            'category' => ConcernCategory::Maintenance,
            'issue_type_id' => IssueType::query()->whereNull('team_id')->where('name', 'Leaking faucet')->value('id'),
            'unit_item_id' => $faucet->id,
            'title' => 'Leaking faucet: Faucet, bathroom',
            'description' => 'The bathroom faucet keeps dripping even when fully closed.',
            'priority' => ConcernPriority::Medium,
            'status' => ConcernStatus::Resolved,
            'resolved_at' => now()->subWeeks(3),
        ]);
        $fixed->updates()->create(['author_id' => $this->landlord->id, 'message' => 'Replaced the washer. Should be fine now.', 'new_status' => ConcernStatus::Resolved]);
        $faucet->services()->create([
            'concern_id' => $fixed->id,
            'action' => ItemServiceAction::Repaired,
            'performed_at' => now()->subWeeks(3),
            'cost' => 150,
            'notes' => 'Replaced the washer.',
            'recorded_by' => $this->landlord->id,
        ]);

        $open = $lease->concerns()->create([
            'category' => ConcernCategory::Maintenance,
            'issue_type_id' => IssueType::query()->whereNull('team_id')->where('name', 'Busted light')->value('id'),
            'unit_item_id' => $light->id,
            'title' => 'Busted light: Ceiling light, main room',
            'description' => 'The ceiling light flickers and went out last night.',
            'priority' => ConcernPriority::High,
            'status' => ConcernStatus::Pending,
        ]);

        $this->landlord->notify(new TenantReportSubmitted($open));
    }

    /**
     * One reservation in each state, all for the shared Unit 103.
     */
    private function reservations(Unit $unit): void
    {
        $listingId = $unit->listing?->id;
        $details = fn (string $first, string $last, string $email, string $number): array => [
            'unit_listing_id' => $listingId,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'desired_username' => strtolower($first).'.'.strtolower($last),
            'contact_number' => $number,
            'valid_id_path' => $this->demoImage("Valid ID of {$first} {$last} (demo)", 'reservations', config('filesystems.sensitive_disk')),
        ];
        $paid = fn (): array => [
            'payment_channel_id' => $this->channel->id,
            'downpayment_amount' => 3000,
            'downpayment_method' => PaymentMethod::Gcash,
            'downpayment_reference' => (string) random_int(1000000000, 9999999999),
            'downpayment_proof_path' => $this->demoImage('GCash receipt (demo)', 'reservations', config('filesystems.sensitive_disk')),
        ];

        $pending = Reservation::factory()->for($unit)->create($details('Carlo', 'Reyes', 'carlo@demo.occuplace.test', '+639181234567'));
        $this->landlord->notify(new ReservationSubmitted($pending));

        Reservation::factory()->for($unit)->reserved(now()->addDays(2))->create([
            ...$details('Bea', 'Garcia', 'bea@demo.occuplace.test', '+639191234567'),
            'reviewed_by' => $this->landlord->id,
            'reviewed_at' => now()->subDay(),
        ]);

        $sent = Reservation::factory()->for($unit)->reserved(now()->addDay())->create([
            ...$details('Dino', 'Mercado', 'dino@demo.occuplace.test', '+639201234567'),
            ...$paid(),
            'downpayment_submitted_at' => now()->subHours(3),
            'reviewed_by' => $this->landlord->id,
            'reviewed_at' => now()->subDays(2),
        ]);
        $this->landlord->notify(new DownpaymentSubmitted($sent));

        $ella = $this->tenant('Ella Villanueva', 'ella@demo.occuplace.test', '+639211234567');
        Reservation::factory()->for($unit)->create([
            ...$details('Ella', 'Villanueva', 'ella@demo.occuplace.test', '+639211234567'),
            ...$paid(),
        ])->forceFill([
            'status' => ReservationStatus::Confirmed,
            'expires_at' => now()->subDay(),
            'downpayment_submitted_at' => now()->subDays(3),
            'downpayment_confirmed_at' => now()->subDays(2),
            'downpayment_confirmed_by' => $this->landlord->id,
            'reviewed_by' => $this->landlord->id,
            'reviewed_at' => now()->subDays(4),
            'tenant_user_id' => $ella->id,
        ])->save();
    }

    /**
     * A tenant account the way a confirmed reservation makes one: no team of
     * their own, a Tenant membership on the demo team.
     */
    private function tenant(string $name, string $email, string $contactNumber): User
    {
        return $this->member($name, $email, TeamRole::Tenant, $contactNumber);
    }

    private function member(string $name, string $email, TeamRole $role, ?string $contactNumber = null): User
    {
        $user = (new User)->forceFill([
            'name' => $name,
            'email' => $email,
            'contact_number' => $contactNumber,
            'username' => strtolower(explode('@', $email)[0]),
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'current_team_id' => $this->team->id,
        ]);
        $user->save();

        $this->team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        $this->logins[] = [$role->label(), $name, $email];

        return $user;
    }

    /**
     * Store a plain labelled image, so demo photos, IDs and receipts open.
     *
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function demoImage(string $label, string $directory, string $disk, int $width = 800, int $height = 600): string
    {
        $path = tempnam(sys_get_temp_dir(), 'occ');
        $this->drawImage($label, $path, $width, $height);

        $stored = $directory.'/demo-'.bin2hex(random_bytes(8)).'.jpg';
        Storage::disk($disk)->put($stored, (string) file_get_contents($path));
        @unlink($path);

        return $stored;
    }

    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function drawImage(string $label, string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 240, 216, 161));
        imagestring($image, 5, 24, (int) ($height / 2) - 8, $label, (int) imagecolorallocate($image, 92, 58, 37));
        imagejpeg($image, $path, 80);
    }
}
