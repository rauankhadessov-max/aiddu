<?php

namespace App\Services;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisWorkflowService
{
    public function __construct(
        private readonly WorkspaceSourceVersionResolver $sourceVersionResolver,
    ) {
    }

    public function save(User $user, array $data, ?Analysis $analysis = null): Analysis
    {
        $workspace = $user->workspaces()->find($data['workspace_id']);

        if (!$workspace) {
            throw ValidationException::withMessages([
                'workspace_id' => 'Выбранное рабочее дело недоступно.',
            ]);
        }

        $sourceVersionIds = $data['action'] === 'run'
            ? $this->sourceVersionResolver->resolveForRun($user, $workspace, $data['source_versions'] ?? [])
            : $this->sourceVersionResolver->validateExplicitSelection($user, $workspace, $data['source_versions'] ?? []);

        return DB::transaction(function () use ($user, $workspace, $data, $analysis, $sourceVersionIds) {
            if ($analysis && $analysis->status !== 'draft') {
                throw ValidationException::withMessages([
                    'action' => 'Изменять можно только черновик анализа.',
                ]);
            }

            $document = $analysis?->document;

            if ($document && $document->analyses()->whereKeyNot($analysis->id)->exists()) {
                $document = null;
            }

            $documentData = [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'title' => $data['title'],
                'document_type' => 'legal_norm',
                'input_type' => 'text',
                'language' => 'ru',
                'status' => $data['action'] === 'run' ? 'ready' : 'draft',
                'current_text' => $data['current_text'] ?? null,
                'proposed_text' => $data['proposed_text'] ?? null,
                'analysis_instruction' => $data['analysis_instruction'] ?? null,
            ];

            if ($document) {
                $document->update($documentData);
            } else {
                $document = Document::create($documentData);
            }

            $analysisData = [
                'workspace_id' => $workspace->id,
                'document_id' => $document->id,
                'user_id' => $user->id,
                'title' => $data['title'],
                'analysis_type' => filled($data['proposed_text'] ?? null)
                    ? 'amendment_review'
                    : 'amendment_drafting',
                'instruction' => $data['analysis_instruction'] ?? null,
                'status' => 'draft',
                'version' => $analysis?->version ?? 1,
            ];

            if ($analysis) {
                $analysis->update($analysisData);
            } else {
                $analysis = Analysis::create($analysisData);
            }

            $analysis->sourceVersions()->sync(
                $sourceVersionIds->mapWithKeys(fn ($id) => [
                    $id => ['role' => 'reference'],
                ])->all(),
            );

            return $analysis->fresh(['document', 'workspace', 'sourceVersions']);
        });
    }

}
