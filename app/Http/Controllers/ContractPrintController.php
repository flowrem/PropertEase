<?php

namespace App\Http\Controllers;

use App\Models\LeaseContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ContractPrintController extends Controller
{
    /**
     * A plain page with just the contract, for the browser's Print or Save
     * as PDF. Only contracts of the current team's units are found, and
     * only the lease's tenant or the landlord's side may open them.
     */
    public function __invoke(Request $request, string $currentTeam, int $contract): View
    {
        $record = LeaseContract::query()
            ->whereHas('lease.unit.property', fn ($properties) => $properties->where('team_id', $request->user()->current_team_id))
            ->with('lease.tenant')
            ->findOrFail($contract);

        Gate::authorize('view', $record);

        return view('contracts.print', ['contract' => $record]);
    }
}
