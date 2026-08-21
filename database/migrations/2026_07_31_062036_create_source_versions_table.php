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
        Schema::create('source_versions', function (Blueprint $table) {

            $table->id();

            $table->foreignId('source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('version_name');

            $table->date('effective_date')
                ->nullable();

            $table->longText('text');

            $table->string('hash', 64);

            $table->timestamps();

            $table->index('effective_date');
            $table->index('hash');
            $table->unique(['source_id', 'hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_versions');
    }
};
