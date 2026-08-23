<?php

namespace App\Policies;

use App\Models\Analysis;
use App\Models\User;

class AnalysisPolicy
{
    public function view(User $user, Analysis $analysis): bool
    {
        return $this->ownsAnalysis($user, $analysis);
    }

    public function run(User $user, Analysis $analysis): bool
    {
        return $this->ownsAnalysis($user, $analysis);
    }

    public function createDraftPackage(User $user, Analysis $analysis): bool
    {
        return $this->ownsAnalysis($user, $analysis);
    }

    public function delete(User $user, Analysis $analysis): bool
    {
        return $this->ownsAnalysis($user, $analysis);
    }

    private function ownsAnalysis(User $user, Analysis $analysis): bool
    {
        return $analysis->user_id === $user->id
            && $analysis->workspace?->user_id === $user->id
            && $analysis->document?->user_id === $user->id
            && $analysis->document?->workspace_id === $analysis->workspace_id;
    }
}
