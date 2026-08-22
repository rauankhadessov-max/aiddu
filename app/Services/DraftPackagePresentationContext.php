<?php

namespace App\Services;

use App\Models\Artifact;

class DraftPackagePresentationContext
{
    public function __construct(private readonly DraftPackageInputBuilder $hasher) {}

    public function for(Artifact $sourceArtifact): array
    {
        $canonicalHash = $this->hasher->hashPayload($sourceArtifact->content);
        $draftNpa = $sourceArtifact->artifact_type === 'draft_npa'
            ? $sourceArtifact
            : $sourceArtifact->draftPackage
                ->artifacts()
                ->whereNull('source_artifact_id')
                ->where('format', 'structured_json')
                ->where('artifact_type', 'draft_npa')
                ->orderBy('id')
                ->first();

        return [
            'canonical_hash' => $canonicalHash,
            'comparative_table_hash' => $sourceArtifact->artifact_type === 'comparative_table'
                ? $canonicalHash
                : null,
            'draft_npa_artifact_id' => $draftNpa?->id,
            'draft_npa_hash' => $draftNpa !== null
                ? $this->hasher->hashPayload($draftNpa->content)
                : null,
            'draft_npa_content' => $draftNpa?->content,
        ];
    }
}
