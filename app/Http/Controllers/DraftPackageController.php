<?php

namespace App\Http\Controllers;

use App\Models\Analysis;
use App\Models\DraftPackage;
use App\Services\DraftPackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class DraftPackageController extends Controller
{
    public function show(DraftPackage $draftPackage)
    {
        Gate::authorize('view', $draftPackage);
        $draftPackage->load(['analysis.document', 'artifacts']);

        return view('draft-packages.show', compact('draftPackage'));
    }

    public function store(Request $request, Analysis $analysis, DraftPackageService $service)
    {
        Gate::authorize('createDraftPackage', $analysis);

        try {
            $package = $service->generate($analysis, $request->user());

            return redirect()
                ->route('analyses.show', $analysis)
                ->with('success', 'Пакет документов сформирован.')
                ->with('draft_package_id', $package->id);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('analyses.show', $analysis)
                ->with('error', 'Не удалось сформировать пакет документов: '.$exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('analyses.show', $analysis)
                ->with('error', 'Не удалось сформировать пакет документов. Попробуйте повторить позже или обратитесь к администратору.');
        }
    }
}
