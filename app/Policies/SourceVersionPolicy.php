<?php

namespace App\Policies;

use App\Models\SourceVersion;
use App\Models\User;

class SourceVersionPolicy
{
    public function view(User $user, SourceVersion $version): bool
    {
        return $version->source !== null
            && $user->can('view', $version->source);
    }

    public function update(User $user, SourceVersion $version): bool
    {
        return $version->source !== null
            && $user->can('update', $version->source);
    }

    public function delete(User $user, SourceVersion $version): bool
    {
        return $this->update($user, $version);
    }
}
