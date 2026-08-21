<?php

namespace App\Http\Controllers;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\SourceVersion;
use Illuminate\Http\Request;
use App\Services\LegalAnalysisService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class AnalysisController extends Controller
{
    public function create(Request $request, Document $document)
    {
        Gate::authorize('createAnalysis', $document);

        if (blank($document->analysis_instruction)) {
            return redirect()
                ->route('documents.show', $document)
                ->with('error', 'Сначала укажите поручение ИИ для этого документа.');
        }

        $document->load('workspace');

        $sourceVersions = $this->sourceVersionsAvailableFor($document)
            ->with('source')
            ->orderByDesc('source_versions.effective_date')
            ->orderBy('source_versions.id')
            ->get();

        return view('analyses.create', compact('document', 'sourceVersions'));
    }

    public function store(Request $request, Document $document)
    {
        Gate::authorize('createAnalysis', $document);

        if (blank($document->analysis_instruction)) {
            return redirect()
                ->route('documents.show', $document)
                ->with('error', 'Сначала укажите поручение ИИ для этого документа.');
        }

        $validated = $request->validate([
            'source_versions' => ['required', 'array', 'min:1'],
            'source_versions.*' => ['required', 'integer', 'distinct', 'exists:source_versions,id'],
        ], [
            'source_versions.required' => 'Выберите хотя бы одну редакцию нормативного источника.',
            'source_versions.min' => 'Выберите хотя бы одну редакцию нормативного источника.',
            'source_versions.*.exists' => 'Выбрана недоступная редакция нормативного источника.',
        ]);

        $selectedSourceVersionIds = collect($validated['source_versions'])
            ->map(fn ($id) => (int) $id)
            ->values();

        $allowedSourceVersionIds = $this->sourceVersionsAvailableFor($document)
            ->whereIn('source_versions.id', $selectedSourceVersionIds)
            ->pluck('source_versions.id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($allowedSourceVersionIds->count() !== $selectedSourceVersionIds->count()) {
            throw ValidationException::withMessages([
                'source_versions' => 'Можно использовать только редакции источников, подключённых к текущему рабочему делу.',
            ]);
        }

        $analysis = DB::transaction(function () use ($document, $request, $selectedSourceVersionIds) {
            $analysis = Analysis::create([
                'workspace_id' => $document->workspace_id,
                'document_id' => $document->id,
                'user_id' => $request->user()->id,
                'title' => 'Юридический анализ: '.$document->title,
                'analysis_type' => 'comprehensive',
                'instruction' => $document->analysis_instruction,
                'status' => 'draft',
                'version' => 1,
            ]);

            $syncData = $selectedSourceVersionIds
                ->mapWithKeys(fn ($sourceVersionId) => [
                    $sourceVersionId => ['role' => 'reference'],
                ])
                ->all();

            $analysis->sourceVersions()->sync($syncData);

            return $analysis;
        });

        return redirect()
            ->route('analyses.show', $analysis)
            ->with('success', 'Анализ создан.');
    }

    public function show(Request $request, Analysis $analysis)
    {
        Gate::authorize('view', $analysis);

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
    Gate::authorize('run', $analysis);

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
        $runResult = $legalAnalysisService->run($analysis);

        if ($runResult->hasCompletelyInvalidCitations()) {
            DB::transaction(function () use ($analysis, $runResult) {
                $attemptSnapshot = $runResult->settings();
                $settings = $analysis->settings ?? [];

                if ($settings === []) {
                    $settings = $attemptSnapshot;
                }

                $settings['citation_validation'] = $attemptSnapshot['citation_validation'];
                $settings['last_failed_attempt'] = $attemptSnapshot;

                $analysis->update([
                    'status' => 'failed',
                    'settings' => $settings,
                    'completed_at' => now(),
                    'error_message' => 'Ни одно замечание не прошло обязательную проверку нормативных ссылок.',
                ]);
            });

            return redirect()
                ->route('analyses.show', $analysis)
                ->with('error', 'Не удалось подтвердить нормативные ссылки в результате анализа.');
        }

        DB::transaction(function () use ($analysis, $runResult) {

            $analysis->findings()->delete();

            $analysis->update([
                'status' => 'completed',
                'ai_model' => $runResult->model ?? config('services.openai.model'),
                'summary' => $runResult->summary,
                'settings' => $runResult->settings(),
                'completed_at' => now(),
                'error_message' => null,
            ]);

            foreach ($runResult->findings as $index => $finding) {
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
            ->with('error', 'Не удалось выполнить анализ. Попробуйте повторить позже или обратитесь к администратору.');
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

private function sourceVersionsAvailableFor(Document $document)
{
    return SourceVersion::query()
        ->select('source_versions.*')
        ->join('sources', 'sources.id', '=', 'source_versions.source_id')
        ->join('workspace_sources', 'workspace_sources.source_id', '=', 'sources.id')
        ->where('workspace_sources.workspace_id', $document->workspace_id);
}



}
