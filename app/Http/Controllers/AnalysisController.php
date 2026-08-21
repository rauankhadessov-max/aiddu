<?php

namespace App\Http\Controllers;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\SourceVersion;
use Illuminate\Http\Request;
use App\Services\LegalAnalysisService;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalysisController extends Controller
{
    public function create(Request $request, Document $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        $document->load('workspace');

        $sourceVersions = SourceVersion::with('source')
            ->latest('effective_date')
            ->get();

        return view('analyses.create', compact('document', 'sourceVersions'));
    }

    public function store(Request $request, Document $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'analysis_type' => ['required', 'string', 'max:100'],
            'instruction' => ['required', 'string'],
            'source_versions' => ['nullable', 'array'],
            'source_versions.*' => ['integer', 'exists:source_versions,id'],
        ]);

        $analysis = Analysis::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'analysis_type' => $validated['analysis_type'],
            'instruction' => $validated['instruction'],
            'status' => 'draft',
            'version' => 1,
        ]);

        if (!empty($validated['source_versions'])) {
            $syncData = [];

            foreach ($validated['source_versions'] as $sourceVersionId) {
                $syncData[$sourceVersionId] = [
                    'role' => 'reference',
                ];
            }

            $analysis->sourceVersions()->sync($syncData);
        }

        return redirect()
            ->route('analyses.show', $analysis)
            ->with('success', 'Анализ создан.');
    }

    public function show(Request $request, Analysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $analysis->load([
            'workspace',
            'document',
            'sourceVersions.source',
            'findings',
            'draftPackage.artifacts',
        ]);

        return view('analyses.show', compact('analysis'));
    }

public function run(
    Request $request,
    Analysis $analysis,
    LegalAnalysisService $legalAnalysisService
)
{
    abort_unless($analysis->user_id === $request->user()->id, 403);

    if (!$analysis->document_id) {
        return back()->with('error', 'Для анализа не выбран документ.');
    }

    if ($analysis->sourceVersions()->count() === 0) {
        return back()->with('error', 'Для анализа необходимо выбрать хотя бы один нормативный источник.');
    }

    $analysis->update([
        'status' => 'processing',
        'started_at' => now(),
        'completed_at' => null,
        'error_message' => null,
    ]);

    try {
        $response = $legalAnalysisService->run($analysis);

        $result = $response['result'];

        DB::transaction(function () use ($analysis, $result, $response) {

            $analysis->findings()->delete();

            $analysis->update([
                'status' => 'completed',
                'ai_model' => $response['model'] ?? config('services.openai.model'),
                'summary' => $result['summary'] ?? null,
                'settings' => [
                    'response_id' => $response['response_id'] ?? null,
                    'usage' => $response['usage'] ?? [],
                    'overall_assessment' => $result['overall_assessment'] ?? null,
                ],
                'completed_at' => now(),
                'error_message' => null,
            ]);

            foreach (($result['findings'] ?? []) as $index => $finding) {
                $analysis->findings()->create([
                    'finding_type' => $finding['finding_type'],
                    'severity' => $finding['severity'],
                    'status' => 'open',
                    'title' => $finding['title'],
                    'description' => $finding['description'],
                    'document_fragment' => $finding['document_fragment'],
                    'document_location' => $finding['document_location'],
                    'source_reference' => $finding['source_reference'],
                    'legal_basis' => $finding['legal_basis'],
                    'recommendation' => $finding['recommendation'],
                    'recommended_text' => $finding['recommended_text'],
                    'justification' => $finding['justification'],
                    'confidence_score' => $finding['confidence_score'],
                    'sort_order' => $index + 1,
                ]);
            }
        });

        return redirect()
            ->route('analyses.show', $analysis)
            ->with('success', 'Юридический анализ успешно выполнен.');

    } catch (Throwable $e) {

        $analysis->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $e->getMessage(),
        ]);

        report($e);

        return redirect()
            ->route('analyses.show', $analysis)
            ->with('error', 'Не удалось выполнить анализ: ' . $e->getMessage());
    }
}


public function index(Request $request)
{
    $analyses = Analysis::with(['document', 'workspace'])
        ->where('user_id', $request->user()->id)
        ->latest()
        ->get();

    return view('analyses.index', compact('analyses'));
}



}
