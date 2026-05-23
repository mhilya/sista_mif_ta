<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FastApiWorkerService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.fastapi.worker_url', 'http://127.0.0.1:8001');
    }

    public function classifyFile(string $filePath, string $filename): array
    {
        try {
            $response = Http::timeout(config('services.fastapi.timeout', 120))
                ->attach('file', file_get_contents($filePath), $filename)
                ->post($this->baseUrl . '/api/v1/classify');

            if ($response->failed()) {
                Log::error("FastAPI Error: {$response->status()} | {$response->body()}");
                return ['error' => "Gagal menghubungi FastAPI: {$response->status()}"];
            }
            return $response->json();
        } catch (\Exception $e) {
            Log::error("FastAPI Connection Failed: {$e->getMessage()}");
            return ['error' => "Koneksi ke FastAPI gagal: {$e->getMessage()}"];
        }
    }

    /**
     * Kirim CSV data manual_override ke FastAPI untuk memulai re-training.
     * CSV bersifat opsional — training tetap berjalan dengan corpus asli jika tidak ada.
     */
    public function triggerRetrain(?string $csvPath = null): array
    {
        try {
            $request = Http::timeout(30);

            if ($csvPath && file_exists($csvPath)) {
                $request = $request->attach(
                    'file',
                    file_get_contents($csvPath),
                    'manual_override.csv'
                );
            }

            $response = $request->post($this->baseUrl . '/api/v1/retrain');

            if ($response->status() === 409) {
                return ['error' => 'Re-training sedang berjalan. Tunggu hingga selesai.'];
            }

            if ($response->failed()) {
                Log::error("FastAPI Retrain Error: {$response->status()} | {$response->body()}");
                return ['error' => "Gagal memulai re-training: HTTP {$response->status()}"];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("FastAPI Retrain Failed: {$e->getMessage()}");
            return ['error' => "Koneksi ke FastAPI gagal: {$e->getMessage()}"];
        }
    }

    /**
     * Polling status re-training dari FastAPI.
     */
    public function getRetrainStatus(): array
    {
        try {
            $response = Http::timeout(10)->get($this->baseUrl . '/api/v1/retrain/status');

            if ($response->failed()) {
                return ['stage' => 'unknown', 'message' => 'Tidak dapat membaca status dari FastAPI.'];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::warning("FastAPI Status Check Failed: {$e->getMessage()}");
            return ['stage' => 'unknown', 'message' => "Koneksi gagal: {$e->getMessage()}"];
        }
    }

    /**
     * Minta FastAPI reload model pkl aktif secara manual.
     */
    public function reloadModel(): array
    {
        try {
            $response = Http::timeout(15)->post($this->baseUrl . '/api/v1/retrain/reload');

            if ($response->failed()) {
                return ['error' => "Gagal reload model: HTTP {$response->status()}"];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("FastAPI Reload Failed: {$e->getMessage()}");
            return ['error' => "Koneksi ke FastAPI gagal: {$e->getMessage()}"];
        }
    }
}