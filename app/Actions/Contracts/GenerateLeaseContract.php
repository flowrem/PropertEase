<?php

namespace App\Actions\Contracts;

use App\Enums\ItemCondition;
use App\Enums\StayType;
use App\Models\Amenity;
use App\Models\ConditionCheck;
use App\Models\ConditionCheckItem;
use App\Models\Lease;
use App\Models\LeaseContract;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ContractReady;
use App\Rules\PhilippineMobileNumber;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerateLeaseContract
{
    /**
     * Make the lease's contract from the landlord's current terms and the
     * lease, unit and tenant as they are now, and keep it as a snapshot: the
     * values in `terms` and the text rendered from them. A contract the
     * tenant has not agreed to yet is replaced; an agreed one never is.
     * The tenant is told it is ready to read.
     *
     * @throws ValidationException
     */
    public function handle(Lease $lease, ?User $generatedBy = null): LeaseContract
    {
        $lease->loadMissing([
            'tenant', 'contract', 'currentRent',
            'unit.property.team', 'unit.amenities',
            'moveInCheck.items.unitItem',
        ]);

        if ($lease->contract?->isAccepted()) {
            throw ValidationException::withMessages(['contract' => __('The tenant already agreed to this contract, so it cannot be replaced.')]);
        }

        $terms = $this->termsFor($lease);

        $contract = $lease->contract ?? new LeaseContract;

        $contract->forceFill([
            'lease_id' => $lease->id,
            'terms' => $terms,
            'body_html' => view('contracts.document', ['terms' => $terms])->render(),
            'generated_at' => now(),
            'generated_by' => $generatedBy?->id,
        ])->save();

        $lease->setRelation('contract', $contract);

        $lease->tenant->notify(new ContractReady($contract));

        return $contract;
    }

    /**
     * Everything the contract states, as plain values.
     *
     * @return array<string, mixed>
     */
    private function termsFor(Lease $lease): array
    {
        $unit = $lease->unit;
        $property = $unit->property;
        $team = $property->team;
        $owner = $team->owner();
        $template = $team->contractTerms();
        $rent = $lease->currentRent ? (float) $lease->currentRent->amount : $unit->rentSharePerTenant();
        $minimumStay = match ($lease->stay_type) {
            StayType::ShortTerm => $template->minimum_stay_months_short,
            StayType::LongTerm => $template->minimum_stay_months_long,
            null => null,
        };

        return [
            'generated_on' => now()->timezone(config('occuplace.display_timezone'))->format('M j, Y'),
            'landlord' => [
                'business' => $team->name,
                'name' => $owner?->name,
                'email' => $owner?->email,
                'contact_number' => $owner?->contact_number ? PhilippineMobileNumber::forDisplay($owner->contact_number) : null,
            ],
            'tenant' => [
                'name' => $lease->tenant->name,
                'email' => $lease->tenant->email,
                'contact_number' => $lease->tenant->contact_number ? PhilippineMobileNumber::forDisplay($lease->tenant->contact_number) : null,
            ],
            'property' => [
                'name' => $property->name,
                'type' => $property->type->label(),
                'address' => collect([$property->address_line, $property->city, $property->province, $property->postal_code])->filter()->implode(', '),
            ],
            'unit' => [
                'name' => $unit->unit_number,
                'floor' => $unit->floor_level,
                'floor_area' => $unit->floor_area_sqm !== null ? Unit::formatFloorArea((float) $unit->floor_area_sqm) : null,
                'bedrooms' => $unit->bedrooms,
                'bathrooms' => $unit->bathrooms,
            ],
            'amenities' => $this->amenityList($unit),
            'move_in_check' => $this->moveInCheckSummary($lease->moveInCheck, $lease->move_in_override_reason),
            'start_date' => $lease->start_date->format('M j, Y'),
            'rent' => round($rent, 2),
            'due_day' => $lease->due_day,
            'billing_timing' => $lease->billing_timing->label(),
            'stay' => [
                'type' => $lease->stay_type?->label(),
                'minimum_months' => $minimumStay,
            ],
            'advance' => ['months' => $template->advance_months, 'amount' => round($rent * $template->advance_months, 2)],
            'deposit' => ['months' => $template->deposit_months, 'amount' => round($rent * $template->deposit_months, 2)],
            'notice_days' => $template->notice_days,
            'late_fee' => $template->late_fee !== null ? (float) $template->late_fee : null,
            'house_rules' => $template->house_rules,
            'additional_terms' => $template->additional_terms,
        ];
    }

    /**
     * What the unit comes with, like "2 × Double deck" or "Wi-Fi".
     *
     * @return array<int, string>
     */
    private function amenityList(Unit $unit): array
    {
        $quantities = $unit->amenities()->pluck('amenity_unit.quantity', 'amenities.id');

        return $unit->amenities
            ->map(function (Amenity $amenity) use ($quantities): string {
                $quantity = (int) ($quantities[$amenity->id] ?? 1);

                return ($quantity > 1 ? $quantity.' × ' : '').$amenity->name;
            })
            ->values()
            ->all();
    }

    /**
     * The move-in check in a few facts: when, how many items, and any that
     * were not in working order. A tenant moved in without a check keeps the
     * landlord's reason for going ahead.
     *
     * @return array{date: string|null, item_count: int, issues: array<int, string>, override_reason: string|null}|null
     */
    private function moveInCheckSummary(?ConditionCheck $check, ?string $overrideReason): ?array
    {
        if (! $check) {
            return $overrideReason ? ['date' => null, 'item_count' => 0, 'issues' => [], 'override_reason' => $overrideReason] : null;
        }

        return [
            'date' => $check->checked_at->format('M j, Y'),
            'item_count' => $check->items->count(),
            'issues' => $check->items
                ->reject(fn (ConditionCheckItem $item): bool => in_array($item->condition, [ItemCondition::GoodAsNew, ItemCondition::Working], true))
                ->map(fn (ConditionCheckItem $item): string => Str::squish($item->unitItem->name.': '.$item->condition->label().($item->remarks ? ' ('.$item->remarks.')' : '')))
                ->values()
                ->all(),
            'override_reason' => $overrideReason,
        ];
    }
}
