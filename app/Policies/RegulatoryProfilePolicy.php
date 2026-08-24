<?php

namespace App\Policies;

use App\Models\RegulatoryProfile;
use App\Models\User;

class RegulatoryProfilePolicy
{
    public function view(User $user, RegulatoryProfile $profile): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, RegulatoryProfile $profile): bool
    {
        return (bool) $user->is_admin;
    }
}
