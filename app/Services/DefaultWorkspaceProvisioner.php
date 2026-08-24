<?php

namespace App\Services;

use App\Models\RegulatoryProfile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DefaultWorkspaceProvisioner
{
    public function defaultProfile(): RegulatoryProfile
    {
        return RegulatoryProfile::query()
            ->where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)
            ->where('is_active', true)
            ->firstOrFail();
    }

    public function provision(User $user, ?Workspace $adopt = null): Workspace
    {
        return DB::transaction(function () use ($user, $adopt) {
            $profile = RegulatoryProfile::query()
                ->where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $workspace = $user->workspaces()
                ->where('regulatory_profile_id', $profile->id)
                ->first();

            if (!$workspace && $adopt) {
                if ($adopt->user_id !== $user->id || ($adopt->regulatory_profile_id && $adopt->regulatory_profile_id !== $profile->id)) {
                    throw new RuntimeException('Workspace cannot be adopted for this user and profile.');
                }

                $adopt->update(['regulatory_profile_id' => $profile->id]);
                $workspace = $adopt->fresh();
            }

            if (!$workspace) {
                $workspace = $user->workspaces()->create([
                    'regulatory_profile_id' => $profile->id,
                    'reference_number' => $this->referenceNumber(),
                    'title' => $profile->workspace_title,
                    'description' => $profile->workspace_description,
                    'category' => $profile->workspace_category,
                    'status' => 'draft',
                ]);
            }

            $sources = $profile->sources()
                ->whereNull('sources.user_id')
                ->whereNull('sources.deleted_at')
                ->get();

            $workspace->sources()->syncWithoutDetaching(
                $sources->mapWithKeys(fn ($source) => [
                    $source->id => ['is_primary' => (bool) $source->pivot->is_primary],
                ])->all(),
            );

            return $workspace->fresh('sources');
        });
    }

    private function referenceNumber(): string
    {
        return 'WS-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
    }
}
