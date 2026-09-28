<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('contact_number', 13)->nullable()->after('last_name');
            $table->string('stay_type')->nullable()->after('address');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('contact_number', 13)->nullable()->after('email');
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->string('stay_type')->nullable()->after('billing_timing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('stay_type');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contact_number');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['contact_number', 'stay_type']);
        });
    }
};
