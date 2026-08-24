<?php

namespace App\Services;

use App\Models\Analysis;
use App\Models\User;
use Throwable;

class DraftPackageAutoGenerationService
{
    public function __construct(
        private readonly DraftPackageService $draftPackageService,
    ) {}

    /**
     * @return string|null A user-facing error, or null when generated/skipped.
     */
    public function generate(Analysis $analysis, User $user): ?string
    {
        try {
            $analysis = Analysis::query()->findOrFail($analysis->id);

            if (
                $analysis->status !== 'completed'
                || ! $analysis->amendments()->exists()
                || $analysis->draftPackage()->exists()
            ) {
                return null;
            }

            $this->draftPackageService->generate($analysis, $user);

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return 'Юридический анализ завершён, но пакет документов сформировать не удалось. Повторите формирование пакета.';
        }
    }
}
