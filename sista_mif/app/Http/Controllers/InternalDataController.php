<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassificationResult;
use App\Models\InternalRawData;
use Illuminate\Support\Facades\DB;

class InternalDataController extends Controller
{
    public function index(Request $request)
    {
        $stats = [
            'total_internal' => ClassificationResult::where('source_type', 'internal_mif')->count(),
            'auto_classified' => ClassificationResult::where('source_type', 'internal_mif')->where('status', 'auto_classified')->count(),
            'needs_review'  => ClassificationResult::where('source_type', 'internal_mif')->where('status', 'needs_review')->count(),
        ];

        $query = ClassificationResult::where('source_type', 'internal_mif');

        $query->orderByRaw("FIELD(status, 'needs_review', 'manual_override', 'auto_classified', 'failed')");

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('job_text_raw', 'LIKE', '%' . $request->search . '%')
                  ->orWhere('nim', 'LIKE', '%' . $request->search . '%')
                  ->orWhereHas('internalRaw', function($q) use ($request) {
                      $q->where('nama_lengkap', 'LIKE', '%' . $request->search . '%');
                  });
            });
        }

        if ($request->has('sort')) {
            $allowedSorts = ['nim', 'job_text_raw', 'predicted_profile', 'status'];
            if ($request->sort === 'nama') {
                $query->join('internal_raw_data', 'classification_results.nim', '=', 'internal_raw_data.nim')
                      ->orderBy('internal_raw_data.nama_lengkap', $request->direction === 'desc' ? 'desc' : 'asc')
                      ->select('classification_results.*');
            } elseif (in_array($request->sort, $allowedSorts)) {
                $query->orderBy('classification_results.'.$request->sort, $request->direction === 'desc' ? 'desc' : 'asc');
            }
        } else {
            $query->latest();
        }

        // --- Optimasi OOM: Hitung menggunakan database level, bukan php level mapping ---
        $chartProfile = ClassificationResult::where('source_type', 'internal_mif')
            ->whereNotNull('predicted_profile')
            ->select('predicted_profile', DB::raw('count(*) as total'))
            ->groupBy('predicted_profile')
            ->pluck('total', 'predicted_profile');

        $chartMethod = ClassificationResult::where('source_type', 'internal_mif')
            ->whereNotNull('classification_method')
            ->select('classification_method', DB::raw('count(*) as total'))
            ->groupBy('classification_method')
            ->pluck('total', 'classification_method');

        // Memakai cursor untuk menghemat memori
        $waktuTunggu = [];
        $lamaranDikirim = [];
        $respons = [];
        $wawancara = [];
        $lokasiCounts = [];

        foreach (InternalRawData::cursor() as $r) {
            $tahun = $r->tahun_lulus;
            $tunggu = intval($r->total_masa_tunggu ?? 0);
            if (!empty($tahun) && $tunggu > 0) {
                $waktuTunggu[$tahun][] = $tunggu;
            }

            $lamaranDikirim[] = intval($r->jumlah_lamaran_dikirim ?? 0);
            $respons[] = intval($r->jumlah_respons_lamaran ?? 0);
            $wawancara[] = intval($r->jumlah_undangan_wawancara ?? 0);

            $lokasi = trim($r->lokasi_perusahaan ?? '');
            if (!empty($lokasi)) {
                $lokasiCounts[$lokasi] = ($lokasiCounts[$lokasi] ?? 0) + 1;
            }
        }

        $chartWaktuTunggu = collect($waktuTunggu)->map(fn($arr) => round(collect($arr)->average(), 1))->sortKeys();

        $chartFunnel = [
            'Lamaran Dikirim' => count($lamaranDikirim) > 0 ? round(collect($lamaranDikirim)->average(), 1) : 0,
            'Respons' => count($respons) > 0 ? round(collect($respons)->average(), 1) : 0,
            'Wawancara' => count($wawancara) > 0 ? round(collect($wawancara)->average(), 1) : 0,
        ];

        $chartLokasi = collect($lokasiCounts);
        $chartTopLokasi = $chartLokasi->sortDesc()->take(5);

        $charts = [
            'profile' => $chartProfile,
            'method' => $chartMethod,
            'waktu_tunggu' => $chartWaktuTunggu,
            'funnel' => $chartFunnel,
            'lokasi' => $chartTopLokasi,
            'map_lokasi' => $chartLokasi,
        ];

        $metricsPath = base_path('../fastapi/ml_assets/metrics_internal_only.json');
        $mlMetrics = null;
        if (file_exists($metricsPath)) {
            $mlMetrics = json_decode(file_get_contents($metricsPath), true);
        }

        $manualOverrideCount = ClassificationResult::where('source_type', 'internal_mif')
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
            'predicted_profile' => 'required|in:Programmer,Data Analyst,Wirausaha Informatika,Non-IT,Tidak Diketahui',
        ]);

        $record = ClassificationResult::findOrFail($id);
        $record->update([
            'predicted_profile' => $request->predicted_profile,
            'status' => 'manual_override'
        ]);

        return redirect(url()->previous() . '#tabel-data')->with('success', 'Data berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $record = ClassificationResult::findOrFail($id);
        $record->delete();

        return redirect(url()->previous() . '#tabel-data')->with('success', 'Data berhasil dihapus!');
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:classification_results,id',
        ]);

        ClassificationResult::whereIn('id', $request->ids)->delete();

        return redirect(url()->previous() . '#tabel-data')->with('success', count($request->ids) . ' data berhasil dihapus!');
    }

    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:classification_results,id',
            'predicted_profile' => 'required|in:Programmer,Data Analyst,Wirausaha Informatika,Non-IT,Tidak Diketahui',
        ]);

        ClassificationResult::whereIn('id', $request->ids)->update([
            'predicted_profile' => $request->predicted_profile,
            'status' => 'manual_override'
        ]);

        return redirect(url()->previous() . '#tabel-data')->with('success', count($request->ids) . ' data berhasil diubah profilnya!');
    }

    public function exportPdf(Request $request)
    {
        $query = ClassificationResult::where('source_type', 'internal_mif');
        $query->orderByRaw("FIELD(status, 'needs_review', 'manual_override', 'auto_classified', 'failed')");

        if ($request->has('sort')) {
            $allowedSorts = ['nim', 'job_text_raw', 'predicted_profile', 'status'];
            if ($request->sort === 'nama') {
                $query->join('internal_raw_data', 'classification_results.nim', '=', 'internal_raw_data.nim')
                      ->orderBy('internal_raw_data.nama_lengkap', $request->direction === 'desc' ? 'desc' : 'asc')
                      ->select('classification_results.*');
            } elseif (in_array($request->sort, $allowedSorts)) {
                $query->orderBy('classification_results.'.$request->sort, $request->direction === 'desc' ? 'desc' : 'asc');
            }
        } else {
            $query->latest();
        }

        $internal_data = $query->get();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.classification_results', compact('internal_data'));
        return $pdf->download('Hasil_Klasifikasi_Internal_MIF.pdf');
    }
}
