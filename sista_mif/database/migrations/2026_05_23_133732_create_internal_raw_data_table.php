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
        Schema::create('internal_raw_data', function (Blueprint $table) {
            $table->id();
            $table->string('nama_lengkap')->nullable();
            $table->string('nim')->index();
            $table->string('email')->nullable();
            $table->string('no_telepon')->nullable();
            $table->text('alamat_domisili')->nullable();
            $table->string('jurusan')->nullable();
            $table->string('program_studi')->nullable();
            $table->string('tahun_masuk')->nullable();
            $table->string('tahun_lulus')->nullable();
            $table->string('status_pekerjaan')->nullable();
            $table->string('klasifikasi_pekerjaan')->nullable();
            $table->string('nama_perusahaan')->nullable();
            $table->string('jenis_perusahaan')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('lokasi_perusahaan')->nullable();
            $table->text('alamat_perusahaan')->nullable();
            $table->text('deskripsi_pekerjaan')->nullable();
            $table->string('tahun_mulai_kerja')->nullable();
            $table->string('masa_kerja_bulan')->nullable();
            $table->string('jumlah_lamaran_dikirim')->nullable();
            $table->string('jumlah_respons_lamaran')->nullable();
            $table->string('jumlah_undangan_wawancara')->nullable();
            $table->string('masa_tunggu_pra_lulus')->nullable();
            $table->string('masa_tunggu_pasca_lulus')->nullable();
            $table->string('total_masa_tunggu')->nullable();
            $table->string('instansi_terupdate')->nullable();
            $table->string('jabatan_terupdate')->nullable();
            $table->string('tahun_bekerja')->nullable();
            $table->string('status_pekerjaan_terupdate')->nullable();
            $table->string('linkedin_profile')->nullable();
            $table->string('sosmed_ig')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internal_raw_data');
    }
};
