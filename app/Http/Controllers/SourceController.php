<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Http\Request;
use App\Services\DocxTextExtractor;
use Illuminate\Support\Facades\Storage;


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
        return view('sources.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:100'],
            'number' => ['nullable', 'string', 'max:100'],
            'adoption_date' => ['nullable', 'date'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:100'],
            'official_url' => ['nullable', 'url', 'max:1000'],
            'description' => ['nullable', 'string'],
        ]);

        $source = Source::create($validated);

        return redirect()
            ->route('sources.show', $source)
            ->with('success', 'Нормативный источник создан.');
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
        return view('sources.versions.create', compact('source'));
    }

    public function storeVersion(
    Request $request,
    Source $source,
    DocxTextExtractor $docxTextExtractor
)
{
    $validated = $request->validate([
        'version_name' => ['required', 'string', 'max:255'],
        'effective_date' => ['nullable', 'date'],
        'text' => ['nullable', 'string'],
        'docx_file' => ['nullable', 'file', 'mimes:docx', 'max:2048'],
    ]);

    if (!$request->hasFile('docx_file') && empty($validated['text'])) {
        return back()
            ->withErrors([
                'text' => 'Введите текст редакции или загрузите DOCX-файл.',
            ])
            ->withInput();
    }

    $text = $validated['text'] ?? null;

    if ($request->hasFile('docx_file')) {
        $file = $request->file('docx_file');

        $storedPath = $file->store('source_versions', 'local');

        $absolutePath = Storage::disk('local')->path($storedPath);

        $text = $docxTextExtractor->extract($absolutePath);
    }

    $normalizedText = trim($text);

    $source->versions()->create([
        'version_name' => $validated['version_name'],
        'effective_date' => $validated['effective_date'] ?? null,
        'text' => $normalizedText,
        'hash' => hash('sha256', $normalizedText),
    ]);

    return redirect()
        ->route('sources.show', $source)
        ->with('success', 'Редакция НПА добавлена.');
}

public function editVersion(Source $source, $version)
{
    $version = $source->versions()->findOrFail($version);

    return view('sources.versions.edit', compact('source', 'version'));
}

public function updateVersion(
    Request $request,
    Source $source,
    $version,
    DocxTextExtractor $docxTextExtractor
)
{
    $version = $source->versions()->findOrFail($version);

    $validated = $request->validate([
        'version_name' => ['required', 'string', 'max:255'],
        'effective_date' => ['nullable', 'date'],
        'text' => ['nullable', 'string'],
        'docx_file' => ['nullable', 'file', 'mimes:docx', 'max:2048'],
    ]);

    if (!$request->hasFile('docx_file') && empty($validated['text'])) {
        return back()
            ->withErrors([
                'text' => 'Введите текст редакции или загрузите DOCX-файл.',
            ])
            ->withInput();
    }

    $text = $validated['text'] ?? null;

    if ($request->hasFile('docx_file')) {
        $file = $request->file('docx_file');

        $storedPath = $file->store('source_versions', 'local');

        $absolutePath = Storage::disk('local')->path($storedPath);

        $text = $docxTextExtractor->extract($absolutePath);
    }

    $normalizedText = trim($text);

    $version->update([
        'version_name' => $validated['version_name'],
        'effective_date' => $validated['effective_date'] ?? null,
        'text' => $normalizedText,
        'hash' => hash('sha256', $normalizedText),
    ]);

    return redirect()
        ->route('sources.show', $source)
        ->with('success', 'Редакция НПА обновлена.');
}
}
