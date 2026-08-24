<?php

namespace App\Services;

use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WorkspaceSourceVersionResolver
{
    public function eligibleQuery(User $user, Workspace $workspace): Builder
    {
        if ((int) $workspace->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'workspace_id' => 'Выбранное рабочее дело недоступно.',
            ]);
        }

        return SourceVersion::query()
            ->select('source_versions.*')
            ->join('sources', 'sources.id', '=', 'source_versions.source_id')
            ->join('workspace_sources', 'workspace_sources.source_id', '=', 'sources.id')
            ->where('workspace_sources.workspace_id', $workspace->id)
            ->when(Source::supportsOwnership(), function (Builder $query) use ($user) {
                $query->whereNull('sources.deleted_at')
                    ->where(function (Builder $query) use ($user) {
                        $query->whereNull('sources.user_id')
                            ->orWhere('sources.user_id', $user->id);
                    });
            })
            ->distinct();
    }

    public function resolveForRun(User $user, Workspace $workspace, array $explicitIds): Collection
    {
        $explicitIds = $this->normalizeIds($explicitIds);

        if ($explicitIds->isNotEmpty()) {
            return $this->validateExplicitSelection($user, $workspace, $explicitIds->all());
        }

        $resolved = $this->currentVersionIds($user, $workspace);

        if ($resolved->isEmpty()) {
            throw ValidationException::withMessages([
                'source_versions' => 'В выбранном рабочем деле нет редакций НПА, доступных для анализа. Добавьте нормативный текст или подключите источник.',
            ]);
        }

        return $resolved;
    }

    public function validateExplicitSelection(User $user, Workspace $workspace, array $ids): Collection
    {
        $ids = $this->normalizeIds($ids);

        if ($ids->isEmpty()) {
            return $ids;
        }

        $allowedIds = $this->eligibleQuery($user, $workspace)
            ->whereIn('source_versions.id', $ids->all())
            ->pluck('source_versions.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($allowedIds->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'source_versions' => 'Можно использовать только редакции источников, подключённых к выбранному рабочему делу.',
            ]);
        }

        return $ids;
    }

    public function currentVersionIds(User $user, Workspace $workspace): Collection
    {
        return $this->currentVersions($user, $workspace)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function currentVersions(User $user, Workspace $workspace): Collection
    {
        $today = now()->startOfDay();

        return $this->eligibleQuery($user, $workspace)
            ->orderBy('source_versions.source_id')
            ->orderByDesc('source_versions.effective_date')
            ->orderByDesc('source_versions.id')
            ->get()
            ->groupBy('source_id')
            ->map(function (Collection $versions) use ($today) {
                $effective = $versions
                    ->filter(fn (SourceVersion $version) => $version->effective_date?->lte($today))
                    ->sort(function (SourceVersion $left, SourceVersion $right) {
                        $dateComparison = $right->effective_date->getTimestamp() <=> $left->effective_date->getTimestamp();

                        return $dateComparison !== 0
                            ? $dateComparison
                            : $right->id <=> $left->id;
                    })
                    ->first();

                if ($effective) {
                    return $effective;
                }

                if ($versions->every(fn (SourceVersion $version) => $version->effective_date === null)) {
                    return $versions->sortByDesc('id')->first();
                }

                return null;
            })
            ->filter()
            ->values();
    }

    private function normalizeIds(array $ids): Collection
    {
        return collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
    }
}
