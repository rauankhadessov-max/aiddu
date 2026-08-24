<?php

namespace App\Services;

use App\Data\LegalStructuralIntent;
use App\Models\Analysis;
use App\Models\RegulatoryProfile;
use Normalizer;

class LegalTargetSourceResolver
{
    public function resolve(
        Analysis $analysis,
        LegalStructuralIntent $intent,
        array $articleGroups,
    ): array {
        $analysis->loadMissing([
            'document',
            'workspace.regulatoryProfile',
            'sourceVersions.source',
        ]);

        $versions = $analysis->sourceVersions->keyBy('id');
        $structuralVersionIds = collect($articleGroups)
            ->pluck('source_version_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $candidateVersionIds = $versions->keys()->map(fn ($id) => (int) $id)->values();

        $signals = $candidateVersionIds->mapWithKeys(fn (int $versionId) => [$versionId => []])->all();
        $exactGroups = $this->exactCurrentTextGroups($analysis, $intent, $articleGroups);
        $exactVersionIds = collect($exactGroups)
            ->pluck('source_version_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($exactVersionIds as $versionId) {
            $signals[$versionId][] = 'exact_current_text';
        }

        $explicitVersionIds = $this->versionsMentionedInTargetClause(
            (string) $analysis->instruction,
            $intent,
            $versions,
            $candidateVersionIds->all(),
        );

        foreach ($explicitVersionIds as $versionId) {
            $signals[$versionId][] = 'explicit_instruction_title';
        }

        $documentVersionIds = $this->versionsMentionedInText(
            (string) ($analysis->document?->title ?? ''),
            $versions,
            $candidateVersionIds->all(),
        );

        foreach ($documentVersionIds as $versionId) {
            $signals[$versionId][] = 'document_title';
        }

        $workspaceVersionIds = [];
        $primaryVersionIds = [];
        $workspace = $analysis->workspace;

        if ($workspace?->regulatoryProfile?->is_active
            && $workspace->regulatoryProfile->purpose === RegulatoryProfile::NEW_USER_DEFAULT) {
            $workspaceVersionIds = $this->versionsMentionedInText(
                (string) $workspace->title,
                $versions,
                $candidateVersionIds->all(),
            );
            $primarySourceIds = $workspace->sources()
                ->wherePivot('is_primary', true)
                ->pluck('sources.id')
                ->merge($workspace->regulatoryProfile->sources()->wherePivot('is_primary', true)->pluck('sources.id'))
                ->map(fn ($id) => (int) $id)
                ->unique();
            $primaryVersionIds = $candidateVersionIds
                ->filter(fn (int $versionId) => $primarySourceIds->contains((int) $versions->get($versionId)?->source_id))
                ->values()
                ->all();

            foreach ($workspaceVersionIds as $versionId) {
                $signals[$versionId][] = 'default_workspace_title';
            }

            foreach ($primaryVersionIds as $versionId) {
                $signals[$versionId][] = 'primary_source';
            }
        }

        $exact = $this->uniqueVersion($exactVersionIds->all());
        $explicit = $this->uniqueSourceVersion($explicitVersionIds, $versions, $articleGroups);

        if ($exact !== null && $explicit !== null
            && (int) $versions->get($exact)?->source_id !== (int) $versions->get($explicit)?->source_id) {
            return $this->result(
                status: 'ambiguous',
                versions: $versions,
                signals: $signals,
                candidateVersionIds: $candidateVersionIds->all(),
                ambiguityReasons: ['conflicting_exact_current_text_and_explicit_source_title'],
            );
        }

        $selectedVersionId = $exact ?? $explicit;
        $resolutionEvidence = [];

        if ($selectedVersionId !== null) {
            $resolutionEvidence = array_values(array_intersect(
                $signals[$selectedVersionId] ?? [],
                ['exact_current_text', 'explicit_instruction_title', 'document_title', 'default_workspace_title', 'primary_source'],
            ));
        }

        if ($selectedVersionId === null) {
            $selectedVersionId = $this->uniqueSourceVersion($documentVersionIds, $versions, $articleGroups);

            if ($selectedVersionId !== null) {
                $resolutionEvidence = ['document_title'];
            }
        }

        if ($selectedVersionId === null) {
            $fallbackIds = array_values(array_unique(array_intersect($workspaceVersionIds, $primaryVersionIds)));
            $selectedVersionId = $this->uniqueSourceVersion($fallbackIds, $versions, $articleGroups);

            if ($selectedVersionId !== null) {
                $resolutionEvidence = ['default_workspace_title', 'primary_source'];
            }
        }

        $structuralFallbackIds = $structuralVersionIds->isNotEmpty()
            ? $structuralVersionIds
            : $candidateVersionIds;

        if ($selectedVersionId === null && $structuralFallbackIds->count() === 1) {
            $selectedVersionId = $structuralFallbackIds->first();
            $resolutionEvidence = ['unique_structural_candidate'];
            $signals[$selectedVersionId][] = 'unique_structural_candidate';
        }

        if ($selectedVersionId === null) {
            return $this->result(
                status: 'ambiguous',
                versions: $versions,
                signals: $signals,
                candidateVersionIds: $candidateVersionIds->all(),
                ambiguityReasons: ['multiple_target_source_candidates'],
            );
        }

        return $this->result(
            status: 'resolved',
            versions: $versions,
            signals: $signals,
            candidateVersionIds: $candidateVersionIds->all(),
            selectedVersionId: $selectedVersionId,
            resolutionEvidence: $resolutionEvidence,
        );
    }

    private function exactCurrentTextGroups(Analysis $analysis, LegalStructuralIntent $intent, array $articleGroups): array
    {
        $current = $this->currentArticleBlock((string) ($analysis->document?->current_text ?? ''), $intent->article);
        $normalizedCurrent = $this->normalizeComparableText($current);

        if ($normalizedCurrent === '') {
            return [];
        }

        return array_values(array_filter($articleGroups, function (array $group) use ($normalizedCurrent) {
            $groupText = collect($group['fragments'])
                ->map(fn ($fragment) => $fragment->text)
                ->implode("\n");

            return str_contains($this->normalizeComparableText($groupText), $normalizedCurrent);
        }));
    }

    private function currentArticleBlock(string $text, ?string $article): string
    {
        if ($article === null || trim($text) === '') {
            return trim($text);
        }

        $locator = preg_quote($article, '/');
        $pattern = '/^[\h]*(?:Статья|СТАТЬЯ|Бап)\h+'.$locator.'(?:\.|\h)[\s\S]*?(?=^[\h]*(?:Статья|СТАТЬЯ|Бап)\h+[\d]+(?:[-.]\d+)*(?:\.|\h)|\z)/imu';

        return preg_match($pattern, $text, $match) === 1 ? trim($match[0]) : trim($text);
    }

    private function versionsMentionedInTargetClause(
        string $text,
        LegalStructuralIntent $intent,
        $versions,
        array $candidateVersionIds,
    ): array {
        if ($intent->article === null || trim($text) === '') {
            return [];
        }

        $article = preg_quote($intent->article, '/');
        $clauses = preg_split('/(?:\R+|(?<=[.!?;])\s+)/u', $text) ?: [];
        $targetClauses = array_values(array_filter(
            $clauses,
            fn (string $clause) => preg_match('/(?:стать(?:я|и|ю|е)|бап(?:тың|ты|та)?)\s+'.$article.'\b/iu', $clause) === 1,
        ));

        return $this->versionsMentionedInText(implode("\n", $targetClauses), $versions, $candidateVersionIds);
    }

    private function versionsMentionedInText(string $text, $versions, array $candidateVersionIds): array
    {
        $normalizedText = $this->normalizeTitle($text);

        if ($normalizedText === '') {
            return [];
        }

        return collect($candidateVersionIds)
            ->filter(function (int $versionId) use ($versions, $normalizedText) {
                $title = (string) $versions->get($versionId)?->source?->title;

                return $this->titleMatchesText($title, $normalizedText);
            })
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function titleMatchesText(string $title, string $normalizedText): bool
    {
        $normalizedTitle = $this->normalizeTitle($title);

        if ($normalizedTitle === '' || mb_strlen($normalizedTitle) < 8) {
            return false;
        }

        if (str_contains($normalizedText, $normalizedTitle)) {
            return true;
        }

        $core = preg_replace(
            '/^(?:(?:закон|кодекс|постановление|приказ|правила|методика)\s+)?(?:республика\s+казахстан\s+)?/u',
            '',
            $normalizedTitle,
        ) ?? $normalizedTitle;
        $coreTokens = preg_split('/\s+/u', trim($core)) ?: [];

        return count($coreTokens) >= 3
            && mb_strlen($core) >= 14
            && str_contains($normalizedText, $core);
    }

    private function normalizeTitle(string $value): string
    {
        $value = Normalizer::isNormalized($value) ? $value : (Normalizer::normalize($value) ?: $value);
        $value = mb_strtolower($value);
        $value = preg_replace_callback('/\b(закона|кодекса|постановления|приказа|правил|методики|республики)\b/u', fn ($match) => match ($match[1]) {
            'закона' => 'закон',
            'кодекса' => 'кодекс',
            'постановления' => 'постановление',
            'приказа' => 'приказ',
            'правил' => 'правила',
            'методики' => 'методика',
            'республики' => 'республика',
        }, $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalizeComparableText(string $value): string
    {
        $value = Normalizer::isNormalized($value) ? $value : (Normalizer::normalize($value) ?: $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        return mb_strtolower($value);
    }

    private function uniqueVersion(array $ids): ?int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return count($ids) === 1 ? $ids[0] : null;
    }

    private function uniqueSourceVersion(array $ids, $versions, array $articleGroups): ?int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return null;
        }

        $sourceIds = collect($ids)
            ->map(fn (int $id) => (int) $versions->get($id)?->source_id)
            ->filter()
            ->unique()
            ->values();

        if ($sourceIds->count() !== 1) {
            return null;
        }

        $articleVersionIds = collect($articleGroups)
            ->where('source_id', $sourceIds->first())
            ->pluck('source_version_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($articleVersionIds->count() === 1) {
            return $articleVersionIds->first();
        }

        return count($ids) === 1 ? $ids[0] : null;
    }

    private function result(
        string $status,
        $versions,
        array $signals,
        array $candidateVersionIds,
        ?int $selectedVersionId = null,
        array $resolutionEvidence = [],
        array $ambiguityReasons = [],
    ): array {
        $selected = $selectedVersionId !== null ? $versions->get($selectedVersionId) : null;

        return [
            'source_resolution_status' => $status,
            'target_source_id' => $selected?->source_id,
            'target_source_version_id' => $selected?->id,
            'target_source_title' => $selected?->source?->title,
            'source_resolution_evidence' => array_values(array_unique($resolutionEvidence)),
            'candidates' => collect($candidateVersionIds)->map(function (int $versionId) use ($versions, $signals) {
                $version = $versions->get($versionId);

                return [
                    'source_id' => $version?->source_id,
                    'source_version_id' => $versionId,
                    'source_title' => $version?->source?->title,
                    'signals' => array_values(array_unique($signals[$versionId] ?? [])),
                ];
            })->values()->all(),
            'source_resolution_ambiguity_reasons' => array_values(array_unique($ambiguityReasons)),
        ];
    }
}
