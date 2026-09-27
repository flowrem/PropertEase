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
        Schema::table('concerns', function (Blueprint $table) {
            $table->foreignId('issue_type_id')->nullable()->after('category')->constrained()->nullOnDelete();
            $table->foreignId('unit_item_id')->nullable()->after('issue_type_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_item_id');
            $table->dropConstrainedForeignId('issue_type_id');
        });
    }
};
