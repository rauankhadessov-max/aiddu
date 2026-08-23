<?php

namespace App\Services;

use App\Models\Analysis;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalysisExecutionService
{
    public function __construct(
        private readonly LegalAnalysisService $legalAnalysisService,
    ) {
    }

    /**
     * Execute the existing legal-analysis pipeline.
     *
     * @return bool True when the run completed, false when every mandatory
     *              citation was rejected.
     */
    public function execute(Analysis $analysis): bool
    {
        $analysis->update([
            'status' => 'processing',
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ]);

        try {
            $runResult = $this->legalAnalysisService->run($analysis);

            if ($runResult->hasCompletelyInvalidCitations()) {
                DB::transaction(function () use ($analysis, $runResult) {
                    $attemptSnapshot = $runResult->settings();
                    $settings = $analysis->settings ?? [];

                    if ($settings === []) {
                        $settings = $attemptSnapshot;
                    }

                    $settings['citation_validation'] = $attemptSnapshot['citation_validation'];
                    $settings['amendment_validation'] = $attemptSnapshot['amendment_validation'] ?? null;
                    $settings['source_sufficiency'] = $attemptSnapshot['source_sufficiency'] ?? null;
                    $settings['warnings'] = $attemptSnapshot['warnings'] ?? [];
                    $settings['last_failed_attempt'] = $attemptSnapshot;

                    $analysis->update([
                        'status' => 'failed',
                        'settings' => $settings,
                        'completed_at' => now(),
                        'error_message' => 'Ни один элемент результата не прошёл обязательную правовую проверку.',
                    ]);
                });

                return false;
            }

            DB::transaction(function () use ($analysis, $runResult) {
                $analysis->findings()->delete();
                $analysis->amendments()->delete();

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

                foreach ($runResult->amendments as $index => $amendment) {
                    unset($amendment['_model_index']);

                    $analysis->amendments()->create(array_merge($amendment, [
                        'sort_order' => $index + 1,
                    ]));
                }
            });

            return true;
        } catch (Throwable $exception) {
            $analysis->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
