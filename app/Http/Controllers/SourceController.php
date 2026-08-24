<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceRequest;
use App\Models\Source;
use App\Services\SourceCreationService;
use App\Services\SourceVersionContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SourceController extends Controller
{
    public function index()
    {
        $sources = Source::visibleTo(request()->user())
            ->withCount('versions')
            ->latest()
            ->get();

        return view('sources.index', compact('sources'));
    }

    public function create()
    {
        Gate::authorize('create', Source::class);

        return view('sources.create');
    }

    public function store(StoreSourceRequest $request, SourceCreationService $creationService)
    {
        Gate::authorize('create', Source::class);

        $validated = $request->validated();
        $source = $request->user()->is_admin
            ? $creationService->createGlobal($validated, $request->file('docx_file'))
            : $creationService->createPersonal($request->user(), $validated, $request->file('docx_file'));

        return redirect()
            ->route('sources.show', $source)
            ->with(
                'success',
                $validated['input_method'] === 'docx'
                    ? 'НПА и редакция нормативного текста добавлены.'
                    : 'Ссылка сохранена. Добавьте редакцию нормативного текста, чтобы использовать НПА в юридическом анализе.',
            );
    }

    public function show(Source $source)
    {
        Gate::authorize('view', $source);

        $source->load([
            'versions' => fn ($query) => $query->latest('effective_date'),
        ]);

        return view('sources.show', compact('source'));
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
