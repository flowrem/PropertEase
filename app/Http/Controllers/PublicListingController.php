<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrowseListingsRequest;
use App\Models\Amenity;
use App\Models\PaymentChannel;
use App\Models\UnitListing;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class PublicListingController extends Controller
{
    /**
     * Guest-facing list of approved listings whose unit still has room.
     */
    public function index(BrowseListingsRequest $request): View
    {
        $filters = $request->validated();
        $search = trim((string) ($filters['q'] ?? ''));

        $listings = UnitListing::query()
            ->publiclyVisible()
            ->with([
                'photos',
                'unit' => fn ($unit) => $unit->withCount(['activeLeases', 'heldReservations'])->withBedSpaces()->with('property'),
            ])
            ->when($search !== '', function (Builder $query) use ($search) {
                $term = '%'.addcslashes($search, '\\%_').'%';

                $query->whereHas('unit.property', fn (Builder $property) => $property
                    ->where('city', 'like', $term)
                    ->orWhere('province', 'like', $term));
            })
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query
                ->whereHas('unit.property', fn (Builder $property) => $property->where('type', $type)))
            ->when($filters['max_price'] ?? null, fn (Builder $query, $maxPrice) => $query
                ->whereHas('unit', fn (Builder $unit) => $unit->where('price', '<=', $maxPrice)))
            ->when($filters['amenities'] ?? [], function (Builder $query, array $amenityIds) {
                foreach ($amenityIds as $amenityId) {
                    $query->whereHas('unit.amenities', fn (Builder $amenity) => $amenity->whereKey($amenityId));
                }
            })
            ->latest('reviewed_at')
            ->paginate(12)
            ->withQueryString();

        return view('listings.index', [
            'listings' => $listings,
            'filters' => $filters,
            'amenityFilters' => $this->amenityFilters(),
        ]);
    }

    /**
     * The platform amenities guests can filter by, grouped by category
     * label. Teams name their own amenities differently, so only the
     * shared platform list makes a useful filter.
     *
     * @return Collection<string, EloquentCollection<int, Amenity>>
     */
    private function amenityFilters(): Collection
    {
        return Amenity::groupByCategory(
            Amenity::query()->whereNull('team_id')->active()->orderBy('name')->get(),
        );
    }

    /**
     * A single listing. Anything not publicly visible is a plain 404 so drafts
     * and rejected listings cannot be detected.
     */
    public function show(int $listing): View
    {
        $listing = UnitListing::query()
            ->publiclyVisible()
            ->with([
                'photos',
                'unit' => fn ($unit) => $unit->withCount(['activeLeases', 'heldReservations'])->withBedSpaces()->with(['property.team', 'amenities' => fn ($amenities) => $amenities->orderBy('name')]),
            ])
            ->findOrFail($listing);

        $paymentMethods = PaymentChannel::query()
            ->where('team_id', $listing->unit->property->team_id)
            ->active()
            ->get()
            ->map(fn (PaymentChannel $channel) => $channel->method->label())
            ->unique()
            ->values();

        return view('listings.show', [
            'listing' => $listing,
            'paymentMethods' => $paymentMethods,
        ]);
    }
}
