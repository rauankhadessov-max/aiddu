<?php

namespace App\Http\Controllers;

use App\Models\Analysis;
use App\Models\Document;
use App\Presenters\AnalysisResultPresenter;
use App\Services\AnalysisDeletionService;
use App\Services\AnalysisExecutionService;
use App\Services\WorkspaceSourceVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class AnalysisController extends Controller
{
    public function create(
        Request $request,
        Document $document,
        WorkspaceSourceVersionResolver $sourceVersionResolver,
    ) {
        Gate::authorize('createAnalysis', $document);

        if (blank($document->analysis_instruction)) {
            return redirect()
                ->route('documents.show', $document)
                ->with('error', 'Сначала укажите поручение ИИ для этого документа.');
        }

        $document->load('workspace');

        $sourceVersions = $sourceVersionResolver
            ->eligibleQuery($request->user(), $document->workspace)
            ->with('source')
            ->orderByDesc('source_versions.effective_date')
            ->orderBy('source_versions.id')
            ->get();

        return view('analyses.create', compact('document', 'sourceVersions'));
    }

    public function store(
        Request $request,
        Document $document,
        WorkspaceSourceVersionResolver $sourceVersionResolver,
    ) {
        Gate::authorize('createAnalysis', $document);

        if (blank($document->analysis_instruction)) {
            return redirect()
                ->route('documents.show', $document)
                ->with('error', 'Сначала укажите поручение ИИ для этого документа.');
        }

        $validated = $request->validate([
            'source_versions' => ['nullable', 'array', 'max:100'],
            'source_versions.*' => ['required', 'integer', 'distinct', 'exists:source_versions,id'],
        ], [
            'source_versions.*.exists' => 'Выбрана недоступная редакция нормативного источника.',
        ]);

        $selectedSourceVersionIds = $sourceVersionResolver->resolveForRun(
            $request->user(),
            $document->workspace,
            $validated['source_versions'] ?? [],
        );

        $analysis = DB::transaction(function () use ($document, $request, $selectedSourceVersionIds) {
            $analysis = Analysis::create([
                'workspace_id' => $document->workspace_id,
                'document_id' => $document->id,
                'user_id' => $request->user()->id,
                'title' => 'Юридический анализ: '.$document->title,
                'analysis_type' => filled($document->proposed_text)
                    ? 'amendment_review'
                    : 'amendment_drafting',
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

    public function show(Request $request, Analysis $analysis, AnalysisResultPresenter $resultPresenter)
    {
        Gate::authorize('view', $analysis);

        $analysis->load([
            'workspace',
            'document',
            'sourceVersions.source',
            'findings',
            'amendments',
            'draftPackage.canonicalArtifacts',
        ]);

        return view('analyses.show', compact('analysis', 'resultPresenter'));
    }

    public function run(
        Request $request,
        Analysis $analysis,
        AnalysisExecutionService $analysisExecutionService
    ) {
        Gate::authorize('run', $analysis);

        if (! $analysis->document_id) {
            return back()->with('error', 'Для анализа не выбран документ.');
        }

        if ($analysis->sourceVersions()->count() === 0) {
            return back()->with('error', 'Для анализа необходимо выбрать хотя бы один нормативный источник.');
        }

        try {
            if (! $analysisExecutionService->execute($analysis)) {
                return redirect()
                    ->route('analyses.show', $analysis)
                    ->with('error', 'Не удалось подтвердить нормативные ссылки в результате анализа.');
            }

            return redirect()
                ->route('analyses.show', $analysis)
                ->with('success', 'Юридический анализ успешно выполнен.');

        } catch (Throwable $e) {

            report($e);

            return redirect()
                ->route('analyses.show', $analysis)
                ->with('error', 'Не удалось выполнить анализ. Попробуйте повторить позже или обратитесь к администратору.');
        }
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'workspace' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,processing,completed,failed'],
        ]);

        $workspaces = $request->user()
            ->workspaces()
            ->select(['id', 'title'])
            ->orderBy('title')
            ->get();

        $analyses = Analysis::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'workspace:id,user_id,title',
                'draftPackage:id,analysis_id',
                'draftPackage.canonicalArtifacts:id,draft_package_id,artifact_type,format,title',
            ])
            ->select('analyses.*')
            ->selectSub(
                DB::table('analysis_source_versions')
                    ->join('source_versions', 'source_versions.id', '=', 'analysis_source_versions.source_version_id')
                    ->whereColumn('analysis_source_versions.analysis_id', 'analyses.id')
                    ->selectRaw('COUNT(DISTINCT source_versions.source_id)'),
                'sources_count'
            )
            ->withCount('amendments')
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $search = trim($filters['search']);

                $query->where(function ($query) use ($search) {
                    $query->where('analyses.title', 'like', "%{$search}%")
                        ->orWhereHas('workspace', fn ($workspaceQuery) => $workspaceQuery
                            ->where('title', 'like', "%{$search}%"));
                });
            })
            ->when(isset($filters['workspace']), function ($query) use ($filters, $workspaces) {
                if ($workspaces->contains('id', (int) $filters['workspace'])) {
                    $query->where('workspace_id', (int) $filters['workspace']);

                    return;
                }

                $query->whereRaw('1 = 0');
            })
            ->when(filled($filters['status'] ?? null), fn ($query) => $query
                ->where('status', $filters['status']))
            ->latest('analyses.created_at')
            ->latest('analyses.id')
            ->paginate(15)
            ->withQueryString();

        return view('analyses.index', compact('analyses', 'workspaces', 'filters'));
    }

    public function destroy(Analysis $analysis, AnalysisDeletionService $analysisDeletionService)
    {
        Gate::authorize('delete', $analysis);

        try {
            $analysisDeletionService->delete($analysis);

            return redirect()
                ->route('analyses.index')
                ->with('success', 'Анализ и сформированные результаты удалены.');
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Не удалось безопасно удалить анализ. Попробуйте позже.');
        }
    }
}
