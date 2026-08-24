<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;

class SourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Source $source): bool
    {
        return $source->isGlobal() || $source->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Source $source): bool
    {
        return $source->isGlobal()
            ? (bool) $user->is_admin
            : $source->isOwnedBy($user);
    }

    public function delete(User $user, Source $source): bool
    {
        return $this->update($user, $source);
    }
}
