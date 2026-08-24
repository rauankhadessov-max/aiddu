<?php
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\DraftPackageController;
use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\ArtifactDocxController;
use App\Http\Controllers\AnalysisWorkflowController;
use App\Http\Controllers\RegulatoryProfileController;
use App\Http\Controllers\InlineWorkspaceSourceController;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {

Route::get('/analyses', [AnalysisController::class, 'index'])
    ->name('analyses.index');

Route::get('/analyses/create', [AnalysisWorkflowController::class, 'create'])
    ->name('analyses.workflow.create');

Route::post('/analyses', [AnalysisWorkflowController::class, 'store'])
    ->name('analyses.workflow.store');

Route::get('/analyses/{analysis}/edit', [AnalysisWorkflowController::class, 'edit'])
    ->name('analyses.workflow.edit');

Route::patch('/analyses/{analysis}', [AnalysisWorkflowController::class, 'update'])
    ->name('analyses.workflow.update');

Route::delete('/analyses/{analysis}', [AnalysisController::class, 'destroy'])
    ->name('analyses.destroy');

Route::get('/workspaces/{workspace}/sources', [WorkspaceController::class, 'sources'])
    ->name('workspaces.sources');

Route::post('/api/workspaces/{workspace}/sources/inline', [InlineWorkspaceSourceController::class, 'store'])
    ->name('workspaces.sources.inline.store');

Route::post('/workspaces/{workspace}/sources/{source}', [WorkspaceController::class, 'attachSource'])
    ->name('workspaces.sources.attach');

Route::get('/sources', [SourceController::class, 'index'])
    ->name('sources.index');

Route::get('/sources/create', [SourceController::class, 'create'])
    ->name('sources.create');

Route::post('/sources', [SourceController::class, 'store'])
    ->name('sources.store');

Route::get('/admin/regulatory-profiles/default', [RegulatoryProfileController::class, 'edit'])
    ->name('regulatory-profiles.default.edit');

Route::patch('/admin/regulatory-profiles/default', [RegulatoryProfileController::class, 'update'])
    ->name('regulatory-profiles.default.update');

Route::get('/sources/{source}', [SourceController::class, 'show'])
    ->name('sources.show');

Route::delete('/sources/{source}', [SourceController::class, 'destroy'])
    ->name('sources.destroy');

Route::get('/sources/{source}/versions/create', [SourceController::class, 'createVersion'])
    ->name('source-versions.create');

Route::post('/sources/{source}/versions', [SourceController::class, 'storeVersion'])
    ->name('source-versions.store');

Route::get('/sources/{source}/versions/{version}/edit', [SourceController::class, 'editVersion'])
    ->name('source-versions.edit');

Route::put('/sources/{source}/versions/{version}', [SourceController::class, 'updateVersion'])
    ->name('source-versions.update');

    Route::get('/documents/{document}/analyses/create', [AnalysisController::class, 'create'])
    ->name('analyses.create');

    Route::post('/documents/{document}/analyses', [AnalysisController::class, 'store'])
    ->name('analyses.store');

    Route::get('/analyses/{analysis}', [AnalysisController::class, 'show'])
    ->name('analyses.show');

    Route::post('/analyses/{analysis}/run', [AnalysisController::class, 'run'])
    ->name('analyses.run');

    Route::post('/analyses/{analysis}/draft-package', [DraftPackageController::class, 'store'])
        ->name('draft-packages.store');

    Route::get('/draft-packages/{draftPackage}', [DraftPackageController::class, 'show'])
        ->name('draft-packages.show');

    Route::get('/artifacts/{artifact}', [ArtifactController::class, 'show'])
        ->name('artifacts.show');

    Route::post('/artifacts/{artifact}/docx', [ArtifactDocxController::class, 'download'])
        ->name('artifacts.docx.download');

    Route::get('/workspaces', [WorkspaceController::class, 'index'])
        ->name('workspaces.index');

    Route::get('/workspaces/create', [WorkspaceController::class, 'create'])
        ->name('workspaces.create');

    Route::post('/workspaces', [WorkspaceController::class, 'store'])
        ->name('workspaces.store');

    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])
        ->name('workspaces.show');

    Route::get('/workspaces/{workspace}/documents/create', [DocumentController::class, 'create'])
    ->name('documents.create');

    Route::post('/workspaces/{workspace}/documents', [DocumentController::class, 'store'])
    ->name('documents.store');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->name('documents.show');

    Route::patch('/documents/{document}/analysis-instruction', [DocumentController::class, 'updateAnalysisInstruction'])
    ->name('documents.analysis-instruction.update');

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

require __DIR__.'/auth.php';
