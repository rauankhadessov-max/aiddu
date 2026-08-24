<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use App\Services\WorkspaceSourceVersionResolver;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with('regulatoryProfile')
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
            'reference_number' => 'WS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
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
            'documents' => fn ($query) => $query->latest(),
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
            ->orderBy('title'),
    ]);
    $currentVersions = $sourceVersionResolver
        ->currentVersions(request()->user(), $workspace)
        ->keyBy('source_id');

    return view('workspaces.sources', compact('workspace', 'currentVersions'));
}

public function attachSource(Workspace $workspace, \App\Models\Source $source)
{
    Gate::authorize('update', $workspace);
    Gate::authorize('view', $source);

    $workspace->sources()->syncWithoutDetaching([$source->id]);

    return redirect()
        ->route('workspaces.show', $workspace)
        ->with('success', 'Источник подключен к рабочему делу.');
}

}
