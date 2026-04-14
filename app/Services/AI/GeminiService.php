<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
 
class GeminiService
{
    protected string $apiKey;
    protected string $apiUrl;
    protected int $maxAttempts;
 
    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->apiUrl = config('services.gemini.url');
        // request_retries means additional retries after the first request.
        $configuredRetries = max(0, (int) config('services.gemini.request_retries', 0));
        $this->maxAttempts = 1 + $configuredRetries;
    }
 
    public function ask(string $prompt, ?string $systemInstruction = null): string
    {
        $cooldownUntilKey = $this->cooldownKey('until_ts');
        $cooldownPayloadKey = $this->cooldownKey('payload');

        $cooldownUntil = (int) Cache::get($cooldownUntilKey, 0);
        $nowTs = now()->timestamp;

        if ($cooldownUntil > $nowTs) {
            $payload = Cache::get($cooldownPayloadKey);
            if (!is_array($payload)) {
                $payload = [
                    'error' => 'Gemini cooldown aktif',
                    'status' => 429,
                ];
            }

            $payload['retry_after_seconds'] = $cooldownUntil - $nowTs;
            return json_encode($payload);
        }

        $maxAttempts = $this->maxAttempts;
        $lastResponse = null;
        $lastError = null;
 
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $payload = [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => max(512, (int) config('services.gemini.max_output_tokens', 1024)),
                        'responseMimeType' => 'application/json',
                    ]
                ];

                if (is_string($systemInstruction) && trim($systemInstruction) !== '') {
                    $payload['systemInstruction'] = [
                        'parts' => [
                            ['text' => trim($systemInstruction)],
                        ],
                    ];
                }

                $response = Http::timeout(max(30, (int) config('services.gemini.timeout_seconds', 90)))
                    ->connectTimeout(max(5, (int) config('services.gemini.connect_timeout_seconds', 15)))
                    ->post("{$this->apiUrl}?key={$this->apiKey}", $payload);
 
                $lastResponse = $response;
 
                if (in_array($response->status(), [429, 503], true)) {
                    $responseBody = $response->json();
                    $retrySeconds = $this->extractRetrySeconds(is_array($responseBody) ? $responseBody : null, $response->status());

                    Log::warning('Gemini transient error', [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    $lastError = [
                        'error' => $response->status() === 429
                            ? 'Gemini rate limited'
                            : 'Gemini sementara unavailable',
                        'status' => $response->status(),
                        'body' => $responseBody ?? $response->body(),
                        'retry_after_seconds' => $retrySeconds,
                    ];

                    Cache::put($cooldownUntilKey, now()->timestamp + $retrySeconds, now()->addSeconds($retrySeconds));
                    Cache::put($cooldownPayloadKey, $lastError, now()->addSeconds($retrySeconds));

                    if ($attempt < $maxAttempts) {
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

                Cache::forget($cooldownUntilKey);
                Cache::forget($cooldownPayloadKey);
 
                return $response->json('candidates.0.content.parts.0.text')
                    ?? json_encode(['error' => 'Response kosong']);
 
            } catch (\Exception $e) {
                $lastError = ['error' => $e->getMessage()];

                Cache::put($cooldownUntilKey, now()->timestamp + 15, now()->addSeconds(15));
                Cache::put($cooldownPayloadKey, [
                    'error' => 'Gemini sementara unavailable',
                    'status' => 503,
                    'body' => ['exception' => $e->getMessage()],
                    'retry_after_seconds' => 15,
                ], now()->addSeconds(15));

                Log::error('Gemini Exception', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
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

    private function cooldownKey(string $suffix): string
    {
        $hash = substr(sha1((string) $this->apiKey), 0, 12);

        return 'gemini:' . $hash . ':' . $suffix;
    }
}
 