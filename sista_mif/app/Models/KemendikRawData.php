<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KemendikRawData extends Model
{
    protected $table = 'kemendik_raw_data';
    protected $guarded = ['id'];
    protected $casts = ['raw_data' => 'array', 'f505_pendapatan' => 'float', 'imported_at' => 'datetime'];
    public $timestamps = false;
    
    public function getJabatanTextAttribute(): string {
        $map = ['1'=>'Founder/Owner','2'=>'Co-Founder','3'=>'Staff/Karyawan','4'=>'Freelance'];
        return $map[$this->f5c_jabatan_kode] ?? $this->f5c_jabatan_text ?? '-';
    }
    public function getJenisInstansiTextAttribute(): string {
        $map = ['1'=>'Instansi Pemerintah','2'=>'Non-profit','3'=>'Perusahaan Swasta','4'=>'Wiraswasta','6'=>'BUMN/BUMD','7'=>'Multilateral'];
        return $map[$this->f1101_jenis_instansi_kode] ?? $this->f1101_jenis_instansi_text ?? '-';
    }
}
