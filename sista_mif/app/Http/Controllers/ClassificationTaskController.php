<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassificationResult;
use App\Models\KemendikRawData;
use App\Models\InternalRawData;
use App\Services\FastApiWorkerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClassificationTaskController extends Controller
{
    public function __construct(protected FastApiWorkerService $fastApi) {}

    public function upload(Request $request) {
        $request->validate(['file' => 'required|file|mimes:csv,xlsx,xls|max:20480']);
        
        $file = $request->file('file');
        $result = $this->fastApi->classifyFile($file->getRealPath(), $file->getClientOriginalName());
        
        if (isset($result['error'])) {
            if ($request->wantsJson()) return response()->json(['success' => false, 'message' => $result['error']], 400);
            return back()->with('error', $result['error']);
        }
        
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'job_id' => $result['job_id'] ?? null,
                'message' => $result['message'] ?? 'Proses dimulai...'
            ]);
        }
        return back()->with('success', 'File sedang diproses. Silakan refresh beberapa saat lagi.');
    }

    public function checkStatus(Request $request, string $jobId)
    {
        $status = $this->fastApi->getClassifyStatus($jobId);
        
        if (!isset($status['status']) || in_array($status['status'], ['pending', 'processing'])) {
            return response()->json($status);
        }

        if ($status['status'] === 'error') {
            return response()->json($status);
        }

        if ($status['status'] === 'success' && isset($status['results'])) {
            try {
                DB::transaction(function () use ($status) {
                    $chunks = array_chunk($status['results'], 500);
                    foreach ($chunks as $chunk) {
                        $kemendikUpsertData = [];
                        $internalResultsUpsertData = [];
                        $internalRawUpsertData = [];

                        foreach ($chunk as $row) {
                            if ($row['source_type'] === 'kemendik') {
                                $raw = $row['raw_data'] ?? [];
                                $kemendikUpsertData[] = [
                                    'nimhsmsmh' => $row['nim'], 
                                    'source_type' => 'kemendik',
                                    'nmmhsmsmh' => $row['nama'], 
                                    'tahun_lulus' => $row['tahun_lulus'] ?? null,
                                    'f5c_jabatan_kode' => $raw['F5c'] ?? null, 
                                    'f5c_jabatan_text' => $this->mapF5c($raw['F5c'] ?? null),
                                    'f1101_jenis_instansi_kode' => $raw['F1101'] ?? null, 
                                    'f1101_jenis_instansi_text' => $this->mapF1101($raw['F1101'] ?? null),
                                    'f5a1_provinsi' => $raw['F5a1'] ?? null, 
                                    'raw_data' => json_encode($raw) 
                                ];
                            } else {
                                $internalResultsUpsertData[] = [
                                    'nim' => $row['nim'], 
                                    'source_type' => 'internal_mif',
                                    'job_text_raw' => $row['job_text_raw'] ?? '', 
                                    'predicted_profile' => $row['predicted_profile'],
                                    'confidence_score' => $row['confidence_score'] ?? 0, 
                                    'classification_method' => $row['classification_method'],
                                    'status' => $row['status'], 
                                    'error_detail' => $row['error_detail'] ?? null
                                ];
                                
                                if (isset($row['raw_data'])) {
                                    $mapped = ['nim' => $row['nim']];
                                    $cols = [
                                        'nama_lengkap', 'email', 'no_telepon', 'alamat_domisili', 'jurusan',
                                        'program_studi', 'tahun_masuk', 'tahun_lulus', 'status_pekerjaan',
                                        'klasifikasi_pekerjaan', 'nama_perusahaan', 'jenis_perusahaan', 'jabatan',
                                        'lokasi_perusahaan', 'alamat_perusahaan', 'deskripsi_pekerjaan',
                                        'tahun_mulai_kerja', 'masa_kerja_bulan', 'jumlah_lamaran_dikirim',
                                        'jumlah_respons_lamaran', 'jumlah_undangan_wawancara', 'masa_tunggu_pra_lulus',
                                        'masa_tunggu_pasca_lulus', 'total_masa_tunggu', 'instansi_terupdate',
                                        'jabatan_terupdate', 'tahun_bekerja', 'status_pekerjaan_terupdate',
                                        'linkedin_profile', 'sosmed_ig'
                                    ];
                                    foreach ($cols as $col) {
                                        $val = $row['raw_data'][$col] ?? null;
                                        $mapped[$col] = $val === '' ? null : $val;
                                    }
                                    $internalRawUpsertData[] = $mapped;
                                }
                            }
                        }

                        if (!empty($kemendikUpsertData)) {
                            KemendikRawData::upsert(
                                $kemendikUpsertData, 
                                ['nimhsmsmh', 'source_type'], 
                                ['nmmhsmsmh', 'tahun_lulus', 'f5c_jabatan_kode', 'f5c_jabatan_text', 'f1101_jenis_instansi_kode', 'f1101_jenis_instansi_text', 'f5a1_provinsi', 'raw_data']
                            );
                        }

                        if (!empty($internalResultsUpsertData)) {
                            ClassificationResult::upsert(
                                $internalResultsUpsertData,
                                ['nim', 'source_type'],
                                ['job_text_raw', 'predicted_profile', 'confidence_score', 'classification_method', 'status', 'error_detail']
                            );
                        }

                        if (!empty($internalRawUpsertData)) {
                            $cols = [
                                'nama_lengkap', 'email', 'no_telepon', 'alamat_domisili', 'jurusan',
                                'program_studi', 'tahun_masuk', 'tahun_lulus', 'status_pekerjaan',
                                'klasifikasi_pekerjaan', 'nama_perusahaan', 'jenis_perusahaan', 'jabatan',
                                'lokasi_perusahaan', 'alamat_perusahaan', 'deskripsi_pekerjaan',
                                'tahun_mulai_kerja', 'masa_kerja_bulan', 'jumlah_lamaran_dikirim',
                                'jumlah_respons_lamaran', 'jumlah_undangan_wawancara', 'masa_tunggu_pra_lulus',
                                'masa_tunggu_pasca_lulus', 'total_masa_tunggu', 'instansi_terupdate',
                                'jabatan_terupdate', 'tahun_bekerja', 'status_pekerjaan_terupdate',
                                'linkedin_profile', 'sosmed_ig'
                            ];
                            InternalRawData::upsert($internalRawUpsertData, ['nim'], $cols);
                        }
                    }
                });
                
                $source_type = $status['source_type'] ?? 'unknown';
                if ($source_type === 'kemendik') {
                    $count = collect($status['results'])->where('source_type','kemendik')->count();
                    $msg = "Selesai. {$status['processed_rows']} baris diproses. {$count} data Kemendik masuk.";
                } else {
                    $classified = collect($status['results'])->where('source_type','internal_mif')->where('status','auto_classified')->count();
                    $msg = "Selesai. {$status['processed_rows']} data diproses. {$classified} otomatis (MIF).";
                }
                
                // Hapus data results dari file status agar tidak berat saat diambil UI selanjutnya? Tidak perlu.
                return response()->json(['status' => 'completed', 'message' => $msg]);
            } catch (\Exception $e) {
                Log::error("DB Upsert failed: " . $e->getMessage());
                return response()->json(['status' => 'error', 'message' => "Gagal menyimpan database: " . $e->getMessage()]);
            }
        }
        
        return response()->json(['status' => 'error', 'message' => 'Status tidak dikenali.']);
    }

    private function mapF5c(?string $c): ?string { return ['1'=>'Founder','2'=>'Co-Founder','3'=>'Staff','4'=>'Freelance'][$c] ?? null; }
    private function mapF1101(?string $c): ?string { return ['1'=>'Pemerintah','2'=>'Non-profit','3'=>'Swasta','4'=>'Wiraswasta','6'=>'BUMN','7'=>'Multilateral'][$c] ?? null; }

    public function retrain(Request $request)
    {
        $overrideData = ClassificationResult::where('source_type', 'internal_mif')
            ->where('status', 'manual_override')
            ->whereNotNull('job_text_raw')
            ->whereNotNull('predicted_profile')
            ->get(['nim', 'job_text_raw', 'predicted_profile']);

        $csvPath = null;

        if ($overrideData->count() > 0) {
            $csvLines   = ['nim;job_text_raw;label'];
            foreach ($overrideData as $row) {
                $nim = $row->nim;
                $text  = str_replace([';', "\n", "\r"], [',', ' ', ' '], $row->job_text_raw);
                $label = $row->predicted_profile;
                $csvLines[] = "{$nim};{$text};{$label}";
            }

            $tmpDir  = storage_path('app/temp');
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            
            $csvPath = $tmpDir . '/manual_override_' . Str::uuid() . '.csv';
            file_put_contents($csvPath, implode("\n", $csvLines));

            Log::info("Retrain: Exported {$overrideData->count()} manual_override rows to {$csvPath}");
        } else {
            Log::info('Retrain: Tidak ada data manual_override, training hanya dengan corpus asli.');
        }

        $result = $this->fastApi->triggerRetrain($csvPath);

        if ($csvPath && file_exists($csvPath)) {
            try {
                unlink($csvPath);
            } catch (\Exception $e) {
                Log::warning("Gagal menghapus temp CSV: " . $e->getMessage());
            }
        }

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        return response()->json([
            'success'        => true,
            'message'        => 'Re-training dimulai di background.',
            'override_count' => $overrideData->count(),
        ]);
    }

    public function retrainStatus(Request $request)
    {
        $status = $this->fastApi->getRetrainStatus();
        return response()->json($status);
    }
}
