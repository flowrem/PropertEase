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
        Schema::create('unit_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('contact_name');
            $table->string('contact_phone');
            $table->string('contact_email');
            $table->decimal('downpayment_amount', 10, 2)->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_listings');
    }
};
