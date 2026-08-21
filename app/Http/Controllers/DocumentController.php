<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function create(Request $request, Workspace $workspace)
    {
        Gate::authorize('update', $workspace);

        return view('documents.create', compact('workspace'));
    }

    public function store(Request $request, Workspace $workspace)
    {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:100'],
            'language' => ['required', 'in:ru,kz,bilingual'],
            'current_text' => ['nullable', 'string'],
            'proposed_text' => ['nullable', 'string'],
            'analysis_instruction' => ['required', 'string'],
        ]);

        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'document_type' => $validated['document_type'],
            'input_type' => 'text',
            'language' => $validated['language'],
            'status' => 'ready',
            'content_text' => null,
            'current_text' => $validated['current_text'] ?? null,
            'proposed_text' => $validated['proposed_text'] ?? null,
            'analysis_instruction' => $validated['analysis_instruction'],
            'uploaded_at' => now(),
        ]);

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Документ создан.');
    }

    public function show(Request $request, Document $document)
    {
        Gate::authorize('view', $document);

        $document->load([
            'workspace',
            'attachments',
            'analyses' => fn ($query) => $query->latest(),
        ]);

        return view('documents.show', compact('document'));
    }

    public function updateAnalysisInstruction(Request $request, Document $document)
    {
        Gate::authorize('updateAnalysisInstruction', $document);

        $validated = $request->validate([
            'analysis_instruction' => ['required', 'string'],
        ], [
            'analysis_instruction.required' => 'Укажите поручение ИИ перед созданием анализа.',
        ]);

        $document->update([
            'analysis_instruction' => $validated['analysis_instruction'],
        ]);

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Поручение ИИ сохранено.');
    }
}
