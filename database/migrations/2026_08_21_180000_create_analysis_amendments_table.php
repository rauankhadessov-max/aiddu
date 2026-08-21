<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_version_id')->constrained()->restrictOnDelete();
            $table->string('target_mode');
            $table->string('structural_element_type');
            $table->string('section')->nullable();
            $table->string('chapter')->nullable();
            $table->string('part')->nullable();
            $table->string('article')->nullable();
            $table->string('paragraph')->nullable();
            $table->string('subparagraph')->nullable();
            $table->string('text_paragraph')->nullable();
            $table->string('appendix')->nullable();
            $table->string('proposed_locator')->nullable();
            $table->string('amendment_type');
            $table->string('disposition');
            $table->longText('current_text')->nullable();
            $table->longText('proposed_text')->nullable();
            $table->longText('justification');
            $table->longText('legal_basis');
            $table->text('source_reference');
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->json('warnings')->nullable();
            $table->json('target_fragment_ids')->nullable();
            $table->json('anchor_fragment_ids')->nullable();
            $table->json('citations');
            $table->json('target_snapshot');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['analysis_id', 'sort_order']);
            $table->index(['source_version_id', 'target_mode']);
            $table->index(['amendment_type', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_amendments');
    }
};
