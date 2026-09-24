<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('verification_id_path')->nullable();
            $table->timestamp('verification_submitted_at')->nullable();
            $table->timestamp('verification_id_pruned_at')->nullable();
        });

        // Every landlord that existed before verification was required stays approved.
        DB::table('teams')->update(['approved_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'approved_at',
                'rejected_at',
                'rejection_reason',
                'verification_id_path',
                'verification_submitted_at',
                'verification_id_pruned_at',
            ]);
        });
    }
};
