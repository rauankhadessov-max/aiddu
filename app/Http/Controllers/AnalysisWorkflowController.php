<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalysisWorkflowRequest;
use App\Models\Analysis;
use App\Models\Document;
use App\Services\AnalysisExecutionService;
use App\Services\AnalysisWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class AnalysisWorkflowController extends Controller
{
    public function create(Request $request)
    {
        $workspaces = $this->workspacesFor($request);
        $document = $this->prefillDocument($request);
        $selectedWorkspaceId = old('workspace_id', $document?->workspace_id ?? $request->integer('workspace'));

        return view('analyses.workflow', compact('workspaces', 'document', 'selectedWorkspaceId'));
    }

    public function store(
        AnalysisWorkflowRequest $request,
        AnalysisWorkflowService $workflowService,
        AnalysisExecutionService $executionService,
    ) {
        $analysis = $workflowService->save($request->user(), $request->validated());

        return $this->finish($request, $analysis, $executionService);
    }

    public function edit(Request $request, Analysis $analysis)
    {
        Gate::authorize('view', $analysis);

        if ($analysis->status !== 'draft') {
            return redirect()->route('analyses.show', $analysis);
        }

        $analysis->load(['document', 'sourceVersions']);
        $workspaces = $this->workspacesFor($request);
        $selectedWorkspaceId = old('workspace_id', $analysis->workspace_id);

        return view('analyses.workflow', compact('workspaces', 'analysis', 'selectedWorkspaceId'));
    }

    public function update(
        AnalysisWorkflowRequest $request,
        Analysis $analysis,
        AnalysisWorkflowService $workflowService,
        AnalysisExecutionService $executionService,
    ) {
        Gate::authorize('run', $analysis);

        $analysis = $workflowService->save($request->user(), $request->validated(), $analysis);

        return $this->finish($request, $analysis, $executionService);
    }

    private function finish(
        AnalysisWorkflowRequest $request,
        Analysis $analysis,
        AnalysisExecutionService $executionService,
    ) {
        if ($request->validated('action') === 'save_draft') {
            return redirect()
                ->route('analyses.workflow.edit', $analysis)
                ->with('success', 'Черновик анализа сохранён.');
        }

        try {
            if (!$executionService->execute($analysis)) {
                return redirect()
                    ->route('analyses.show', $analysis)
                    ->with('error', 'Не удалось подтвердить нормативные ссылки в результате анализа.');
            }

            return redirect()
                ->route('analyses.show', $analysis)
                ->with('success', 'Юридический анализ успешно выполнен.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('analyses.show', $analysis)
                ->with('error', 'Не удалось выполнить анализ. Попробуйте повторить позже или обратитесь к администратору.');
        }
    }

    private function workspacesFor(Request $request)
    {
        return $request->user()->workspaces()
            ->with([
                'sources' => fn ($query) => $query->visibleTo($request->user()),
                'sources.versions' => fn ($query) => $query
                    ->orderByDesc('effective_date')
                    ->orderBy('id'),
            ])
            ->latest()
            ->get();
    }

    private function prefillDocument(Request $request): ?Document
    {
        if (!$request->filled('document')) {
            return null;
        }

        $document = Document::find($request->integer('document'));

        if (!$document) {
            return null;
        }

        Gate::authorize('view', $document);

        return $document;
    }
}
