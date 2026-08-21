<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('draft_package_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('artifact_type');

            $table->string('format')
                ->nullable();

            $table->string('title');

            $table->longText('content')
                ->nullable();

            $table->string('storage_disk')
                ->default('local');

            $table->string('storage_path')
                ->nullable();

            $table->string('filename')
                ->nullable();

            $table->string('mime_type')
                ->nullable();

            $table->unsignedBigInteger('file_size')
                ->nullable();

            $table->string('status')
                ->default('draft');

            $table->timestamp('generated_at')
                ->nullable();

            $table->timestamps();

            $table->index('draft_package_id');
            $table->index('artifact_type');
            $table->index('format');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
