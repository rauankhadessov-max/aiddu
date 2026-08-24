<?php

namespace App\Services;

use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SourceCreationService
{
    public function __construct(
        private readonly SourceVersionContentService $contentService,
    ) {
    }

    public function createGlobal(array $data, ?UploadedFile $docxFile): Source
    {
        return $this->create(null, $data, $docxFile);
    }

    public function createPersonal(User $owner, array $data, ?UploadedFile $docxFile, ?Workspace $workspace = null): Source
    {
        if ($workspace && $workspace->user_id !== $owner->id) {
            abort(403);
        }

        return $this->create($owner, $data, $docxFile, $workspace);
    }

    private function create(?User $owner, array $data, ?UploadedFile $docxFile, ?Workspace $workspace = null): Source
    {
        return DB::transaction(function () use ($owner, $data, $docxFile, $workspace) {
            $source = new Source([
                'title' => $data['title'],
                'type' => $data['type'],
                'status' => 'active',
                'official_url' => $data['input_method'] === 'url' ? $data['official_url'] : null,
            ]);
            if ($owner) {
                $source->user()->associate($owner);
            }
            $source->save();

            if ($data['input_method'] === 'docx') {
                $this->contentService->create(
                    $source,
                    'Редакция из загруженного DOCX',
                    null,
                    null,
                    $docxFile,
                );
            }

            if ($workspace) {
                $workspace->sources()->syncWithoutDetaching([$source->id]);
            }

            return $source->fresh('versions');
        });
    }
}
