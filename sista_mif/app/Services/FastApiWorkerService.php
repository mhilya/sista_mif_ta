<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class FastApiWorkerService
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->baseUrl = config('services.fastapi.worker_url', 'http://127.0.0.1:8001');
        $this->secretKey = config('services.fastapi.secret_key', '');
    }

    /**
     * Helper to get HTTP client with common config
     */
    private function getClient(int $timeout = 15)
    {
        $client = Http::timeout($timeout);
        if (!empty($this->secretKey)) {
            $client = $client->withToken($this->secretKey);
        }
        return $client;
    }

    public function classifyFile(string $filePath, string $filename): array
    {
        $fileStream = null;
        try {
            // Fix OOM: Gunakan fopen untuk stream file, bukan file_get_contents
            $fileStream = fopen($filePath, 'r');
            if (!$fileStream) {
                throw new \Exception("Tidak dapat membaca file: {$filePath}");
            }

            $response = $this->getClient(15)
                ->attach('file', $fileStream, $filename)
                ->post($this->baseUrl . '/api/v1/classify');

            if ($response->failed()) {
                Log::error("FastAPI Error: {$response->status()} | {$response->body()}");
                return ['error' => "Gagal menghubungi FastAPI: {$response->status()}"];
            }
            return $response->json();
        } catch (ConnectionException $e) {
            Log::error("FastAPI Connection Timeout/Refused: {$e->getMessage()}");
            return ['error' => 'Server AI sedang tidak dapat dijangkau (Timeout/Refused).'];
        } catch (\Exception $e) {
            Log::error("FastAPI Internal System Error: {$e->getMessage()}");
            return ['error' => "Kesalahan sistem internal: {$e->getMessage()}"];
        } finally {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    public function getClassifyStatus(string $jobId): array
    {
        try {
            $response = $this->getClient(10)->get($this->baseUrl . "/api/v1/classify/status/{$jobId}");
            if ($response->failed()) {
                if ($response->status() === 404) {
                    return ['status' => 'error', 'message' => 'Job ID tidak ditemukan di server.'];
                }
                return ['status' => 'error', 'message' => "Gagal menghubungi server: HTTP {$response->status()}"];
            }
            return $response->json();
        } catch (ConnectionException $e) {
            return ['status' => 'error', 'message' => 'Koneksi ke server AI terputus.'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => "Kesalahan sistem: {$e->getMessage()}"];
        }
    }

    /**
     * Kirim CSV data manual_override ke FastAPI untuk memulai re-training.
     * CSV bersifat opsional — training tetap berjalan dengan corpus asli jika tidak ada.
     */
    public function triggerRetrain(?string $csvPath = null): array
    {
        $fileStream = null;
        try {
            $request = $this->getClient(30);

            if ($csvPath && file_exists($csvPath)) {
                $fileStream = fopen($csvPath, 'r');
                if (!$fileStream) {
                    throw new \Exception("Tidak dapat membaca temp CSV: {$csvPath}");
                }
                $request = $request->attach('file', $fileStream, 'manual_override.csv');
            }

            $response = $request->post($this->baseUrl . '/api/v1/retrain');

            if ($response->status() === 409) {
                return ['error' => 'Re-training sedang berjalan. Tunggu hingga selesai.'];
            }

            if ($response->status() === 429) {
                return ['error' => 'Sistem sibuk. Permintaan retraining lain sedang diproses.'];
            }

            if ($response->failed()) {
                Log::error("FastAPI Retrain Error: {$response->status()} | {$response->body()}");
                return ['error' => "Gagal memulai re-training: HTTP {$response->status()}"];
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error("FastAPI Retrain Connection Timeout/Refused: {$e->getMessage()}");
            return ['error' => 'Server AI sedang tidak dapat dijangkau (Timeout/Refused).'];
        } catch (\Exception $e) {
            Log::error("FastAPI Retrain Internal System Error: {$e->getMessage()}");
            return ['error' => "Kesalahan sistem internal: {$e->getMessage()}"];
        } finally {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    /**
     * Polling status re-training dari FastAPI.
     */
    public function getRetrainStatus(): array
    {
        try {
            $response = $this->getClient(10)->get($this->baseUrl . '/api/v1/retrain/status');

            if ($response->failed()) {
                if ($response->status() === 429) {
                    return $response->json(); // Return stage locked dari FastAPI
                }
                return ['stage' => 'unknown', 'message' => 'Tidak dapat membaca status dari FastAPI.'];
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::warning("FastAPI Status Check Connection Refused/Timeout: {$e->getMessage()}");
            return ['stage' => 'unknown', 'message' => 'Server AI tidak merespons (Timeout).'];
        } catch (\Exception $e) {
            Log::warning("FastAPI Status Check System Error: {$e->getMessage()}");
            return ['stage' => 'unknown', 'message' => "Kesalahan sistem internal: {$e->getMessage()}"];
        }
    }

    /**
     * Minta FastAPI reload model pkl aktif secara manual.
     */
    public function reloadModel(): array
    {
        try {
            $response = $this->getClient(15)->post($this->baseUrl . '/api/v1/retrain/reload');

            if ($response->failed()) {
                return ['error' => "Gagal reload model: HTTP {$response->status()}"];
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error("FastAPI Reload Connection Timeout/Refused: {$e->getMessage()}");
            return ['error' => 'Server AI sedang tidak dapat dijangkau (Timeout/Refused).'];
        } catch (\Exception $e) {
            Log::error("FastAPI Reload System Error: {$e->getMessage()}");
            return ['error' => "Kesalahan sistem internal: {$e->getMessage()}"];
        }
    }
}