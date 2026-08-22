<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use App\Services\ArtifactDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ArtifactDocxController extends Controller
{
    public function download(
        Request $request,
        Artifact $artifact,
        ArtifactDocxService $service,
    ) {
        Gate::authorize('view', $artifact);

        try {
            $representation = $service->generate($artifact, $request->user());
            if (! $service->isValid($representation)) {
                throw new RuntimeException('DOCX не прошёл проверку целостности и не может быть скачан.');
            }

            return Storage::disk($representation->storage_disk)->download(
                $representation->storage_path,
                $representation->filename,
                [
                    'Content-Type' => ArtifactDocxService::MIME_TYPE,
                    'X-Content-Type-Options' => 'nosniff',
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Не удалось сформировать DOCX. Попробуйте повторить позже или обратитесь к администратору.');
        }
    }
}
