<?php

namespace App\Policies;

use App\Models\OdometerClaim;
use App\Models\User;

class OdometerClaimPolicy
{
    /**
     * Only the administrator's review queue decides outcomes.
     */
    public function review(User $user, OdometerClaim $odometerClaim): bool
    {
        return $user->isAdmin();
    }
}
