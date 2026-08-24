<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceRequest;
use App\Models\Workspace;
use App\Services\SourceCreationService;
use Illuminate\Support\Facades\Gate;

class InlineWorkspaceSourceController extends Controller
{
    public function store(StoreSourceRequest $request, Workspace $workspace, SourceCreationService $creationService)
    {
        Gate::authorize('update', $workspace);

        $source = $creationService->createPersonal(
            $request->user(),
            $request->validated(),
            $request->file('docx_file'),
            $workspace,
        );
        $version = $source->versions->first();

        return response()->json([
            'source' => [
                'id' => $source->id,
                'title' => $source->title,
                'type_label' => $source->typeLabel(),
                'official_url' => $source->official_url,
            ],
            'version' => $version ? [
                'id' => $version->id,
                'version_name' => $version->version_name,
                'effective_date' => $version->effective_date?->format('d.m.Y'),
            ] : null,
            'message' => $version
                ? 'Личный НПА добавлен, подключён к рабочему делу и выбран для анализа.'
                : 'Ссылка сохранена. Добавьте нормативный текст, чтобы использовать НПА в анализе.',
        ], 201);
    }
}
