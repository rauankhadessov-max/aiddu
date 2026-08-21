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
        Schema::create('sources', function (Blueprint $table) {

            $table->id();

            $table->string('title');

            $table->string('type');

            $table->string('number')
                ->nullable();

            $table->date('adoption_date')
                ->nullable();

            $table->string('issuing_authority')
                ->nullable();

            $table->string('status')
                ->default('active');

            $table->string('official_url')
                ->nullable();

            $table->text('description')
                ->nullable();

            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('adoption_date');
            $table->unique(['type', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
