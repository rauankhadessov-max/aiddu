<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $this->ownsDocument($user, $document);
    }

    public function createAnalysis(User $user, Document $document): bool
    {
        return $this->ownsDocument($user, $document);
    }

    private function ownsDocument(User $user, Document $document): bool
    {
        return $document->user_id === $user->id
            && $document->workspace?->user_id === $user->id;
    }
}
