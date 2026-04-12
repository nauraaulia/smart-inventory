<?php
 
namespace App\Services\AI;
 
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
                        'body' => $response->json() ?? $response->body(),
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
}
 