<?php

namespace App\Policies;

use App\Enums\ConditionCheckKind;
use App\Models\ConditionCheck;
use App\Models\User;

class ConditionCheckPolicy
{
    /**
     * Only the tenant whose lease claimed a move-in check can acknowledge it.
     */
    public function acknowledge(User $user, ConditionCheck $check): bool
    {
        return $check->kind === ConditionCheckKind::MoveIn
            && $check->lease !== null
            && $check->lease->tenant_id === $user->id;
    }
}
