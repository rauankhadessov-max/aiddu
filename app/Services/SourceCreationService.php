<?php

namespace App\Services;

use App\Models\Source;
use App\Models\RegulatoryProfile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SourceCreationService
{
    public function __construct(
        private readonly SourceVersionContentService $contentService,
        private readonly RegulatoryProfileWorkspaceSynchronizer $profileSynchronizer,
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

    public function createForWorkspace(
        User $actor,
        Workspace $workspace,
        array $data,
        ?UploadedFile $docxFile,
        string $visibility,
    ): Source {
        if ((int) $workspace->user_id !== (int) $actor->id) {
            abort(403);
        }

        $global = $visibility === 'global';

        if ($global && !$actor->is_admin) {
            abort(403);
        }

        return DB::transaction(function () use ($actor, $workspace, $data, $docxFile, $global) {
            $source = $this->createRecord(
                $global ? null : $actor,
                $data,
                $docxFile,
                $workspace,
            );

            if ($global) {
                $profile = $workspace->regulatoryProfile()
                    ->where('is_active', true)
                    ->where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)
                    ->first();

                if ($profile) {
                    $this->profileSynchronizer->attachGlobalSource($profile, $source);
                }
            }

            return $source->fresh('versions');
        });
    }

    private function create(?User $owner, array $data, ?UploadedFile $docxFile, ?Workspace $workspace = null): Source
    {
        return DB::transaction(function () use ($owner, $data, $docxFile, $workspace) {
            return $this->createRecord($owner, $data, $docxFile, $workspace);
        });
    }

    private function createRecord(?User $owner, array $data, ?UploadedFile $docxFile, ?Workspace $workspace): Source
    {
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

        return $source;
    }
}
