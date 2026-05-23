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
        Schema::create('kemendik_raw_data', function (Blueprint $table) {
            $table->id();
            $table->string('nimhsmsmh')->index();
            $table->string('nmmhsmsmh');
            $table->string('tahun_lulus')->nullable();
            $table->string('f8_status')->nullable();
            $table->string('f5b_nama_perusahaan')->nullable();
            $table->string('f5c_jabatan_kode')->nullable();
            $table->string('f5c_jabatan_text')->nullable();
            $table->string('f1101_jenis_instansi_kode')->nullable();
            $table->string('f1101_jenis_instansi_text')->nullable();
            $table->string('f5a1_provinsi')->nullable();
            $table->string('f5a2_kabupaten')->nullable();
            $table->decimal('f505_pendapatan', 15, 2)->nullable();
            $table->json('raw_data')->nullable();
            $table->string('source_type')->default('kemendik');
            $table->timestamp('imported_at')->useCurrent();
            $table->unique(['nimhsmsmh', 'source_type']); 
            $table->index(['f1101_jenis_instansi_kode', 'f5a1_provinsi', 'tahun_lulus'], 'idx_kem_inst_prov_tahun');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kemendik_raw_data');
    }
};
