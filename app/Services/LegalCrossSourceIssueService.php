<?php

namespace App\Services;

use App\Models\Analysis;

class LegalCrossSourceIssueService
{
    private const GENERIC_TITLE_TERMS = [
        'закон', 'кодекс', 'республик', 'казахстан', 'постановлен', 'приказ',
        'правил', 'методик', 'типов', 'форм', 'договор', 'нормативн', 'правов',
    ];

    public function __construct(private readonly LegalRetrievalTextNormalizer $normalizer) {}

    public function discover(
        Analysis $analysis,
        array $excludedSourceVersionIds = [],
        array $additionalIssues = [],
    ): array {
        $analysis->loadMissing(['document', 'sourceVersions.source']);
        $excluded = array_fill_keys(array_map('intval', $excludedSourceVersionIds), true);
        $clauses = collect([(string) $analysis->instruction, ...$additionalIssues])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->flatMap(fn (string $value) => $this->clauses($value))
            ->unique()
            ->values()
            ->all();
        $versions = $analysis->sourceVersions
            ->reject(fn ($version) => isset($excluded[(int) $version->id]))
            ->values();
        $issues = [];

        foreach ($clauses as $clauseIndex => $clause) {
            $clauseTerms = array_values(array_unique($this->normalizer->tokens($clause)));
            $candidates = [];

            foreach ($versions as $version) {
                $titleTerms = $this->distinctiveTitleTerms((string) $version->source->title);
                $matches = array_values(array_intersect($titleTerms, $clauseTerms));

                if ($matches === []) {
                    continue;
                }

                $candidates[] = [
                    'source_id' => (int) $version->source_id,
                    'source_version_id' => (int) $version->id,
                    'source_title' => (string) $version->source->title,
                    'matched_title_terms' => $matches,
                    'match_count' => count($matches),
                    'coverage' => count($matches) / max(1, count($titleTerms)),
                ];
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, fn (array $a, array $b) => $b['match_count'] <=> $a['match_count']
                ?: $b['coverage'] <=> $a['coverage']
                ?: $a['source_version_id'] <=> $b['source_version_id']);
            $best = $candidates[0];
            $ties = array_values(array_filter($candidates, fn (array $candidate) => $candidate['match_count'] === $best['match_count']
                && abs($candidate['coverage'] - $best['coverage']) < 0.000001
            ));
            $issueId = 'issue-'.substr(hash('sha256', $clauseIndex.'|'.$clause), 0, 12);

            if (collect($ties)->pluck('source_id')->unique()->count() !== 1) {
                $issues[] = [
                    'issue_id' => $issueId,
                    'label' => $clause,
                    'query' => $clause,
                    'phrases' => $this->legalPhrases($clause),
                    'status' => 'ambiguous_source',
                    'requested_sources' => $ties,
                ];

                continue;
            }

            $headings = $this->documentStructuralHeadings($analysis);
            $issues[] = [
                'issue_id' => $issueId,
                'label' => $clause,
                'query' => trim($clause."\n".implode("\n", $headings)),
                'phrases' => $this->legalPhrases($clause),
                'status' => 'resolved',
                'requested_sources' => [$best],
            ];
        }

        return collect($issues)
            ->unique(fn (array $issue) => $issue['status'].'|'.data_get($issue, 'requested_sources.0.source_version_id').'|'.$issue['label'])
            ->values()
            ->all();
    }

    private function clauses(string $instruction): array
    {
        $parts = preg_split('/(?:\R+|;|(?<=[.!?])\s+|\s+[—–]\s+)/u', $instruction) ?: [];

        return collect($parts)
            ->map(fn (string $part) => trim($part, " \t\n\r\0\x0B—–-"))
            ->filter(fn (string $part) => mb_strlen($part) >= 18)
            ->values()
            ->all();
    }

    private function distinctiveTitleTerms(string $title): array
    {
        $terms = array_values(array_unique($this->normalizer->tokens($title)));
        $distinctive = array_values(array_filter($terms, fn (string $term) => ! in_array($term, self::GENERIC_TITLE_TERMS, true)
        ));

        return $distinctive !== [] ? $distinctive : $terms;
    }

    private function legalPhrases(string $clause): array
    {
        $phrases = [];
        $patterns = [
            '/(?:включая|соның ішінде)\s+(?:принцип\s+)?([^,;.]{5,100})/iu',
            '/(?:принцип|қағидат)\s+([^,;.]{5,100})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $clause, $match) === 1) {
                $phrases[] = trim($match[1]);
            }
        }

        return array_values(array_unique($phrases));
    }

    private function documentStructuralHeadings(Analysis $analysis): array
    {
        $headings = [];

        foreach ([$analysis->document?->current_text, $analysis->document?->proposed_text] as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            preg_match_all('/^[\h]*(?:Статья|Бап)\h+[\d]+(?:[-.]\d+)*(?:\.|\h)\h*(.+)$/imu', $text, $matches);

            foreach ($matches[1] ?? [] as $heading) {
                $headings[] = trim($heading);
            }
        }

        return array_values(array_unique($headings));
    }
}
