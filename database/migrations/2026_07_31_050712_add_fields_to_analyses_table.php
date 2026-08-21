<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {

            $table->foreignId('workspace_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->unsignedBigInteger('document_version_id')
                ->nullable()
                ->after('workspace_id');

            $table->index('document_version_id');

            $table->foreignId('user_id')
                ->nullable()
                ->after('document_version_id')
                ->constrained()
                ->nullOnDelete();

            $table->string('title')
                ->nullable()
                ->after('user_id');

            $table->string('analysis_type')
                ->default('combined')
                ->after('title');

            $table->string('status')
                ->default('draft')
                ->after('analysis_type');

            $table->unsignedInteger('version')
                ->default(1)
                ->after('status');

            $table->string('ai_model')
                ->nullable()
                ->after('version');

            $table->json('settings')
                ->nullable()
                ->after('ai_model');

            $table->timestamp('started_at')
                ->nullable()
                ->after('settings');

            $table->timestamp('completed_at')
                ->nullable()
                ->after('started_at');

            $table->text('summary')
                ->nullable()
                ->after('completed_at');

            $table->text('error_message')
                ->nullable()
                ->after('summary');

            $table->index(['workspace_id', 'status']);
            $table->index('analysis_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {

            $table->dropIndex(['workspace_id', 'status']);
            $table->dropIndex(['analysis_type']);
            $table->dropIndex(['document_version_id']);

            $table->dropConstrainedForeignId('workspace_id');
            $table->dropConstrainedForeignId('user_id');

            $table->dropColumn([
                'document_version_id',
                'title',
                'analysis_type',
                'status',
                'version',
                'ai_model',
                'settings',
                'started_at',
                'completed_at',
                'summary',
                'error_message',
            ]);
        });
    }
};
