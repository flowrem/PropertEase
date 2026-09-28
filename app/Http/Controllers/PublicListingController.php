<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrowseListingsRequest;
use App\Models\Amenity;
use App\Models\City;
use App\Models\PaymentChannel;
use App\Models\Property;
use App\Models\Region;
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
        $regionCode = $filters['region'] ?? null;
        $cityCode = $regionCode ? ($filters['city'] ?? null) : null;

        // A city from another region (the region changed) is dropped.
        if ($cityCode && ! City::query()->whereKey($cityCode)->where('region_code', $regionCode)->exists()) {
            $cityCode = null;
        }

        $listings = UnitListing::query()
            ->publiclyVisible()
            ->with([
                'photos',
                'unit' => fn ($unit) => $unit->withCount(['activeLeases', 'heldReservations', 'incomingTransfers'])->withBedSpaces()->with('property'),
            ])
            ->when($search !== '', function (Builder $query) use ($search) {
                $term = '%'.addcslashes($search, '\\%_').'%';

                $query->whereHas('unit.property', fn (Builder $property) => $property->where(fn (Builder $place) => $place
                    ->where('city', 'like', $term)
                    ->orWhere('province', 'like', $term)
                    ->orWhere('address_line', 'like', $term)));
            })
            ->when($regionCode, fn (Builder $query, string $code) => $query
                ->whereHas('unit.property', fn (Builder $property) => $property->where('region_code', $code)))
            ->when($cityCode, fn (Builder $query, string $code) => $query
                ->whereHas('unit.property', fn (Builder $property) => $property->where('city_code', $code)))
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
            'regions' => Region::query()->orderBy('code')->get(),
            'regionCode' => $regionCode,
            'cityCode' => $cityCode,
            'cityOptions' => $regionCode ? $this->citiesWithListings($regionCode) : new EloquentCollection,
        ]);
    }

    /**
     * The region's cities and municipalities that have a listing someone
     * could reserve right now, so the City filter never leads nowhere.
     *
     * @return EloquentCollection<int, City>
     */
    private function citiesWithListings(string $regionCode): EloquentCollection
    {
        return City::query()
            ->whereIn('code', Property::query()
                ->where('region_code', $regionCode)
                ->whereNotNull('city_code')
                ->whereHas('units.listing', fn (Builder $listing) => $listing->publiclyVisible())
                ->select('city_code'))
            ->orderBy('name')
            ->get();
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
                'unit' => fn ($unit) => $unit->withCount(['activeLeases', 'heldReservations', 'incomingTransfers'])->withBedSpaces()->with(['property.team', 'amenities' => fn ($amenities) => $amenities->orderBy('name')]),
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
