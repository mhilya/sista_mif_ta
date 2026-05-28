<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn(['nama', 'tahun_lulus']);
            $table->index(['source_type', 'status']);
            $table->index('predicted_profile');
        });
    }

    public function down(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->string('nama')->nullable();
            $table->string('tahun_lulus')->nullable();
            $table->dropIndex(['source_type', 'status']);
            $table->dropIndex(['predicted_profile']);
        });
    }
};
