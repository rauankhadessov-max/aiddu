<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->boolean('is_primary')
                ->default(false);

            $table->timestamps();

            $table->unique(['workspace_id', 'source_id']);

            $table->index(['workspace_id', 'is_primary']);
            $table->index('source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_sources');
    }
};
