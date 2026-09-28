<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Approved" reservations were accepted with their downpayment already
 * checked, which is what "Confirmed" means now, so they keep holding their
 * slot. Every older reservation sent its payment proof with the form, so
 * those that have one are marked as having submitted it then.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('reservations')->where('status', 'approved')->update(['status' => 'confirmed']);

        DB::table('reservations')
            ->whereNotNull('downpayment_proof_path')
            ->whereNull('downpayment_submitted_at')
            ->update(['downpayment_submitted_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('reservations')->where('status', 'confirmed')->update(['status' => 'approved']);
    }
};
