<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkspaceSourceRequest;
use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\SourceCreationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkspaceSourceController extends Controller
{
    public function create(Request $request, Workspace $workspace)
    {
        Gate::authorize('update', $workspace);
        Gate::authorize('create', Source::class);

        $workspace->load('regulatoryProfile');
        $defaultVisibility = $request->user()->is_admin
            && $workspace->regulatoryProfile?->is_active
            && $workspace->regulatoryProfile->purpose === RegulatoryProfile::NEW_USER_DEFAULT
                ? 'global'
                : 'personal';

        return view('workspaces.sources.create', compact('workspace', 'defaultVisibility'));
    }

    public function store(
        StoreWorkspaceSourceRequest $request,
        Workspace $workspace,
        SourceCreationService $creationService,
    ) {
        Gate::authorize('update', $workspace);
        Gate::authorize('create', Source::class);

        $validated = $request->validated();
        $visibility = $request->user()->is_admin
            ? $validated['visibility']
            : 'personal';
        $source = $creationService->createForWorkspace(
            $request->user(),
            $workspace,
            $validated,
            $request->file('docx_file'),
            $visibility,
        );

        return redirect()
            ->route('workspaces.sources', $workspace)
            ->with(
                'success',
                $source->versions->isNotEmpty()
                    ? 'НПА и редакция нормативного текста добавлены в рабочее дело.'
                    : 'Ссылка сохранена. Добавьте редакцию нормативного текста, чтобы использовать НПА в юридическом анализе.',
            );
    }
}
