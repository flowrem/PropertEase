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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('unit_listing_id')->nullable()->constrained('unit_listings')->nullOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('desired_username', 30);
            $table->string('email');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->unsignedTinyInteger('age');
            $table->string('address', 500);
            $table->string('valid_id_path');
            $table->decimal('downpayment_amount', 10, 2);
            $table->foreignId('payment_channel_id')->nullable()->constrained('payment_channels')->nullOnDelete();
            $table->string('downpayment_method');
            $table->string('downpayment_reference', 50);
            $table->string('downpayment_proof_path')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('tenant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consented_at');
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index('desired_username');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
