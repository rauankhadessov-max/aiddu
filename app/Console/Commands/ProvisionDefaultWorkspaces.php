<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DefaultWorkspaceProvisioner;
use Illuminate\Console\Command;

class ProvisionDefaultWorkspaces extends Command
{
    protected $signature = 'regulatory-profile:provision-existing
        {--apply : Apply the displayed plan}
        {--user=* : Limit the operation to specific user IDs}';

    protected $description = 'Preview or apply the default regulatory workspace profile to existing users';

    public function handle(DefaultWorkspaceProvisioner $provisioner): int
    {
        $profile = $provisioner->defaultProfile();
        $query = User::query()->with('workspaces')->orderBy('id');
        if ($ids = array_filter(array_map('intval', $this->option('user')))) {
            $query->whereIn('id', $ids);
        }

        foreach ($query->get() as $user) {
            $linked = $user->workspaces->firstWhere('regulatory_profile_id', $profile->id);
            $matches = $user->workspaces->where('title', $profile->workspace_title);

            if ($linked) {
                $action = 'sync existing profile workspace #'.$linked->id;
                $adopt = null;
            } elseif ($matches->count() === 1) {
                $action = 'adopt matching workspace #'.$matches->first()->id;
                $adopt = $matches->first();
            } elseif ($matches->count() > 1) {
                $this->warn("User #{$user->id}: skipped; multiple matching workspaces require manual resolution.");
                continue;
            } else {
                $action = 'create default workspace';
                $adopt = null;
            }

            $this->line("User #{$user->id}: {$action}");
            if ($this->option('apply')) {
                $provisioner->provision($user, $adopt);
            }
        }

        if (!$this->option('apply')) {
            $this->info('Dry-run only. Re-run with --apply after reviewing this plan.');
        }

        return self::SUCCESS;
    }
}
