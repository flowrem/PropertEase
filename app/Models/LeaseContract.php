<?php

namespace App\Models;

use Database\Factories\LeaseContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The contract of one lease, kept as it was made: the rendered text and the
 * values it was made from. Editing the landlord's terms, the unit or the
 * rent later never changes it. Nothing here is fillable; it is written
 * only by GenerateLeaseContract and accept().
 *
 * @property int $id
 * @property int $lease_id
 * @property string $body_html
 * @property array<string, mixed> $terms
 * @property Carbon $generated_at
 * @property int|null $generated_by
 * @property Carbon|null $tenant_accepted_at
 * @property string|null $tenant_accepted_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lease $lease
 */
class LeaseContract extends Model
{
    /** @use HasFactory<LeaseContractFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function isAccepted(): bool
    {
        return $this->tenant_accepted_at !== null;
    }

    /**
     * Record that the tenant agreed to this contract, and from where.
     */
    public function accept(string $ipAddress): void
    {
        $this->forceFill([
            'tenant_accepted_at' => now(),
            'tenant_accepted_ip' => $ipAddress,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'generated_at' => 'datetime',
            'tenant_accepted_at' => 'datetime',
        ];
    }
}
