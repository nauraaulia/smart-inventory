<?php
 
namespace App\Services\AI;
 
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
 
class GeminiService
{
    protected string $apiKey;
    protected string $apiUrl;
 
    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->apiUrl = config('services.gemini.url');
    }
 
    public function ask(string $prompt): string
    {
        $cooldownUntil = (int) Cache::get('gemini:cooldown_until_ts', 0);
        $nowTs = now()->timestamp;

        if ($cooldownUntil > $nowTs) {
            return json_encode([
                'error' => 'Gemini cooldown aktif',
                'status' => 429,
                'retry_after_seconds' => $cooldownUntil - $nowTs,
            ]);
        }

        $maxRetries = 4;
        $lastResponse = null;
        $lastError = null;
 
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout(120)
                    ->connectTimeout(15)
                    ->post("{$this->apiUrl}?key={$this->apiKey}", [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 8192,
                    ]
                ]);
 
                $lastResponse = $response;
 
                if (in_array($response->status(), [429, 503], true)) {
                    $responseBody = $response->json();
                    $retrySeconds = $this->extractRetrySeconds(is_array($responseBody) ? $responseBody : null, $response->status());

                    Log::warning('Gemini transient error', [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    Cache::put('gemini:cooldown_until_ts', now()->timestamp + $retrySeconds, now()->addSeconds($retrySeconds));
 
                    $lastError = [
                        'error' => $response->status() === 429
                            ? 'Gemini rate limited'
                            : 'Gemini sementara unavailable',
                        'status' => $response->status(),
                        'body' => $responseBody ?? $response->body(),
                        'retry_after_seconds' => $retrySeconds,
                    ];

                    if ($attempt < $maxRetries) {
                        usleep(400000 * $attempt);
                        continue;
                    }

                    return json_encode($lastError);
                }
 
                if ($response->failed()) {
                    Log::error('Gemini API Error', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
 
                    return json_encode([
                        'error' => 'Gemini API gagal',
                        'status' => $response->status(),
                        'body' => $response->json() ?? $response->body(),
                    ]);
                }
 
                return $response->json('candidates.0.content.parts.0.text')
                    ?? json_encode(['error' => 'Response kosong']);
 
            } catch (\Exception $e) {
                $lastError = ['error' => $e->getMessage()];

                Cache::put('gemini:cooldown_until_ts', now()->timestamp + 15, now()->addSeconds(15));

                Log::error('Gemini Exception', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    usleep(400000 * $attempt);
                    continue;
                }

                return json_encode($lastError);
            }
        }
 
        return json_encode($lastError ?? [
            'error' => 'Gemini gagal setelah retry',
            'status' => $lastResponse?->status(),
            'body' => $lastResponse?->json() ?? $lastResponse?->body(),
        ]);
    }

    private function extractRetrySeconds(?array $body, int $status): int
    {
        $default = $status === 429 ? 45 : 20;

        $retryDelay = data_get($body, 'body.error.details.2.retryDelay')
            ?? data_get($body, 'error.details.2.retryDelay')
            ?? data_get($body, 'retryDelay');

        if (is_string($retryDelay) && preg_match('/(\d+)/', $retryDelay, $match)) {
            return max(5, (int) $match[1]);
        }

        return $default;
    }
}
 