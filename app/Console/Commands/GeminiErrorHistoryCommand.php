<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GeminiErrorHistoryCommand extends Command
{
    protected $signature = 'ai:gemini-errors
        {--hours=24 : Time window in hours}
        {--limit=100 : Max recent matching lines to print}';

    protected $description = 'Ringkas histori error Gemini dari log Laravel (429, 503, quota, cooldown).';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));

        $logFiles = glob(storage_path('logs/*.log')) ?: [];
        if (empty($logFiles)) {
            $this->warn('Tidak ada file log ditemukan di storage/logs.');
            return self::SUCCESS;
        }

        sort($logFiles);

        $cutoffTs = now()->subHours($hours)->getTimestamp();
        $matches = [];
        $counts = [
            'status_503' => 0,
            'status_429' => 0,
            'quota' => 0,
            'high_demand' => 0,
            'cooldown' => 0,
            'other_gemini' => 0,
        ];

        foreach ($logFiles as $file) {
            $fh = @fopen($file, 'rb');
            if ($fh === false) {
                continue;
            }

            while (($line = fgets($fh)) !== false) {
                if (!$this->isGeminiRelated($line)) {
                    continue;
                }

                $lineTs = $this->extractTimestamp($line);
                if ($lineTs !== null && $lineTs < $cutoffTs) {
                    continue;
                }

                $lower = mb_strtolower($line);
                if (str_contains($lower, '503') || str_contains($lower, 'unavailable') || str_contains($lower, 'high demand')) {
                    $counts['status_503']++;
                }
                if (str_contains($lower, '429') || str_contains($lower, 'rate limited')) {
                    $counts['status_429']++;
                }
                if (str_contains($lower, 'resource_exhausted') || str_contains($lower, 'quota')) {
                    $counts['quota']++;
                }
                if (str_contains($lower, 'high demand')) {
                    $counts['high_demand']++;
                }
                if (str_contains($lower, 'cooldown')) {
                    $counts['cooldown']++;
                }
                if (str_contains($lower, 'gemini')) {
                    $counts['other_gemini']++;
                }

                $matches[] = trim($line);
            }

            fclose($fh);
        }

        $this->info('Gemini error history window: last ' . $hours . ' hour(s)');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Gemini-related lines', count($matches)],
                ['Status 503 / unavailable / high demand', $counts['status_503']],
                ['Status 429 / rate limited', $counts['status_429']],
                ['Quota / resource exhausted', $counts['quota']],
                ['Cooldown mentions', $counts['cooldown']],
            ]
        );

        if (empty($matches)) {
            $this->warn('Tidak ada error Gemini pada rentang waktu tersebut.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Recent matching lines:');
        foreach (array_slice($matches, -$limit) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function isGeminiRelated(string $line): bool
    {
        $lower = mb_strtolower($line);

        return str_contains($lower, 'gemini')
            || str_contains($lower, 'resource_exhausted')
            || str_contains($lower, 'high demand')
            || str_contains($lower, 'cooldown aktif')
            || str_contains($lower, 'rate limited')
            || str_contains($lower, 'quota habis');
    }

    private function extractTimestamp(string $line): ?int
    {
        if (!preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            return null;
        }

        $ts = strtotime($m[1]);
        return $ts === false ? null : $ts;
    }
}
