<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_findings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('analysis_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('finding_type');

            $table->string('severity')
                ->default('medium');

            $table->string('status')
                ->default('open');

            $table->string('title');

            $table->longText('description')
                ->nullable();

            $table->longText('document_fragment')
                ->nullable();

            $table->string('document_location')
                ->nullable();

            $table->text('source_reference')
                ->nullable();

            $table->longText('legal_basis')
                ->nullable();

            $table->longText('recommendation')
                ->nullable();

            $table->longText('recommended_text')
                ->nullable();

            $table->longText('justification')
                ->nullable();

            $table->unsignedTinyInteger('confidence_score')
                ->nullable();

            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->timestamps();

            $table->index(['analysis_id', 'severity']);
            $table->index('finding_type');
            $table->index('status');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_findings');
    }
};
