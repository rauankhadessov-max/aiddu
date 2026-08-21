<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');

            $table->string('document_type')
                ->default('other');

            $table->string('input_type')
                ->default('text');

            $table->string('language')
                ->default('ru');

            $table->string('status')
                ->default('draft');

            $table->longText('content_text')
                ->nullable();

            $table->longText('current_text')
                ->nullable();

            $table->longText('proposed_text')
                ->nullable();

            $table->timestamp('uploaded_at')
                ->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index('document_type');
            $table->index('input_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
