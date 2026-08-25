<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\DraftPackage;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class DraftPackageRebuildService
{
    public function __construct(
        private readonly DraftPackageInputBuilder $inputBuilder,
        private readonly ComparativeTableBuilder $tableBuilder,
        private readonly NpaDraftBuilder $draftBuilder,
        private readonly DraftPackageDocxRenderer $docxRenderer,
        private readonly ArtifactDocxService $docxService,
        private readonly DraftPackageRebuildBackupService $backupService,
        private readonly LegalDocumentFormatter $formatter,
    ) {}

    public function preview(int $packageId): array
    {
        $package = $this->loadPackage($packageId);
        $head = $this->gitHead();
        $guard = $this->guard($package, $head);
        $candidate = $this->candidate($package, $head);
        $docxChecks = $this->verifyTemporaryDocx($package, $candidate);
        $predicted = $this->predictedRepresentations($package, $candidate);
        $planHash = $this->inputBuilder->hashPayload([
            'guard_hash' => $guard['guard_hash'],
            'input_hash' => $candidate['input']['input_hash'],
            'canonical_hashes' => $candidate['canonical_hashes'],
            'renderer_version' => $predicted['renderer_version'],
            'storage_paths' => array_column($predicted['representations'], 'storage_path'),
        ]);

        return [
            'mode' => 'dry-run',
            'package_id' => $package->id,
            'analysis_id' => $package->analysis_id,
            'head' => $head,
            'guard_hash' => $guard['guard_hash'],
            'plan_hash' => $planHash,
            'analysis_updated_at' => $guard['analysis']['updated_at'],
            'amendments' => $guard['amendments'],
            'old_canonical_hashes' => $guard['canonical_hashes'],
            'new_canonical_hashes' => $candidate['canonical_hashes'],
            'old_input_hash' => data_get($package->plan, 'input_hash'),
            'new_input_hash' => $candidate['input']['input_hash'],
            'narrative_response_id' => data_get($package->plan, 'narrative_generation.response_id'),
            'reused_justifications' => $candidate['justification_hashes'],
            'article_headings' => $candidate['article_headings'],
            'commands' => $candidate['commands'],
            'rows_to_update' => [$package->id, ...array_values($candidate['canonical_artifact_ids'])],
            'representation_rows_to_replace' => $guard['representation_ids'],
            'current_storage' => $guard['storage'],
            'predicted_storage' => $predicted,
            'docx_checks' => $docxChecks,
            'download_routes' => $this->downloadRoutes($package, $candidate['canonical_artifact_ids']),
            '_guard' => $guard,
            '_candidate' => $candidate,
        ];
    }

    public function apply(
        int $packageId,
        string $expectedHead,
        string $expectedPlanHash,
    ): array {
        $preview = $this->preview($packageId);
        if (! hash_equals($preview['head'], trim($expectedHead))) {
            throw new RuntimeException('HEAD не совпадает с подтверждённым dry-run.');
        }
        if (! hash_equals($preview['plan_hash'], trim($expectedPlanHash))) {
            throw new RuntimeException('Plan hash не совпадает с подтверждённым dry-run.');
        }

        $package = $this->loadPackage($packageId);
        $backup = $this->backupService->create($package, $preview['_guard']);
        $mutated = false;

        try {
            DB::transaction(function () use ($packageId, $preview, &$mutated): void {
                $package = DraftPackage::query()->lockForUpdate()->findOrFail($packageId);
                $package->load([
                    'analysis.document',
                    'analysis.sourceVersions.source',
                    'analysis.amendments.source',
                    'analysis.amendments.sourceVersion',
                    'artifacts',
                ]);
                $freshGuard = $this->guard($package, $preview['head']);
                if (! hash_equals($preview['guard_hash'], $freshGuard['guard_hash'])) {
                    throw new RuntimeException('DraftPackage изменился после dry-run; rebuild отменён.');
                }

                $candidate = $preview['_candidate'];
                $package->forceFill([
                    'plan' => $candidate['plan'],
                    'generated_at' => now(),
                ])->save();

                foreach ($candidate['canonical_artifacts'] as $type => $attributes) {
                    $artifact = Artifact::query()
                        ->lockForUpdate()
                        ->whereKey($candidate['canonical_artifact_ids'][$type])
                        ->where('draft_package_id', $packageId)
                        ->whereNull('source_artifact_id')
                        ->firstOrFail();
                    $artifact->forceFill([
                        'title' => $attributes['title'],
                        'content' => $attributes['content'],
                        'generated_at' => now(),
                    ])->save();
                }

                Artifact::query()
                    ->where('draft_package_id', $packageId)
                    ->whereNotNull('source_artifact_id')
                    ->delete();
                $mutated = true;
            }, 3);

            $package = $this->loadPackage($packageId);
            $user = User::findOrFail((int) $package->analysis->user_id);
            $representations = [];
            foreach (['comparative_table', 'draft_npa'] as $type) {
                $canonical = $package->artifacts
                    ->whereNull('source_artifact_id')
                    ->firstWhere('artifact_type', $type);
                if (! $canonical instanceof Artifact) {
                    throw new RuntimeException("После rebuild отсутствует canonical Artifact {$type}.");
                }
                $representation = $this->docxService->generate($canonical, $user);
                if (! $this->docxService->isValid($representation)) {
                    throw new RuntimeException("DOCX representation {$type} не прошёл проверку.");
                }
                $representations[$type] = $representation;
            }

            $post = $this->postApplyVerification($packageId, $preview, $representations);
            $this->deleteSupersededFiles(
                $preview['_guard']['storage'],
                collect($representations)->pluck('storage_path')->filter()->all(),
            );

            return [
                ...$this->publicReport($preview),
                'mode' => 'applied',
                'backup' => $backup,
                'representations' => collect($representations)->map(fn (Artifact $artifact) => [
                    'id' => $artifact->id,
                    'source_artifact_id' => $artifact->source_artifact_id,
                    'renderer_version' => $artifact->renderer_version,
                    'storage_path' => $artifact->storage_path,
                    'source_content_hash' => $artifact->source_content_hash,
                    'logical_content_hash' => $artifact->logical_content_hash,
                    'binary_sha256' => $artifact->binary_sha256,
                ])->all(),
                'post_apply_checks' => $post,
            ];
        } catch (Throwable $exception) {
            if ($mutated) {
                $this->rollback($packageId, $backup['path']);
            }
            throw $exception;
        }
    }

    public function rollback(int $packageId, string $backupPath): array
    {
        $snapshot = $this->backupService->snapshot($backupPath);
        if ((int) data_get($snapshot, 'draft_package.id') !== $packageId) {
            throw new RuntimeException('Backup относится к другому DraftPackage.');
        }

        $currentArtifacts = Artifact::query()->where('draft_package_id', $packageId)->get();
        $currentPaths = $currentArtifacts->filter(fn (Artifact $artifact) => filled($artifact->storage_path))
            ->map(fn (Artifact $artifact) => [
                'disk' => $artifact->storage_disk ?: config('filesystems.default'),
                'path' => $artifact->storage_path,
            ])->all();

        $this->backupService->restoreFiles($snapshot);

        DB::transaction(function () use ($packageId, $snapshot): void {
            $current = DraftPackage::query()->lockForUpdate()->findOrFail($packageId);
            Artifact::query()->where('draft_package_id', $packageId)->delete();

            $packageAttributes = $snapshot['draft_package'];
            unset($packageAttributes['id']);
            DB::table('draft_packages')->where('id', $current->id)->update($packageAttributes);

            $artifacts = collect($snapshot['artifacts'] ?? [])->sortBy(fn (array $artifact) => sprintf(
                '%d-%020d',
                $artifact['source_artifact_id'] === null ? 0 : 1,
                (int) $artifact['id'],
            ));
            foreach ($artifacts as $artifact) {
                DB::table('artifacts')->insert($artifact);
            }
        }, 3);

        $restoredPaths = collect($snapshot['storage_manifest'] ?? [])
            ->filter(fn (array $entry) => $entry['exists'] ?? false)
            ->map(fn (array $entry) => ($entry['disk'] ?? 'local').'|'.($entry['path'] ?? ''))
            ->all();
        foreach ($currentPaths as $entry) {
            if (in_array($entry['disk'].'|'.$entry['path'], $restoredPaths, true)) {
                continue;
            }
            Storage::disk((string) $entry['disk'])->delete((string) $entry['path']);
        }

        return [
            'mode' => 'rolled-back',
            'package_id' => $packageId,
            'backup_path' => trim($backupPath, '/'),
            'restored_artifact_ids' => collect($snapshot['artifacts'])->pluck('id')->sort()->values()->all(),
        ];
    }

    public function publicReport(array $preview): array
    {
        return collect($preview)->except(['_guard', '_candidate'])->all();
    }

    private function candidate(DraftPackage $package, string $head): array
    {
        $analysis = $package->analysis;
        $input = $this->inputBuilder->build($analysis);
        $canonical = $package->artifacts
            ->whereNull('source_artifact_id')
            ->where('format', 'structured_json')
            ->keyBy('artifact_type');
        $oldTable = $canonical->get('comparative_table');
        $oldDraft = $canonical->get('draft_npa');
        if (! $oldTable instanceof Artifact || ! $oldDraft instanceof Artifact || $canonical->count() !== 2) {
            throw new RuntimeException('DraftPackage должен содержать ровно два canonical Artifact.');
        }

        $justifications = $this->existingJustifications($oldTable->content, $input);
        $table = $this->tableBuilder->build($input, $justifications);
        $draft = $this->draftBuilder->build($input);
        $artifacts = [
            'comparative_table' => [
                'title' => 'Сравнительная таблица',
                'content' => $table,
            ],
            'draft_npa' => [
                'title' => $draft['act_type'] ? 'Проект НПА: '.$draft['title'] : 'Проект НПА',
                'content' => $draft,
            ],
        ];
        $canonicalHashes = collect($artifacts)->map(
            fn (array $artifact) => $this->inputBuilder->hashPayload($artifact['content']),
        )->all();
        $manifest = collect($artifacts)->map(fn (array $artifact, string $type) => [
            'artifact_type' => $type,
            'format' => 'structured_json',
            'title' => $artifact['title'],
            'content_hash' => $canonicalHashes[$type],
        ])->values()->all();
        $oldPlan = $package->plan;
        $warnings = array_values(array_unique(array_merge(
            $input['warnings'],
            data_get($oldPlan, 'warnings', []),
            $table['warnings'] ?? [],
            $draft['warnings'] ?? [],
        )));
        $justificationHashes = collect($justifications)->mapWithKeys(fn (array $value, int $id) => [
            $id => hash('sha256', (string) ($value['text'] ?? '')),
        ])->all();
        $plan = array_merge($input, [
            'narrative_generation' => data_get($oldPlan, 'narrative_generation'),
            'artifact_manifest' => $manifest,
            'requires_user_input' => $draft['requires_user_input'] ?? [],
            'warnings' => $warnings,
            'maintenance_rebuild' => [
                'version' => 'draft-package-rebuild-v1',
                'head' => $head,
                'source_package_id' => $package->id,
                'source_input_hash' => data_get($oldPlan, 'input_hash'),
                'source_canonical_hashes' => $this->canonicalHashes($package),
                'reused_justification_hashes' => $justificationHashes,
                'reused_narrative_response_id' => data_get($oldPlan, 'narrative_generation.response_id'),
            ],
        ]);

        return [
            'input' => $input,
            'plan' => $plan,
            'canonical_artifacts' => $artifacts,
            'canonical_artifact_ids' => [
                'comparative_table' => $oldTable->id,
                'draft_npa' => $oldDraft->id,
            ],
            'canonical_hashes' => $canonicalHashes,
            'justification_hashes' => $justificationHashes,
            'article_headings' => collect($table['rows'])->pluck('article_heading', 'amendment_id')->all(),
            'commands' => collect($draft['commands'])->pluck('text', 'amendment_id')->all(),
        ];
    }

    private function existingJustifications(array $oldTable, array $input): array
    {
        $rows = collect($oldTable['rows'] ?? [])->keyBy('amendment_id');
        $result = [];
        foreach ($input['amendment_snapshots'] as $amendment) {
            $id = (int) $amendment['amendment_id'];
            $row = $rows->get($id);
            if (! is_array($row)) {
                throw new RuntimeException("В canonical СТ отсутствует подтверждённое обоснование Amendment #{$id}.");
            }
            if ((string) ($row['proposed_text'] ?? '') !== (string) ($amendment['proposed_text'] ?? '')) {
                throw new RuntimeException("Canonical СТ не соответствует proposed_text Amendment #{$id}.");
            }
            $text = trim((string) ($row['justification'] ?? ''));
            if ($text === '') {
                throw new RuntimeException("В canonical СТ отсутствует текст обоснования Amendment #{$id}.");
            }

            $analysisWarnings = array_map('strval', $amendment['warnings'] ?? []);
            $rowWarnings = array_map('strval', $row['warnings'] ?? []);
            $result[$id] = [
                'text' => $text,
                'warnings' => array_values(array_diff($rowWarnings, $analysisWarnings)),
            ];
        }

        if ($rows->count() !== count($result)) {
            throw new RuntimeException('Canonical СТ содержит строки, не соответствующие текущим Amendments.');
        }

        return $result;
    }

    private function guard(DraftPackage $package, string $head): array
    {
        $canonicalHashes = $this->canonicalHashes($package);
        $storage = $package->artifacts->sortBy('id')->map(function (Artifact $artifact): array {
            $diskName = $artifact->storage_disk ?: config('filesystems.default');
            $exists = filled($artifact->storage_path)
                && Storage::disk($diskName)->exists($artifact->storage_path);
            $hash = null;
            $size = null;
            if ($exists) {
                $path = Storage::disk($diskName)->path($artifact->storage_path);
                $hash = hash_file('sha256', $path) ?: null;
                $size = filesize($path) ?: 0;
            }

            return [
                'artifact_id' => $artifact->id,
                'disk' => $diskName,
                'path' => $artifact->storage_path,
                'exists' => $exists,
                'file_size' => $size,
                'sha256' => $hash,
            ];
        })->values()->all();
        $analysis = $package->analysis;
        $guard = [
            'head' => $head,
            'package' => [
                'id' => $package->id,
                'analysis_id' => $package->analysis_id,
                'updated_at' => $package->getRawOriginal('updated_at'),
                'plan_hash' => $this->inputBuilder->hashPayload($package->plan),
            ],
            'analysis' => [
                'id' => $analysis->id,
                'document_id' => $analysis->document_id,
                'user_id' => $analysis->user_id,
                'status' => $analysis->status,
                'updated_at' => $analysis->getRawOriginal('updated_at'),
                'settings_hash' => $this->inputBuilder->hashPayload($analysis->settings ?? []),
            ],
            'amendments' => $analysis->amendments->sortBy('id')->map(fn ($amendment) => [
                'id' => $amendment->id,
                'updated_at' => $amendment->getRawOriginal('updated_at'),
                'attributes_hash' => hash('sha256', json_encode(
                    $amendment->getAttributes(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )),
            ])->values()->all(),
            'artifact_ids' => $package->artifacts->sortBy('id')->pluck('id')->values()->all(),
            'canonical_hashes' => $canonicalHashes,
            'representation_ids' => $package->artifacts->whereNotNull('source_artifact_id')->sortBy('id')->pluck('id')->values()->all(),
            'storage' => $storage,
        ];
        $guard['guard_hash'] = $this->inputBuilder->hashPayload($guard);

        return $guard;
    }

    private function canonicalHashes(DraftPackage $package): array
    {
        return $package->artifacts
            ->whereNull('source_artifact_id')
            ->where('format', 'structured_json')
            ->sortBy('id')
            ->mapWithKeys(fn (Artifact $artifact) => [
                $artifact->artifact_type => $this->inputBuilder->hashPayload($artifact->content),
            ])->all();
    }

    private function verifyTemporaryDocx(DraftPackage $package, array $candidate): array
    {
        $checks = [];
        $paths = [];
        try {
            foreach ($candidate['canonical_artifacts'] as $type => $attributes) {
                $artifact = new Artifact;
                $artifact->id = $candidate['canonical_artifact_ids'][$type];
                $artifact->draft_package_id = $package->id;
                $artifact->artifact_type = $type;
                $artifact->format = 'structured_json';
                $artifact->content = $attributes['content'];
                $path = $this->docxRenderer->render($artifact, [
                    'draft_npa_content' => $candidate['canonical_artifacts']['draft_npa']['content'],
                ]);
                $paths[] = $path;
                $checks[$type] = $this->verifyDocx($path, $type, $candidate);
            }
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }

        return $checks;
    }

    private function verifyDocx(string $path, string $type, array $candidate): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Temporary {$type} DOCX не является ZIP-контейнером.");
        }
        try {
            foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $part) {
                if ($zip->locateName($part) === false) {
                    throw new RuntimeException("Temporary {$type} DOCX не содержит {$part}.");
                }
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if (! is_string($xml) || $xml === '') {
            throw new RuntimeException("Temporary {$type} DOCX содержит пустой document.xml.");
        }

        $dom = new DOMDocument;
        if (! $dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException("Temporary {$type} DOCX содержит повреждённый OOXML.");
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $plainText = collect(iterator_to_array($xpath->query('//w:t') ?: []))
            ->map(fn ($node) => $node->textContent)->implode("\n");
        $checks = [
            'zip_ooxml_valid' => true,
            'binary_sha256' => hash_file('sha256', $path),
            'file_size' => filesize($path),
        ];

        if ($type === 'comparative_table') {
            if (($xpath->query('//w:pageBreakBefore[not(@w:val) or (@w:val != "0" and @w:val != "false" and @w:val != "off")]')?->length ?? 0) !== 0
                || ($xpath->query('//w:br[@w:type="page"]')?->length ?? 0) !== 0) {
                throw new RuntimeException('Comparative DOCX содержит начальный/явный page break.');
            }
            if (($xpath->query('/w:document/w:body/w:tbl[1]/w:tr[2]/w:trPr/w:cantSplit[not(@w:val) or (@w:val != "0" and @w:val != "false" and @w:val != "off")]')?->length ?? 0) !== 0) {
                throw new RuntimeException('Первая содержательная строка Comparative DOCX содержит cantSplit.');
            }
            if (($xpath->query('/w:document/w:body/w:tbl[1]/preceding-sibling::w:p[1]/w:pPr/w:keepNext[not(@w:val) or (@w:val != "0" and @w:val != "false" and @w:val != "off")]')?->length ?? 0) !== 0) {
                throw new RuntimeException('Последний заголовочный абзац Comparative DOCX содержит keepNext.');
            }
            foreach (array_filter($candidate['article_headings']) as $heading) {
                if (substr_count($plainText, (string) $heading) !== 2) {
                    throw new RuntimeException('Заголовок статьи должен присутствовать в обеих редакциях СТ ровно по одному разу.');
                }
            }
            $checks += [
                'no_explicit_page_break' => true,
                'first_data_row_can_split' => true,
                'last_heading_without_keep_next' => true,
                'article_headings_exactly_twice' => true,
            ];
        } else {
            foreach ($candidate['commands'] as $command) {
                $firstLine = trim((string) strtok((string) $command, "\n"));
                if ($firstLine !== '' && ! str_contains($plainText, $firstLine)) {
                    throw new RuntimeException('Draft NPA DOCX не содержит ожидаемую amendment command.');
                }
            }
            $checks['commands_present'] = true;
        }

        return $checks;
    }

    private function predictedRepresentations(DraftPackage $package, array $candidate): array
    {
        $renderer = (string) config('legal_analysis.draft_package.docx_renderer_version');
        $draftHash = $candidate['canonical_hashes']['draft_npa'];
        $result = [];
        foreach ($candidate['canonical_artifact_ids'] as $type => $artifactId) {
            $sourceHash = $this->inputBuilder->hashPayload([
                'canonical_artifact_hash' => $candidate['canonical_hashes'][$type],
                'canonical_draft_npa_hash' => $type === 'comparative_table' ? $draftHash : null,
            ]);
            $representationType = $type.'_docx';
            $version = trim(preg_replace('/[^a-z0-9_-]+/i', '-', $renderer) ?: 'renderer', '-');
            $result[] = [
                'source_artifact_id' => $artifactId,
                'artifact_type' => $representationType,
                'source_content_hash' => $sourceHash,
                'logical_content_hash' => $this->inputBuilder->hashPayload([
                    'artifact_type' => $representationType,
                    'renderer_version' => $renderer,
                    'source_content_hash' => $sourceHash,
                ]),
                'storage_path' => sprintf(
                    'draft-packages/%d/docx/%d/%s/%s-%s.docx',
                    $package->id,
                    $artifactId,
                    $version,
                    $representationType,
                    substr($sourceHash, 0, 16),
                ),
            ];
        }

        return ['renderer_version' => $renderer, 'representations' => $result];
    }

    private function postApplyVerification(int $packageId, array $preview, array $representations): array
    {
        $package = $this->loadPackage($packageId);
        $candidate = $preview['_candidate'];
        if ($package->analysis->getRawOriginal('updated_at') !== $preview['analysis_updated_at']) {
            throw new RuntimeException('Analysis изменился во время rebuild.');
        }
        foreach ($preview['amendments'] as $guard) {
            $amendment = $package->analysis->amendments->firstWhere('id', $guard['id']);
            if ($amendment === null || $amendment->getRawOriginal('updated_at') !== $guard['updated_at']) {
                throw new RuntimeException("AnalysisAmendment #{$guard['id']} изменился во время rebuild.");
            }
        }
        if (data_get($package->plan, 'narrative_generation.response_id') !== $preview['narrative_response_id']) {
            throw new RuntimeException('Narrative response_id изменился во время rebuild.');
        }
        if ($this->canonicalHashes($package) !== $candidate['canonical_hashes']) {
            throw new RuntimeException('Canonical hashes после rebuild не совпали с dry-run.');
        }

        $predicted = collect($preview['predicted_storage']['representations'])->keyBy('artifact_type');
        foreach ($representations as $type => $representation) {
            $expected = $predicted[$type.'_docx'];
            foreach (['source_content_hash', 'logical_content_hash', 'storage_path'] as $field) {
                if ((string) $representation->{$field} !== (string) $expected[$field]) {
                    throw new RuntimeException("DOCX {$type} содержит неожиданный {$field}.");
                }
            }
        }

        $user = User::findOrFail((int) $package->analysis->user_id);
        foreach ($package->artifacts->whereNull('source_artifact_id') as $canonical) {
            Gate::forUser($user)->authorize('view', $canonical);
            $representation = collect($representations)->firstWhere('source_artifact_id', $canonical->id);
            if (! $representation instanceof Artifact || ! $this->docxService->isValid($representation)) {
                throw new RuntimeException("Download endpoint не готов для canonical Artifact #{$canonical->id}.");
            }
            $response = Storage::disk($representation->storage_disk)->download(
                $representation->storage_path,
                $representation->filename,
                ['Content-Type' => ArtifactDocxService::MIME_TYPE],
            );
            if ($response->getStatusCode() !== 200 || ! $response->headers->has('content-disposition')) {
                throw new RuntimeException("Download response не прошёл проверку для Artifact #{$canonical->id}.");
            }
        }

        return [
            'analysis_unchanged' => true,
            'amendments_unchanged' => true,
            'narrative_response_id_preserved' => true,
            'canonical_hashes_match' => true,
            'renderer_version' => config('legal_analysis.draft_package.docx_renderer_version'),
            'download_route_registered' => Route::has('artifacts.docx.download'),
            'authorized_download_responses_ready' => true,
            'representations_valid' => true,
        ];
    }

    private function downloadRoutes(DraftPackage $package, array $artifactIds): array
    {
        if (! Route::has('artifacts.docx.download')) {
            throw new RuntimeException('Download route artifacts.docx.download не зарегистрирован.');
        }

        return collect($artifactIds)->mapWithKeys(fn (int $id, string $type) => [
            $type => route('artifacts.docx.download', ['artifact' => $id]),
        ])->all();
    }

    private function deleteSupersededFiles(array $storage, array $retainedPaths): void
    {
        foreach ($storage as $entry) {
            if (
                ! ($entry['exists'] ?? false)
                || blank($entry['path'] ?? null)
                || in_array($entry['path'], $retainedPaths, true)
            ) {
                continue;
            }
            Storage::disk((string) $entry['disk'])->delete((string) $entry['path']);
        }
    }

    private function loadPackage(int $packageId): DraftPackage
    {
        $package = DraftPackage::with([
            'analysis.document',
            'analysis.sourceVersions.source',
            'analysis.amendments.source',
            'analysis.amendments.sourceVersion',
            'artifacts',
        ])->findOrFail($packageId);

        if ($package->analysis->status !== 'completed') {
            throw new RuntimeException('Rebuild разрешён только для завершённого Analysis.');
        }
        if ($package->analysis->amendments->isEmpty()) {
            throw new RuntimeException('Analysis не содержит подтверждённых Amendments.');
        }
        if ((int) $package->analysis->draftPackage?->id !== $package->id) {
            throw new RuntimeException('DraftPackage не соответствует Analysis relation.');
        }

        return $package;
    }

    private function gitHead(): string
    {
        $process = new Process(['git', '-c', 'safe.directory='.base_path(), 'rev-parse', 'HEAD'], base_path());
        $process->mustRun();
        $head = trim($process->getOutput());
        if (preg_match('/^[a-f0-9]{40}$/', $head) !== 1) {
            throw new RuntimeException('Не удалось определить текущий Git HEAD.');
        }

        return $head;
    }
}
