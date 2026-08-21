<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->foreignId('document_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained()
                ->nullOnDelete();

            $table->longText('instruction')
                ->nullable()
                ->after('analysis_type');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->dropColumn('instruction');
        });
    }
};
