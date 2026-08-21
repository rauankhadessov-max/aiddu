<?php

namespace App\Policies;

use App\Models\Artifact;
use App\Models\User;

class ArtifactPolicy
{
    public function view(User $user, Artifact $artifact): bool
    {
        $analysis = $artifact->draftPackage?->analysis;

        return $analysis !== null
            && $analysis->user_id === $user->id
            && $analysis->workspace?->user_id === $user->id
            && $analysis->document?->user_id === $user->id;
    }
}
