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
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('advance_months')->default(1);
            $table->unsignedTinyInteger('deposit_months')->default(2);
            $table->unsignedTinyInteger('minimum_stay_months_short')->default(1);
            $table->unsignedTinyInteger('minimum_stay_months_long')->default(12);
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->decimal('late_fee', 10, 2)->nullable();
            $table->text('house_rules')->nullable();
            $table->text('additional_terms')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
