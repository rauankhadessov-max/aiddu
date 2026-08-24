<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique('sources_type_number_unique');

            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();

            $table->softDeletes();
            $table->index(['user_id', 'status']);
            $table->index(['type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['type', 'number']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'deleted_at']);
            $table->unique(['type', 'number']);
        });
    }
};
