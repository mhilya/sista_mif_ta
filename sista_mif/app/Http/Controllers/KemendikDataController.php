<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\KemendikRawData;
use Illuminate\Support\Facades\DB;

class KemendikDataController extends Controller
{
    public function index()
    {
        $stats = [
            'total_kemendik' => KemendikRawData::count(),
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

        // Optimasi OOM: Gunakan cursor() alih-alih all()
        $statusCounts = [];
        $instansiCounts = [];
        $kesesuaianCounts = [];
        $levelCounts = [];
        $pendapatanRaw = [];
        $mapLokasi = [];

        foreach (KemendikRawData::cursor() as $r) {
            $raw = $r->raw_data;
            if (!$raw) continue;

            $this->incrementCount($statusCounts, $raw, 'F8 (Jelaskan status Anda saat ini?)', $keterangan);
            $this->incrementCount($instansiCounts, $raw, 'F1101 (Apa jenis perusahaan/instansi/institusi tempat anda bekerja sekarang?)', $keterangan);
            $this->incrementCount($kesesuaianCounts, $raw, 'F14 (Seberapa erat hubungan bidang studi dengan pekerjaan Anda?)', $keterangan);
            $this->incrementCount($levelCounts, $raw, 'F5d (Apa tingkatan tempat kerja Anda?)', $keterangan);

            $pendapatanVal = floatval($raw['F505 (Berapa rata-rata pendapatan Anda per bulan?)'] ?? 0);
            if ($pendapatanVal > 0) {
                $pendapatanRaw[] = $pendapatanVal;
            }

            $lokasi = trim($raw['F5a2 (Dimana lokasi kabupaten/kota tempat Anda bekerja?)'] ?? '');
            if ($lokasi) {
                $mapLokasi[$lokasi] = ($mapLokasi[$lokasi] ?? 0) + 1;
            }
        }

        $chartPendapatan = collect($pendapatanRaw)->reduce(function($carry, $v) {
            if ($v < 2000000) $carry['< 2 Juta']++;
            elseif ($v <= 4000000) $carry['2 - 4 Juta']++;
            elseif ($v <= 6000000) $carry['4 - 6 Juta']++;
            else $carry['> 6 Juta']++;
            return $carry;
        }, ['< 2 Juta' => 0, '2 - 4 Juta' => 0, '4 - 6 Juta' => 0, '> 6 Juta' => 0]);
        $chartPendapatan = array_filter($chartPendapatan); 

        $coordsPath = base_path('../data/kabupaten_coords.json');
        $kabupatenCoords = file_exists($coordsPath) ? json_decode(file_get_contents($coordsPath), true) : [];

        $charts = [
            'status' => collect($statusCounts),
            'instansi' => collect($instansiCounts),
            'kesesuaian' => collect($kesesuaianCounts),
            'pendapatan' => $chartPendapatan,
            'level' => collect($levelCounts),
            'map_lokasi' => collect($mapLokasi),
            'coords' => $kabupatenCoords
        ];

        return view('kemendik', [
            'stats' => $stats,
            'charts' => $charts,
            'kemendik_data' => KemendikRawData::orderBy('imported_at', 'desc')->paginate(10),
            'keterangan' => $keterangan
        ]);
    }

    private function incrementCount(&$countsArray, $raw, $key, $keterangan)
    {
        $val = trim($raw[$key] ?? '');
        if ($val !== '') {
            $mapped = $keterangan[$key][$val] ?? $val;
            $countsArray[$mapped] = ($countsArray[$mapped] ?? 0) + 1;
        }
    }
}
