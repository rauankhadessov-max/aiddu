<?php

namespace App\Services;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

class DefaultWorkspaceResolver
{
    public function resolve(User $user, ?int $explicitWorkspaceId = null): ?Workspace
    {
        if ($explicitWorkspaceId !== null) {
            return $user->workspaces()->find($explicitWorkspaceId);
        }

        if (!Source::supportsOwnership()
            || !Schema::hasTable('regulatory_profiles')
            || !Schema::hasColumn('workspaces', 'regulatory_profile_id')) {
            return null;
        }

        return $user->workspaces()
            ->whereHas('regulatoryProfile', fn ($query) => $query
                ->where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)
                ->where('is_active', true))
            ->first();
    }
}
