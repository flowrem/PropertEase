<?php

namespace App\Policies;

use App\Models\IssueType;
use App\Models\Team;
use App\Models\User;

class IssueTypePolicy
{
    public function create(User $user, Team $team): bool
    {
        return $user->canManageListingsOn($team);
    }

    /**
     * Only a team's own issue types can be changed, and only by its landlord
     * or managers. Platform defaults belong to no team, so nobody can.
     */
    public function update(User $user, IssueType $issueType): bool
    {
        return $issueType->team !== null && $user->canManageListingsOn($issueType->team);
    }
}
