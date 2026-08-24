<?php

namespace App\Services;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class RegulatoryProfileWorkspaceSynchronizer
{
    public function attachGlobalSource(RegulatoryProfile $profile, Source $source): void
    {
        if (!$source->isGlobal()) {
            throw new LogicException('Only global sources can be attached to a regulatory profile.');
        }

        if (!$profile->sources()->whereKey($source->id)->exists()) {
            $currentMax = DB::table('regulatory_profile_sources')
                ->where('regulatory_profile_id', $profile->id)
                ->max('sort_order');
            $nextSortOrder = $currentMax === null ? 0 : ((int) $currentMax) + 1;

            $profile->sources()->syncWithoutDetaching([
                $source->id => [
                    'sort_order' => $nextSortOrder,
                    'is_primary' => false,
                ],
            ]);
        }

        $this->sync($profile->fresh());
    }

    public function sync(RegulatoryProfile $profile): void
    {
        $sources = $this->profileSources($profile);

        $profile->workspaces()
            ->select('workspaces.*')
            ->orderBy('workspaces.id')
            ->chunkById(100, function (Collection $workspaces) use ($sources) {
                foreach ($workspaces as $workspace) {
                    $this->syncWorkspaceWithSources($workspace, $sources);
                }
            });
    }

    public function syncWorkspace(RegulatoryProfile $profile, Workspace $workspace): void
    {
        if ((int) $workspace->regulatory_profile_id !== (int) $profile->id) {
            throw new LogicException('Workspace is not linked to the regulatory profile.');
        }

        $this->syncWorkspaceWithSources($workspace, $this->profileSources($profile));
    }

    private function profileSources(RegulatoryProfile $profile): Collection
    {
        return $profile->sources()
            ->whereNull('sources.user_id')
            ->whereNull('sources.deleted_at')
            ->get();
    }

    private function syncWorkspaceWithSources(Workspace $workspace, Collection $sources): void
    {
        $workspace->sources()->syncWithoutDetaching(
            $sources->mapWithKeys(fn (Source $source) => [
                $source->id => ['is_primary' => (bool) $source->pivot->is_primary],
            ])->all(),
        );
    }
}
