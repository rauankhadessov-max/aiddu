<?php

namespace App\Services;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisWorkflowService
{
    public function save(User $user, array $data, ?Analysis $analysis = null): Analysis
    {
        $workspace = $user->workspaces()->find($data['workspace_id']);

        if (!$workspace) {
            throw ValidationException::withMessages([
                'workspace_id' => 'Выбранное рабочее дело недоступно.',
            ]);
        }

        $sourceVersionIds = collect($data['source_versions'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $this->ensureSourceVersionsBelongToWorkspace($workspace, $sourceVersionIds->all());

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

    private function ensureSourceVersionsBelongToWorkspace(Workspace $workspace, array $sourceVersionIds): void
    {
        if ($sourceVersionIds === []) {
            return;
        }

        $allowedCount = SourceVersion::query()
            ->join('sources', 'sources.id', '=', 'source_versions.source_id')
            ->join('workspace_sources', 'workspace_sources.source_id', '=', 'sources.id')
            ->where('workspace_sources.workspace_id', $workspace->id)
            ->whereIn('source_versions.id', $sourceVersionIds)
            ->distinct()
            ->count('source_versions.id');

        if ($allowedCount !== count($sourceVersionIds)) {
            throw ValidationException::withMessages([
                'source_versions' => 'Можно использовать только редакции источников, подключённых к выбранному рабочему делу.',
            ]);
        }
    }
}
