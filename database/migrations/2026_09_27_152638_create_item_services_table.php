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
        Schema::create('item_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concern_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->date('performed_at');
            $table->decimal('cost', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['unit_item_id', 'performed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_services');
    }
};
