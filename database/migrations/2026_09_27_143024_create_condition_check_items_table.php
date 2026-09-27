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
        Schema::create('condition_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condition_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_item_id')->constrained()->cascadeOnDelete();
            $table->string('condition');
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['condition_check_id', 'unit_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('condition_check_items');
    }
};
