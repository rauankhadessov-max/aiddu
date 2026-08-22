<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->foreignId('source_artifact_id')
                ->nullable()
                ->after('draft_package_id')
                ->constrained('artifacts')
                ->cascadeOnDelete();
            $table->string('renderer_version')->nullable()->after('file_size');
            $table->char('source_content_hash', 64)->nullable()->after('renderer_version');
            $table->char('logical_content_hash', 64)->nullable()->after('source_content_hash');
            $table->char('binary_sha256', 64)->nullable()->after('logical_content_hash');

            $table->unique(
                ['source_artifact_id', 'artifact_type', 'renderer_version', 'source_content_hash'],
                'artifacts_docx_representation_unique',
            );
            $table->index('binary_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropUnique('artifacts_docx_representation_unique');
            $table->dropIndex(['binary_sha256']);
            $table->dropForeign(['source_artifact_id']);
            $table->dropColumn([
                'source_artifact_id',
                'renderer_version',
                'source_content_hash',
                'logical_content_hash',
                'binary_sha256',
            ]);
        });
    }
};
