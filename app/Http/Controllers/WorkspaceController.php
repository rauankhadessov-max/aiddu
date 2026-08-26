<?php

namespace App\Http\Controllers;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\WorkspaceSourceVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with([
                'regulatoryProfile',
                'sources' => fn ($query) => $query
                    ->visibleTo($request->user())
                    ->orderByDesc('workspace_sources.is_primary')
                    ->orderBy('sources.title'),
            ])
            ->withCount([
                'analyses',
                'sources as sources_count' => fn ($query) => $query->visibleTo($request->user()),
            ])
            ->orderByRaw(
                'CASE WHEN EXISTS (
                    SELECT 1
                    FROM regulatory_profiles
                    WHERE regulatory_profiles.id = workspaces.regulatory_profile_id
                      AND regulatory_profiles.is_active = ?
                      AND regulatory_profiles.purpose = ?
                ) THEN 0 ELSE 1 END',
                [true, RegulatoryProfile::NEW_USER_DEFAULT],
            )
            ->latest()
            ->get();

        return view('workspaces.index', compact('workspaces'));
    }

    public function create()
    {
        return view('workspaces.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:100'],
        ]);

        $workspace = Workspace::create([
            'user_id' => $request->user()->id,
            'reference_number' => 'WS-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'],
            'status' => 'draft',
        ]);

        return redirect()
            ->route('workspaces.show', $workspace)
            ->with('success', 'Рабочее дело создано.');
    }

    public function show(Request $request, Workspace $workspace)
    {
        Gate::authorize('view', $workspace);

        $workspace->load([
            'regulatoryProfile',
            'documents' => fn ($query) => $query->latest(),
            'sources' => fn ($query) => $query
                ->visibleTo($request->user())
                ->orderByDesc('workspace_sources.is_primary')
                ->orderBy('sources.title'),
            'sources.versions',
            'analyses' => fn ($query) => $query->latest(),
        ]);

        return view('workspaces.show', compact('workspace'));
    }

    public function sources(Workspace $workspace, WorkspaceSourceVersionResolver $sourceVersionResolver)
    {
        Gate::authorize('view', $workspace);

        $workspace->load([
            'regulatoryProfile',
            'sources' => fn ($query) => $query
                ->visibleTo(request()->user())
                ->orderByDesc('workspace_sources.is_primary')
                ->orderBy('sources.title'),
        ]);
        $currentVersions = $sourceVersionResolver
            ->currentVersions(request()->user(), $workspace)
            ->keyBy('source_id');

        return view('workspaces.sources', compact('workspace', 'currentVersions'));
    }

    public function attachSource(Workspace $workspace, Source $source)
    {
        Gate::authorize('update', $workspace);
        Gate::authorize('view', $source);

        $workspace->sources()->syncWithoutDetaching([$source->id]);

        return redirect()
            ->route('workspaces.show', $workspace)
            ->with('success', 'Источник подключен к рабочему делу.');
    }
}
