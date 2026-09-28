<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $verification_id_path
 * @property Carbon|null $verification_submitted_at
 * @property Carbon|null $verification_id_pruned_at
 * @property int $reservation_hold_days
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, PaymentChannel> $paymentChannels
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, User> $members
 * @property-read ContractTemplate|null $contractTemplate
 */
#[Fillable(['name', 'slug', 'is_personal'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reservation_hold_days' => 3,
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Whether a Super Admin has approved this landlord team.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Whether this team was turned down and is waiting for a new ID.
     */
    public function isRejected(): bool
    {
        return $this->approved_at === null && $this->rejected_at !== null;
    }

    /**
     * Whether this team's ID is waiting for a Super Admin to review it.
     */
    public function isAwaitingReview(): bool
    {
        return $this->approved_at === null && $this->rejected_at === null;
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?User
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get all properties owned by this team.
     *
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function pendingReservationsCount(): int
    {
        return $this->reservations()->pending()->count();
    }

    /**
     * Get the ways this team accepts online downpayments.
     *
     * @return HasMany<PaymentChannel, $this>
     */
    public function paymentChannels(): HasMany
    {
        return $this->hasMany(PaymentChannel::class);
    }

    /**
     * @return HasOne<ContractTemplate, $this>
     */
    public function contractTemplate(): HasOne
    {
        return $this->hasOne(ContractTemplate::class);
    }

    /**
     * The team's contract terms, or the defaults (not yet saved) when the
     * landlord has never changed them.
     */
    public function contractTerms(): ContractTemplate
    {
        return $this->contractTemplate ?? $this->contractTemplate()->make();
    }

    /**
     * Get the amenities this team added on top of the platform defaults.
     *
     * @return HasMany<Amenity, $this>
     */
    public function amenities(): HasMany
    {
        return $this->hasMany(Amenity::class);
    }

    /**
     * Get the issue types this team added on top of the platform defaults.
     *
     * @return HasMany<IssueType, $this>
     */
    public function issueTypes(): HasMany
    {
        return $this->hasMany(IssueType::class);
    }

    /**
     * Get all billable services this team offers.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'verification_submitted_at' => 'datetime',
            'verification_id_pruned_at' => 'datetime',
            'reservation_hold_days' => 'integer',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
