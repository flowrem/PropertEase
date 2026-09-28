<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reservation now holds the unit for a few days after the landlord accepts
 * it, and the downpayment comes afterwards, so its payment details start
 * empty. Making those columns nullable drops no data.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedTinyInteger('reservation_hold_days')->default(3);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->timestamp('downpayment_submitted_at')->nullable()->after('downpayment_proof_path');
            $table->timestamp('expired_at')->nullable()->after('expires_at');

            $table->decimal('downpayment_amount', 10, 2)->nullable()->change();
            $table->string('downpayment_method')->nullable()->change();
            $table->string('downpayment_reference', 50)->nullable()->change();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['expires_at', 'downpayment_submitted_at', 'expired_at']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('reservation_hold_days');
        });
    }
};
