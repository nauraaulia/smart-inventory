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
        $maxRetries = 3;
        $attempt = 0;
        $lastResponse = null;
 
        while ($attempt < $maxRetries) {
            try {
                $response = Http::timeout(120)->post("{$this->apiUrl}?key={$this->apiKey}", [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 8192,
                    ]
                ]);
 
                $lastResponse = $response;
 
                if ($response->status() === 429) {
                    Log::warning('Gemini rate limited', [
                        'attempt' => $attempt + 1,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
 
                    return json_encode([
                        'error' => 'Gemini rate limited',
                        'status' => 429,
                        'body' => $response->json() ?? $response->body(),
                    ]);
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
                Log::error('Gemini Exception', ['message' => $e->getMessage()]);
                return json_encode(['error' => $e->getMessage()]);
            }
        }
 
        return json_encode([
            'error' => 'Gemini 429 setelah retry',
            'status' => $lastResponse?->status(),
            'body' => $lastResponse?->json() ?? $lastResponse?->body(),
        ]);
    }
}
 