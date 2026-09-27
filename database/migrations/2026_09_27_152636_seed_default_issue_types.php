<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the platform's default issue types, which every tenant can report.
 * Done in a migration rather than a seeder so production gets them on
 * deploy, and written with the query builder so later model changes can't
 * break it.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $defaults = [
        'Broken glass',
        'Busted light',
        'Leaking faucet',
        'Clogged drain',
        'No water',
        'No electricity',
        'Broken lock',
        'Appliance not working',
        'Pest problem',
        'Other',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $existing = DB::table('issue_types')->whereNull('team_id')->pluck('name')->all();

        DB::table('issue_types')->insert(
            collect($this->defaults)
                ->reject(fn (string $name): bool => in_array($name, $existing, true))
                ->map(fn (string $name): array => [
                    'team_id' => null,
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('issue_types')->whereNull('team_id')->whereIn('name', $this->defaults)->delete();
    }
};
