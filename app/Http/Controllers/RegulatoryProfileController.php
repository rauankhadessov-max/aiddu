<?php

namespace App\Http\Controllers;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Services\RegulatoryProfileWorkspaceSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegulatoryProfileController extends Controller
{
    public function edit()
    {
        $profile = $this->profile();
        Gate::authorize('view', $profile);

        $sources = Source::query()->whereNull('user_id')->orderBy('title')->get();
        $profile->load('sources');

        return view('regulatory-profiles.edit', compact('profile', 'sources'));
    }

    public function update(Request $request, RegulatoryProfileWorkspaceSynchronizer $profileSynchronizer)
    {
        $profile = $this->profile();
        Gate::authorize('update', $profile);

        $validated = $request->validate([
            'source_ids' => ['nullable', 'array'],
            'source_ids.*' => ['required', 'integer', 'distinct', 'exists:sources,id'],
        ]);

        $ids = collect($validated['source_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $globalCount = Source::query()->whereNull('user_id')->whereIn('id', $ids)->count();

        if ($globalCount !== $ids->count()) {
            throw ValidationException::withMessages(['source_ids' => 'В стартовый профиль можно включать только глобальные НПА.']);
        }

        DB::transaction(function () use ($profile, $ids, $profileSynchronizer) {
            $profile->sources()->sync($ids->mapWithKeys(fn ($id, $index) => [
                $id => ['sort_order' => $index, 'is_primary' => $index === 0],
            ])->all());

            $profileSynchronizer->sync($profile->fresh());
        });

        return back()->with('success', 'Состав стартового нормативного профиля сохранён.');
    }

    private function profile(): RegulatoryProfile
    {
        abort_unless(Schema::hasTable('regulatory_profiles'), 503, 'Regulatory profiles are not available until the pending migration is applied.');

        return RegulatoryProfile::query()
            ->where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)
            ->firstOrFail();
    }
}
