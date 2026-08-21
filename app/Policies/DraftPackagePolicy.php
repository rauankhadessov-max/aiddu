<?php

namespace App\Policies;

use App\Models\DraftPackage;
use App\Models\User;

class DraftPackagePolicy
{
    public function view(User $user, DraftPackage $draftPackage): bool
    {
        $analysis = $draftPackage->analysis;

        return $analysis !== null
            && $analysis->user_id === $user->id
            && $analysis->workspace?->user_id === $user->id
            && $analysis->document?->user_id === $user->id;
    }
}
