<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('regulatory_profile_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unique(['user_id', 'regulatory_profile_id'], 'workspace_user_profile_unique');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique('workspace_user_profile_unique');
            $table->dropForeign(['regulatory_profile_id']);
            $table->dropColumn('regulatory_profile_id');
        });
    }
};
