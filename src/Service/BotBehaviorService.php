<?php

namespace App\Service;

class BotBehaviorService
{
    private const DEFAULT_TIMEOUT_SECONDS = 2;

    /**
     * Lightweight fallback vocabulary used when no local bot endpoint is configured.
     *
     * @var list<string>
     */
    private array $botLexicon = [
        'automation',
        'automated',
        'bot',
        'script',
        'scraper',
        'crawler',
        'headless',
        'same headers',
        'identical requests',
        'repetitive',
        'burst',
        'hundreds',
        'per second',
        'per minute',
        'no mouse',
        'proxy',
    ];

    public function __construct(private readonly ?string $botApiUrl = null)
    {
    }

    public function score(string $text): float
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0.0;
        }

        $normalized = mb_strtolower($trimmed);

        $apiScore = $this->scoreWithLocalApi($trimmed);
        if ($apiScore !== null) {
            return $this->clamp($apiScore * 100.0);
        }

        if ($this->isApiConfigured()) {
            return 0.0;
        }

        return $this->scoreWithLexicon($normalized);
    }

    private function isApiConfigured(): bool
    {
        return rtrim(trim((string) $this->botApiUrl), '/') !== '';
    }

    private function scoreWithLocalApi(string $text): ?float
    {
        $url = rtrim(trim((string) $this->botApiUrl), '/');
        if ($url === '') {
            return null;
        }

        if (!str_ends_with($url, '/behavior/classify-human-bot')) {
            $url .= '/behavior/classify-human-bot';
        }

        $payload = json_encode(['behavior' => $text]);
        if ($payload === false) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return null;
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return null;
        }

        $classification = strtolower((string) ($decoded['classification'] ?? ''));
        $confidence = $decoded['confidence'] ?? null;
        if (!is_numeric($confidence)) {
            return null;
        }

        $confidenceValue = $this->clamp((float) $confidence * 100.0);
        if ($classification === 'bot') {
            return $confidenceValue;
        }

        if ($classification === 'human') {
            return $this->clamp(100.0 - $confidenceValue);
        }

        $scores = $decoded['scores'] ?? null;
        if (is_array($scores)) {
            foreach ($scores as $label => $value) {
                if (!is_numeric($value)) {
                    continue;
                }

                $normalizedLabel = mb_strtolower((string) $label);
                if (str_contains($normalizedLabel, 'bot')) {
                    return $this->clamp((float) $value * 100.0);
                }

                if (str_contains($normalizedLabel, 'human')) {
                    return $this->clamp((1.0 - (float) $value) * 100.0);
                }
            }
        }

        return $confidenceValue;
    }

    private function scoreWithLexicon(string $text): float
    {
        $matches = 0;
        foreach ($this->botLexicon as $term) {
            if (str_contains($text, $term)) {
                ++$matches;
            }
        }

        $repetitionScore = 0.0;
        if (preg_match('/\b(\w+)\b(?:\s+\1\b){2,}/iu', $text) === 1) {
            $repetitionScore += 25.0;
        }

        if (preg_match('/\b(?:login|book|search|click|refresh|submit)\b.{0,30}\b(?:login|book|search|click|refresh|submit)\b/iu', $text) === 1) {
            $repetitionScore += 10.0;
        }

        $uppercaseChars = preg_match_all('/[A-Z]/', $text);
        $totalChars = max(1, mb_strlen($text));
        $capsRatio = $uppercaseChars !== false ? $uppercaseChars / $totalChars : 0.0;

        $baseScore = min(85.0, $matches * 14.0 + $repetitionScore);
        if ($capsRatio > 0.30) {
            $baseScore += 8.0;
        }

        return $this->clamp($baseScore);
    }

    private function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 100.0) {
            return 100.0;
        }

        return $value;
    }
}