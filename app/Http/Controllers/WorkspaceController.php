<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $workspaces = $request->user()
            ->workspaces()
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
        abort_unless($workspace->user_id === $request->user()->id, 403);

        $workspace->load([
            'documents',
            'sources.versions',
            'analyses',
        ]);

        return view('workspaces.show', compact('workspace'));
    }

public function sources(Workspace $workspace)
{
    $sources = \App\Models\Source::with('versions')
        ->latest()
        ->get();

    $workspace->load('sources');

    return view('workspaces.sources', compact('workspace', 'sources'));
}

public function attachSource(Workspace $workspace, \App\Models\Source $source)
{
    $workspace->sources()->syncWithoutDetaching([$source->id]);

    return redirect()
        ->route('workspaces.show', $workspace)
        ->with('success', 'Источник подключен к рабочему делу.');
}

}
