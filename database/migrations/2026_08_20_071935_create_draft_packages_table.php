<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('analysis_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');

            $table->string('package_type')
                ->default('comprehensive');

            $table->string('status')
                ->default('draft');

            $table->text('description')
                ->nullable();

            $table->json('plan')
                ->nullable();

            $table->timestamp('approved_at')
                ->nullable();

            $table->timestamp('generated_at')
                ->nullable();

            $table->timestamps();

            $table->index(['analysis_id', 'status']);
            $table->index('package_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_packages');
    }
};
