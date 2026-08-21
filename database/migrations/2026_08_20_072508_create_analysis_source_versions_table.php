<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_source_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('analysis_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('source_version_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role')
                ->default('reference');

            $table->timestamps();

            $table->unique(['analysis_id', 'source_version_id']);

            $table->index(['analysis_id', 'role']);
            $table->index('source_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_source_versions');
    }
};
