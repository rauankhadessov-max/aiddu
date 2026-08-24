<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('purpose')->unique();
            $table->string('name');
            $table->string('workspace_title');
            $table->text('workspace_description')->nullable();
            $table->string('workspace_category')->default('legal_norm');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('regulatory_profile_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulatory_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['regulatory_profile_id', 'source_id'], 'reg_profile_source_unique');
            $table->index(['regulatory_profile_id', 'sort_order'], 'reg_profile_sort_index');
        });

        DB::table('regulatory_profiles')->insert([
            'purpose' => 'new_user_default',
            'name' => 'Стартовый профиль: долевое участие в жилищном строительстве',
            'workspace_title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
            'workspace_description' => 'Рабочее дело со стартовой нормативной базой.',
            'workspace_category' => 'legal_norm',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_profile_sources');
        Schema::dropIfExists('regulatory_profiles');
    }
};
