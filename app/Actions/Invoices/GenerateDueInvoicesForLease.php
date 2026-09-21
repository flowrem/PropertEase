<?php

namespace App\Actions\Invoices;

use App\Enums\BillingTiming;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Models\Invoice;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class GenerateDueInvoicesForLease
{
    /**
     * Generate every invoice this lease owes up to the given date (today by
     * default): a prorated stub for its first partial cycle, then one
     * invoice per full billing cycle since, stopping once the next cycle
     * hasn't started yet. Safe to call repeatedly - already-generated
     * cycles are never duplicated.
     *
     * @return Collection<int, Invoice>
     */
    public function handle(Lease $lease, ?CarbonInterface $asOf = null): Collection
    {
        if ($lease->status !== LeaseStatus::Active) {
            return collect();
        }

        $today = CarbonImmutable::parse($asOf ?? today())->startOfDay();

        $lastInvoice = $lease->invoices()->orderByDesc('billing_start')->first();

        $cycleStart = CarbonImmutable::parse($lastInvoice
            ? $lastInvoice->billing_end->addDay()
            : $lease->start_date);

        $isFirstCycle = $lastInvoice === null;
        $created = collect();

        while ($cycleStart->lessThanOrEqualTo($today)) {
            $cycleEnd = $lease->nextDueDateAfter($cycleStart)->subDay();

            $created->push($this->createInvoice($lease, $cycleStart, $cycleEnd, $isFirstCycle));

            $cycleStart = $cycleEnd->addDay();
            $isFirstCycle = false;
        }

        return $created;
    }

    /**
     * Create the invoice for a single billing cycle. The due date falls at
     * the start of the cycle when billed in advance, or at its end when
     * billed in arrears; the first cycle's rent is prorated for whatever
     * partial period it actually covers.
     */
    protected function createInvoice(Lease $lease, CarbonImmutable $cycleStart, CarbonImmutable $cycleEnd, bool $isFirstCycle): Invoice
    {
        $dueDate = $lease->billing_timing === BillingTiming::Advance
            ? $cycleStart
            : $cycleEnd->addDay();

        $rent = $lease->rentEffectiveOn($cycleStart);
        $monthlyRent = $rent ? (float) $rent->amount : 0.0;

        $rentAmount = $isFirstCycle
            ? $this->proratedRent($monthlyRent, $cycleStart, $cycleEnd)
            : $monthlyRent;

        return $lease->invoices()->create([
            'billing_start' => $cycleStart,
            'billing_end' => $cycleEnd,
            'rent_amount' => $rentAmount,
            'service_amount' => 0,
            'penalty_amount' => 0,
            'total_amount' => $rentAmount,
            'due_date' => $dueDate,
            'status' => InvoiceStatus::Unpaid,
        ]);
    }

    /**
     * Standard daily proration: the monthly rent divided across the number
     * of days in the cycle's calendar month, times the days actually owed.
     */
    protected function proratedRent(float $monthlyRent, CarbonImmutable $cycleStart, CarbonImmutable $cycleEnd): float
    {
        $daysOwed = $cycleStart->diffInDays($cycleEnd) + 1;

        return round($monthlyRent / $cycleStart->daysInMonth * $daysOwed, 2);
    }
}
