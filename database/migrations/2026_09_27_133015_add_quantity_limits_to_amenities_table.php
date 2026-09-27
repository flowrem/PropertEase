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
        Schema::table('amenities', function (Blueprint $table) {
            $table->string('quantity_basis')->default('per_tenant')->after('sleeps');
            $table->unsignedTinyInteger('quantity_per')->default(1)->after('quantity_basis');
            $table->decimal('footprint_sqm', 4, 2)->nullable()->after('quantity_per');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn(['quantity_basis', 'quantity_per', 'footprint_sqm']);
        });
    }
};
