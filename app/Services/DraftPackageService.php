<?php

namespace App\Services;

use App\Models\Analysis;
use App\Models\DraftPackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DraftPackageService
{
    public function __construct(
        private readonly DraftPackageInputBuilder $inputBuilder,
        private readonly DraftJustificationService $justificationService,
        private readonly ComparativeTableBuilder $tableBuilder,
        private readonly NpaDraftBuilder $draftBuilder,
    ) {}

    public function generate(Analysis $analysis, User $user): DraftPackage
    {
        if ($existing = $analysis->draftPackage()->with('artifacts')->first()) {
            return $existing;
        }

        $input = $this->inputBuilder->build($analysis);
        $narrative = $this->justificationService->generate($input);
        $table = $this->tableBuilder->build($input, $narrative['justifications']);
        $draft = $this->draftBuilder->build($input);
        $artifacts = [
            ['type' => 'comparative_table', 'title' => 'Сравнительная таблица', 'content' => $table],
            ['type' => 'draft_npa', 'title' => $draft['act_type'] ? 'Проект НПА: '.$draft['title'] : 'Проект НПА', 'content' => $draft],
        ];
        $manifest = array_map(fn (array $artifact) => [
            'artifact_type' => $artifact['type'],
            'format' => 'structured_json',
            'title' => $artifact['title'],
            'content_hash' => $this->inputBuilder->hashPayload($artifact['content']),
        ], $artifacts);

        return DB::transaction(function () use ($analysis, $user, $input, $narrative, $artifacts, $manifest) {
            $fresh = Analysis::with(['document', 'sourceVersions.source', 'amendments.source', 'amendments.sourceVersion'])
                ->findOrFail($analysis->id);
            $freshInput = $this->inputBuilder->build($fresh);
            if (! hash_equals($input['input_hash'], $freshInput['input_hash'])) {
                throw new RuntimeException('Analysis изменился во время формирования пакета. Повторите операцию.');
            }

            if ($existing = $fresh->draftPackage()->with('artifacts')->first()) {
                return $existing;
            }

            $warnings = array_values(array_unique(array_merge(
                $input['warnings'],
                $narrative['warnings'],
                ...array_map(fn (array $artifact) => $artifact['content']['warnings'] ?? [], $artifacts),
            )));
            $plan = array_merge($input, [
                'narrative_generation' => $narrative['response'],
                'artifact_manifest' => $manifest,
                'requires_user_input' => $artifacts[1]['content']['requires_user_input'] ?? [],
                'warnings' => $warnings,
            ]);

            $package = $fresh->draftPackage()->create([
                'title' => 'Пакет документов: '.$fresh->document->title,
                'package_type' => 'legal_amendment',
                'status' => 'draft',
                'description' => 'Сравнительная таблица и проект НПА на основании подтверждённых поправок.',
                'plan' => $plan,
                'generated_at' => now(),
            ]);

            foreach ($artifacts as $artifact) {
                $package->artifacts()->create([
                    'created_by' => $user->id,
                    'artifact_type' => $artifact['type'],
                    'format' => 'structured_json',
                    'title' => $artifact['title'],
                    'content' => $artifact['content'],
                    'status' => 'draft',
                    'generated_at' => now(),
                ]);
            }

            return $package->load('artifacts');
        });
    }
}
