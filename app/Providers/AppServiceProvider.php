<?php

namespace App\Providers;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\RegulatoryProfile;
use App\Models\Workspace;
use App\Policies\AnalysisPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\SourcePolicy;
use App\Policies\SourceVersionPolicy;
use App\Policies\RegulatoryProfilePolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Analysis::class, AnalysisPolicy::class);
        Gate::policy(Source::class, SourcePolicy::class);
        Gate::policy(SourceVersion::class, SourceVersionPolicy::class);
        Gate::policy(RegulatoryProfile::class, RegulatoryProfilePolicy::class);
    }
}
