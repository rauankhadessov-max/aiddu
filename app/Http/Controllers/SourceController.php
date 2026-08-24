<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\SourceVersionContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    public function index()
    {
        $sources = Source::withCount('versions')
            ->latest()
            ->get();

        return view('sources.index', compact('sources'));
    }

    public function create()
    {
        Gate::authorize('create', Source::class);

        return view('sources.create');
    }

    public function store(Request $request, SourceVersionContentService $contentService)
    {
        Gate::authorize('create', Source::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                'law',
                'code',
                'government_resolution',
                'order',
                'rules',
                'methodology',
                'other',
            ])],
            'input_method' => ['required', Rule::in(['docx', 'url'])],
            'docx_file' => ['nullable', 'required_if:input_method,docx', 'file', 'mimes:docx', 'max:2048'],
            'official_url' => ['nullable', 'required_if:input_method,url', 'url', 'max:1000'],
        ], [
            'title.required' => 'Укажите название НПА.',
            'type.required' => 'Выберите вид НПА.',
            'type.in' => 'Выбран недопустимый вид НПА.',
            'input_method.required' => 'Выберите источник нормативного текста.',
            'docx_file.required_if' => 'Выберите DOCX-файл нормативного акта.',
            'docx_file.mimes' => 'Можно загрузить только файл в формате DOCX.',
            'docx_file.max' => 'Размер DOCX-файла не должен превышать 2 МБ.',
            'official_url.required_if' => 'Укажите официальную ссылку на НПА.',
            'official_url.url' => 'Укажите корректную официальную ссылку.',
        ]);

        $source = DB::transaction(function () use ($request, $validated, $contentService) {
            $source = Source::create([
                'title' => $validated['title'],
                'type' => $validated['type'],
                'status' => 'active',
                'official_url' => $validated['input_method'] === 'url'
                    ? $validated['official_url']
                    : null,
            ]);

            if ($validated['input_method'] === 'docx') {
                $contentService->create(
                    $source,
                    'Редакция из загруженного DOCX',
                    null,
                    null,
                    $request->file('docx_file'),
                );
            }

            return $source;
        });

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
