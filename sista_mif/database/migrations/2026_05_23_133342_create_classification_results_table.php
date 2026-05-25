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
        Schema::create('classification_results', function (Blueprint $table) {
            $table->id();
            $table->string('nim')->index();
            $table->string('nama');
            $table->string('tahun_lulus')->nullable();
            $table->text('job_text_raw');
            $table->enum('source_type', ['internal_mif', 'kemendik'])->default('internal_mif');
            $table->string('predicted_profile')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('classification_method')->nullable();
            $table->enum('status', ['auto_classified', 'needs_review', 'manual_override', 'failed'])->default('needs_review');
            $table->text('error_detail')->nullable();
            $table->timestamps();
            $table->unique(['nim', 'source_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classification_results');
    }
};
