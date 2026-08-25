<?php

namespace App\Console\Commands;

use App\Services\DraftPackageRebuildService;
use Illuminate\Console\Command;
use Throwable;

class RebuildDraftPackage extends Command
{
    protected $signature = 'draft-package:rebuild
        {package : DraftPackage ID}
        {--apply : Apply the confirmed dry-run plan}
        {--expected-head= : Exact Git HEAD shown by dry-run}
        {--expected-plan-hash= : Exact plan hash shown by dry-run}
        {--rollback= : Restore this package from a maintenance backup path}';

    protected $description = 'Safely preview, rebuild, or roll back one DraftPackage without OpenAI or Legal Engine';

    public function handle(DraftPackageRebuildService $service): int
    {
        $packageId = filter_var($this->argument('package'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($packageId === false) {
            $this->error('DraftPackage ID должен быть положительным целым числом.');

            return self::FAILURE;
        }

        try {
            if (filled($this->option('rollback'))) {
                if (! $this->option('apply')) {
                    $this->error('Rollback требует явного --apply.');

                    return self::FAILURE;
                }
                $report = $service->rollback((int) $packageId, (string) $this->option('rollback'));
                $this->info('DraftPackage rollback завершён.');
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $preview = $service->preview((int) $packageId);
            if (! $this->option('apply')) {
                $this->printPreview($service->publicReport($preview));
                $this->newLine();
                $this->warn('DRY-RUN: production data, canonical Artifacts and storage were not changed.');
                $this->line(sprintf(
                    'After approval: php artisan draft-package:rebuild %d --apply --expected-head=%s --expected-plan-hash=%s',
                    $packageId,
                    $preview['head'],
                    $preview['plan_hash'],
                ));

                return self::SUCCESS;
            }

            if (blank($this->option('expected-head')) || blank($this->option('expected-plan-hash'))) {
                $this->error('--apply требует --expected-head и --expected-plan-hash из подтверждённого dry-run.');

                return self::FAILURE;
            }

            $report = $service->apply(
                (int) $packageId,
                (string) $this->option('expected-head'),
                (string) $this->option('expected-plan-hash'),
            );
            $this->info('DraftPackage rebuild завершён.');
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function printPreview(array $report): void
    {
        $this->info('DraftPackage rebuild dry-run');
        $this->table(['Field', 'Value'], [
            ['Package', $report['package_id']],
            ['Analysis', $report['analysis_id']],
            ['HEAD', $report['head']],
            ['Guard hash', $report['guard_hash']],
            ['Plan hash', $report['plan_hash']],
            ['Renderer', $report['predicted_storage']['renderer_version']],
            ['Narrative response_id', $report['narrative_response_id'] ?: 'none'],
        ]);
        $this->line('Canonical hashes:');
        foreach ($report['new_canonical_hashes'] as $type => $hash) {
            $old = $report['old_canonical_hashes'][$type] ?? 'missing';
            $this->line("  {$type}: {$old} -> {$hash}");
        }
        $this->line('DB rows to update: '.implode(', ', $report['rows_to_update']));
        $this->line('Representation rows to replace: '.($report['representation_rows_to_replace'] === []
            ? 'none'
            : implode(', ', $report['representation_rows_to_replace'])));
        $this->line('Current storage:');
        foreach ($report['current_storage'] as $entry) {
            $this->line(sprintf(
                '  Artifact #%d: %s (%s)',
                $entry['artifact_id'],
                $entry['path'] ?: 'no file',
                $entry['exists'] ? 'exists' : 'missing',
            ));
        }
        $this->line('Predicted storage:');
        foreach ($report['predicted_storage']['representations'] as $entry) {
            $this->line("  {$entry['artifact_type']}: {$entry['storage_path']}");
        }
        $this->line('Article headings: '.json_encode($report['article_headings'], JSON_UNESCAPED_UNICODE));
        $this->line('Commands: '.json_encode($report['commands'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('DOCX checks: '.json_encode($report['docx_checks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('Download routes: '.json_encode($report['download_routes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
