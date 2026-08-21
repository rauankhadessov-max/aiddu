<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title')->nullable();

            $table->string('file_role')
                ->default('attachment');

            $table->string('original_filename');

            $table->string('stored_filename');

            $table->string('storage_disk')
                ->default('local');

            $table->string('storage_path');

            $table->string('mime_type')
                ->nullable();

            $table->string('extension', 20)
                ->nullable();

            $table->unsignedBigInteger('file_size')
                ->nullable();

            $table->string('checksum', 64)
                ->nullable();

            $table->text('description')
                ->nullable();

            $table->timestamp('uploaded_at')
                ->nullable();

            $table->timestamps();

            $table->index('document_id');
            $table->index('file_role');
            $table->index('uploaded_by');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
