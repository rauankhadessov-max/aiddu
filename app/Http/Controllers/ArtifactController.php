<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use App\Services\DraftPackagePresentationService;
use Illuminate\Support\Facades\Gate;

class ArtifactController extends Controller
{
    public function show(Artifact $artifact, DraftPackagePresentationService $presentationService)
    {
        Gate::authorize('view', $artifact);
        $artifact->load('draftPackage.analysis.document');

        $view = match ($artifact->artifact_type) {
            'comparative_table' => 'artifacts.comparative-table',
            'draft_npa' => 'artifacts.draft-npa',
            default => abort(404),
        };
        $presentation = match ($artifact->artifact_type) {
            'comparative_table' => $presentationService->comparativeTable($artifact),
            'draft_npa' => $presentationService->draftNpa($artifact),
            default => abort(404),
        };

        return view($view, compact('artifact', 'presentation'));
    }
}
