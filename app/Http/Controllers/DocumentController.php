<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function create(Request $request, Workspace $workspace)
    {
        abort_unless($workspace->user_id === $request->user()->id, 403);

        return view('documents.create', compact('workspace'));
    }

    public function store(Request $request, Workspace $workspace)
    {
        abort_unless($workspace->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:100'],
            'language' => ['required', 'in:ru,kz,bilingual'],
            'current_text' => ['nullable', 'string'],
            'proposed_text' => ['required', 'string'],
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
            'proposed_text' => $validated['proposed_text'],
            'analysis_instruction' => $validated['analysis_instruction'],
            'uploaded_at' => now(),
        ]);

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Документ создан.');
    }

    public function show(Request $request, Document $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        $document->load([
            'workspace',
            'attachments',
            'analyses',
        ]);

        return view('documents.show', compact('document'));
    }
}
