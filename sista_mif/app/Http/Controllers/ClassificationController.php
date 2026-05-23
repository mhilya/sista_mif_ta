<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassificationResult;
use App\Models\KemendikRawData;
use App\Services\FastApiWorkerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ClassificationController extends Controller
{
    public function __construct(protected FastApiWorkerService $fastApi) {}

    public function upload(Request $request) {
        $request->validate(['file' => 'required|file|mimes:csv,xlsx,xls|max:20480']);
        
        $result = $this->fastApi->classifyFile($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName());
        if (isset($result['error'])) return back()->with('error', $result['error']);

        DB::transaction(function () use ($result) {
            foreach ($result['results'] as $row) {
                if ($row['source_type'] === 'kemendik') {
                    $raw = $row['raw_data'] ?? [];
                    KemendikRawData::updateOrCreate(
                        ['nimhsmsmh' => $row['nim'], 'source_type' => 'kemendik'],
                        [
                            'nmmhsmsmh' => $row['nama'], 'tahun_lulus' => $row['tahun_lulus'] ?? null,
                            'f5c_jabatan_kode' => $raw['F5c'] ?? null, 'f5c_jabatan_text' => $this->mapF5c($raw['F5c'] ?? null),
                            'f1101_jenis_instansi_kode' => $raw['F1101'] ?? null, 'f1101_jenis_instansi_text' => $this->mapF1101($raw['F1101'] ?? null),
                            'f5a1_provinsi' => $raw['F5a1'] ?? null, 'raw_data' => $raw
                        ]
                    );
                } else {
                    ClassificationResult::updateOrCreate(
                        ['nim' => $row['nim'], 'source_type' => 'internal_mif'],
                        [
                            'nama' => $row['nama'], 'tahun_lulus' => $row['tahun_lulus'] ?? null,
                            'job_text_raw' => $row['job_text_raw'], 'predicted_profile' => $row['predicted_profile'],
                            'confidence_score' => $row['confidence_score'], 'classification_method' => $row['classification_method'],
                            'status' => $row['status'], 'error_detail' => $row['error_detail'] ?? null
                        ]
                    );
                    
                    if (isset($row['raw_data'])) {
                        \App\Models\InternalRawData::updateOrCreate(
                            ['nim' => $row['nim']],
                            ['raw_data' => $row['raw_data']]
                        );
                    }
                }
            }
        });

        if (empty($result['results'])) {
            return back()->with('success', "Selesai. 0 data berhasil diproses.");
        }

        $isKemendik = $result['results'][0]['source_type'] === 'kemendik';
        if ($isKemendik) {
            $count = collect($result['results'])->where('source_type','kemendik')->count();
            return back()->with('success', "Selesai. {$result['processed_rows']} baris diproses. {$count} data Kemendik berhasil masuk.");
        } else {
            $classified = collect($result['results'])->where('source_type','internal_mif')->where('status','auto_classified')->count();
            return back()->with('success', "Selesai. {$result['processed_rows']} data diproses. {$classified} diklasifikasi otomatis (Internal MIF).");
        }
    }

    private function mapF5c(?string $c): ?string { return ['1'=>'Founder','2'=>'Co-Founder','3'=>'Staff','4'=>'Freelance'][$c] ?? null; }
    private function mapF1101(?string $c): ?string { return ['1'=>'Pemerintah','2'=>'Non-profit','3'=>'Swasta','4'=>'Wiraswasta','6'=>'BUMN','7'=>'Multilateral'][$c] ?? null; }

    public function dashboard(Request $request)
    {
        $stats = [
            'total_internal' => \App\Models\ClassificationResult::where('source_type', 'internal_mif')->count(),
            'auto_classified' => \App\Models\ClassificationResult::where('source_type', 'internal_mif')->where('status', 'auto_classified')->count(),
            'needs_review'  => \App\Models\ClassificationResult::where('source_type', 'internal_mif')->where('status', 'needs_review')->count(),
        ];

        $query = \App\Models\ClassificationResult::where('source_type', 'internal_mif');

        // Always put needs_review first unless overridden by a very specific custom sort requirement (we'll just prepend it to orders)
        $query->orderByRaw("FIELD(status, 'needs_review', 'manual_override', 'auto_classified', 'failed')");

        if ($request->has('sort')) {
            $allowedSorts = ['nim', 'nama', 'job_text_raw', 'predicted_profile', 'status'];
            if (in_array($request->sort, $allowedSorts)) {
                $query->orderBy($request->sort, $request->direction === 'desc' ? 'desc' : 'asc');
            }
        } else {
            $query->latest();
        }

        // Charts Data Aggregation
        $allResults = \App\Models\ClassificationResult::where('source_type', 'internal_mif')->get();
        $allRaws = \App\Models\InternalRawData::all();

        $chartProfile = $allResults->whereNotNull('predicted_profile')->groupBy('predicted_profile')->map->count();
        $chartMethod = $allResults->whereNotNull('classification_method')->groupBy('classification_method')->map->count();

        $waktuTunggu = [];
        foreach ($allRaws as $r) {
            $tahun = $r->raw_data['tahun_lulus'] ?? null;
            $tunggu = intval($r->raw_data['total_masa_tunggu'] ?? 0);
            if (!empty($tahun) && $tunggu > 0) {
                $waktuTunggu[$tahun][] = $tunggu;
            }
        }
        $chartWaktuTunggu = collect($waktuTunggu)->map(fn($arr) => round(collect($arr)->average(), 1))->sortKeys();

        $chartFunnel = [
            'Lamaran Dikirim' => round((float)$allRaws->map(fn($r) => intval($r->raw_data['jumlah_lamaran_dikirim'] ?? 0))->average(), 1),
            'Respons' => round((float)$allRaws->map(fn($r) => intval($r->raw_data['jumlah_respons_lamaran'] ?? 0))->average(), 1),
            'Wawancara' => round((float)$allRaws->map(fn($r) => intval($r->raw_data['jumlah_undangan_wawancara'] ?? 0))->average(), 1),
        ];

        $chartLokasi = $allRaws->map(fn($r) => $r->raw_data['lokasi_perusahaan'] ?? null)
                               ->map(fn($i) => trim($i))
                               ->filter(fn($i) => !empty($i))
                               ->groupBy(fn($i) => $i)->map->count();

        $chartTopLokasi = $chartLokasi->sortDesc()->take(5);

        $charts = [
            'profile' => $chartProfile,
            'method' => $chartMethod,
            'waktu_tunggu' => $chartWaktuTunggu,
            'funnel' => $chartFunnel,
            'lokasi' => $chartTopLokasi,
            'map_lokasi' => $chartLokasi,
        ];

        // Load ML metrics untuk panel re-training
        $metricsPath = base_path('../fastapi/ml_assets/metrics_internal_only.json');
        $mlMetrics = null;
        if (file_exists($metricsPath)) {
            $mlMetrics = json_decode(file_get_contents($metricsPath), true);
        }

        $manualOverrideCount = \App\Models\ClassificationResult::where('source_type', 'internal_mif')
            ->where('status', 'manual_override')
            ->whereNotNull('job_text_raw')
            ->count();

        return view('dashboard', [
            'stats'               => $stats,
            'charts'              => $charts,
            'internal_data'       => $query->paginate(10)->appends($request->query()),
            'ml_metrics'          => $mlMetrics,
            'manual_override_count' => $manualOverrideCount,
        ]);
    }


    public function update(Request $request, $id)
    {
        $request->validate([
            'predicted_profile' => 'required|in:Programmer,Data Analyst,Wirausaha Informatika,Non-IT',
        ]);

        $record = \App\Models\ClassificationResult::findOrFail($id);
        $record->update([
            'predicted_profile' => $request->predicted_profile,
            'status' => 'manual_override'
        ]);

        return back()->with('success', 'Data berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $record = \App\Models\ClassificationResult::findOrFail($id);
        $record->delete();

        return back()->with('success', 'Data berhasil dihapus!');
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:classification_results,id',
        ]);

        \App\Models\ClassificationResult::whereIn('id', $request->ids)->delete();

        return back()->with('success', count($request->ids) . ' data berhasil dihapus!');
    }

    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:classification_results,id',
            'predicted_profile' => 'required|in:Programmer,Data Analyst,Wirausaha Informatika,Non-IT',
        ]);

        \App\Models\ClassificationResult::whereIn('id', $request->ids)->update([
            'predicted_profile' => $request->predicted_profile,
            'status' => 'manual_override'
        ]);

        return back()->with('success', count($request->ids) . ' data berhasil diubah profilnya!');
    }

    public function kemendik()
    {
        $stats = [
            'total_kemendik' => \App\Models\KemendikRawData::count(),
        ];

        $keteranganPath = base_path('../data/keterangan_ts_kemendiktisaintek.csv');
        $keterangan = [];
        if (file_exists($keteranganPath)) {
            $lines = file($keteranganPath);
            if (count($lines) > 0) {
                $rawHeaders = str_getcsv(trim($lines[0]), ';');
                $headers = array_map('trim', $rawHeaders);
                
                for ($i = 1; $i < count($lines); $i++) {
                    $row = str_getcsv(trim($lines[$i]), ';');
                    foreach ($headers as $index => $header) {
                        $val = trim($row[$index] ?? '');
                        if ($val !== '') {
                            if (preg_match('/^(\d+)\s*-\s*(.*)/', $val, $matches)) {
                                $keterangan[$header][$matches[1]] = $val;
                            } else {
                                $keterangan[$header][$val] = $val;
                            }
                        }
                    }
                }
            }
        }

        $allRaws = \App\Models\KemendikRawData::all();

        // Helper to count occurrences
        $countBy = function($key) use ($allRaws, $keterangan) {
            return $allRaws->map(function($r) use ($key, $keterangan) {
                $val = trim($r->raw_data[$key] ?? '');
                return $val !== '' ? ($keterangan[$key][$val] ?? $val) : null;
            })->filter()->groupBy(fn($i) => $i)->map->count();
        };

        $chartStatus = $countBy('F8 (Jelaskan status Anda saat ini?)');
        $chartInstansi = $countBy('F1101 (Apa jenis perusahaan/instansi/institusi tempat anda bekerja sekarang?)');
        $chartKesesuaian = $countBy('F14 (Seberapa erat hubungan bidang studi dengan pekerjaan Anda?)');
        $chartLevel = $countBy('F5d (Apa tingkatan tempat kerja Anda?)');

        // Pendapatan F505
        $chartPendapatanRaw = $allRaws->map(fn($r) => floatval($r->raw_data['F505 (Berapa rata-rata pendapatan Anda per bulan?)'] ?? 0))->filter(fn($v) => $v > 0);
        $chartPendapatan = [
            '< 2 Juta' => $chartPendapatanRaw->filter(fn($v) => $v < 2000000)->count(),
            '2 - 4 Juta' => $chartPendapatanRaw->filter(fn($v) => $v >= 2000000 && $v <= 4000000)->count(),
            '4 - 6 Juta' => $chartPendapatanRaw->filter(fn($v) => $v > 4000000 && $v <= 6000000)->count(),
            '> 6 Juta' => $chartPendapatanRaw->filter(fn($v) => $v > 6000000)->count(),
        ];

        // Map Lokasi
        $mapLokasi = $allRaws->map(fn($r) => $r->raw_data['F5a2 (Dimana lokasi kabupaten/kota tempat Anda bekerja?)'] ?? null)->filter()->groupBy(fn($i) => $i)->map->count();

        $coordsPath = base_path('../data/kabupaten_coords.json');
        $kabupatenCoords = file_exists($coordsPath) ? json_decode(file_get_contents($coordsPath), true) : [];

        $charts = [
            'status' => $chartStatus,
            'instansi' => $chartInstansi,
            'kesesuaian' => $chartKesesuaian,
            'pendapatan' => array_filter($chartPendapatan), // remove zero values
            'level' => $chartLevel,
            'map_lokasi' => $mapLokasi,
            'coords' => $kabupatenCoords
        ];

        return view('kemendik', [
            'stats' => $stats,
            'charts' => $charts,
            'kemendik_data' => \App\Models\KemendikRawData::orderBy('imported_at', 'desc')->paginate(10),
            'keterangan' => $keterangan
        ]);
    }

    public function downloadPdf(Request $request)
    {
        $query = \App\Models\ClassificationResult::where('source_type', 'internal_mif');

        $query->orderByRaw("FIELD(status, 'needs_review', 'manual_override', 'auto_classified', 'failed')");

        if ($request->has('sort')) {
            $allowedSorts = ['nim', 'nama', 'job_text_raw', 'predicted_profile', 'status'];
            if (in_array($request->sort, $allowedSorts)) {
                $query->orderBy($request->sort, $request->direction === 'desc' ? 'desc' : 'asc');
            }
        } else {
            $query->latest();
        }

        $internal_data = $query->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.classification_results', compact('internal_data'));
        
        return $pdf->download('Hasil_Klasifikasi_Internal_MIF.pdf');
    }

    /**
     * Ekspor data manual_override ke CSV sementara, lalu trigger re-training via FastAPI.
     * Returns JSON untuk AJAX.
     */
    public function retrain(Request $request)
    {
        // Ambil semua data manual_override yang memiliki job_text_raw
        $overrideData = ClassificationResult::where('source_type', 'internal_mif')
            ->where('status', 'manual_override')
            ->whereNotNull('job_text_raw')
            ->whereNotNull('predicted_profile')
            ->get(['job_text_raw', 'predicted_profile']);

        $csvPath = null;

        if ($overrideData->count() > 0) {
            // Buat CSV sementara di storage/app/temp/
            $csvLines   = ['job_text_raw;label'];
            foreach ($overrideData as $row) {
                $text  = str_replace([';', "\n", "\r"], [',', ' ', ' '], $row->job_text_raw);
                $label = $row->predicted_profile;
                $csvLines[] = "{$text};{$label}";
            }

            $tmpDir  = storage_path('app/temp');
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            $csvPath = $tmpDir . '/manual_override_' . time() . '.csv';
            file_put_contents($csvPath, implode("\n", $csvLines));

            Log::info("Retrain: Exported {$overrideData->count()} manual_override rows to {$csvPath}");
        } else {
            Log::info('Retrain: Tidak ada data manual_override, training hanya dengan corpus asli.');
        }

        // Trigger FastAPI
        $result = $this->fastApi->triggerRetrain($csvPath);

        // Bersihkan CSV temp
        if ($csvPath && file_exists($csvPath)) {
            @unlink($csvPath);
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

    /**
     * Proxy polling status re-training dari FastAPI ke frontend.
     */
    public function retrainStatus(Request $request)
    {
        $status = $this->fastApi->getRetrainStatus();
        return response()->json($status);
    }
}
