<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\SourceVersionContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SourceController extends Controller
{
    public function index(Request $request)
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with('regulatoryProfile')
            ->withCount([
                'sources as sources_count' => fn ($query) => $query->visibleTo($request->user()),
            ])
            ->latest()
            ->get();

        return view('sources.index', compact('workspaces'));
    }

    public function create()
    {
        return redirect()
            ->route('sources.index')
            ->with('error', 'Сначала выберите рабочее дело, в которое нужно добавить НПА.');
    }

    public function store()
    {
        return redirect()
            ->route('sources.index')
            ->with('error', 'Сначала выберите рабочее дело, в которое нужно добавить НПА.');
    }

    public function show(Request $request, Source $source)
    {
        Gate::authorize('view', $source);

        $source->load([
            'versions' => fn ($query) => $query->latest('effective_date'),
        ]);

        $workspaceContext = $request->filled('workspace')
            ? $request->user()->workspaces()
                ->whereHas('sources', fn ($query) => $query->whereKey($source->id))
                ->find($request->integer('workspace'))
            : null;

        return view('sources.show', compact('source', 'workspaceContext'));
    }

    public function createVersion(Source $source)
    {
        Gate::authorize('update', $source);

        return view('sources.versions.create', compact('source'));
    }

    public function destroy(Source $source)
    {
        Gate::authorize('delete', $source);

        DB::transaction(function () use ($source) {
            $source->workspaces()->detach();
            $source->delete();
        });

        return redirect()
            ->route('sources.index')
            ->with('success', 'НПА удалён из доступной нормативной базы. Исторические анализы сохранены.');
    }

    public function storeVersion(
        Request $request,
        Source $source,
        SourceVersionContentService $contentService
    ) {
        Gate::authorize('update', $source);

        $validated = $request->validate([
            'version_name' => ['required', 'string', 'max:255'],
            'effective_date' => ['nullable', 'date'],
            'text' => ['nullable', 'string'],
            'docx_file' => ['nullable', 'file', 'mimes:docx', 'max:2048'],
        ]);

        $contentService->create(
            $source,
            $validated['version_name'],
            $validated['effective_date'] ?? null,
            $validated['text'] ?? null,
            $request->file('docx_file'),
        );

        return redirect()
            ->route('sources.show', $source)
            ->with('success', 'Редакция НПА добавлена.');
    }

    public function editVersion(Source $source, $version)
    {
        Gate::authorize('update', $source);

        $version = $source->versions()->findOrFail($version);

        return view('sources.versions.edit', compact('source', 'version'));
    }

    public function updateVersion(
        Request $request,
        Source $source,
        $version,
        SourceVersionContentService $contentService
    ) {
        Gate::authorize('update', $source);

        $version = $source->versions()->findOrFail($version);

        $validated = $request->validate([
            'version_name' => ['required', 'string', 'max:255'],
            'effective_date' => ['nullable', 'date'],
            'text' => ['nullable', 'string'],
            'docx_file' => ['nullable', 'file', 'mimes:docx', 'max:2048'],
        ]);

        $contentService->update(
            $version,
            $validated['version_name'],
            $validated['effective_date'] ?? null,
            $validated['text'] ?? null,
            $request->file('docx_file'),
        );

        return redirect()
            ->route('sources.show', $source)
            ->with('success', 'Редакция НПА обновлена.');
    }
}
