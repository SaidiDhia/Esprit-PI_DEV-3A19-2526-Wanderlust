<?php

namespace App\Service;

class MessageToxicityService
{
    private const DEFAULT_TIMEOUT_SECONDS = 2;

    /**
     * Lightweight fallback vocabulary used when no local NLP endpoint is configured.
     *
     * @var list<string>
     */
    private array $toxicLexicon = [
        'idiot',
        'stupid',
        'trash',
        'hate',
        'loser',
        'dumb',
        'moron',
        'sucks',
        'fuck',
        'shit',
        'bitch',
        'bastard',
        'die',
        'death',
        'kill',
        'suicide',
        'wish you were dead',
        'go die',
        'disappointing',
        'unpleasant',
        'hurtful',
        'negative',
        'unwanted',
        'distressing',
    ];

    public function __construct(private readonly ?string $toxicityApiUrl = null)
    {
    }

    public function score(string $text): float
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0.0;
        }

        $normalized = mb_strtolower($trimmed);

        // Always try the local toxicity model first.
        $apiScore = $this->scoreWithLocalApi($trimmed);
        if ($apiScore !== null) {
            return $this->clamp($apiScore * 100.0);
        }

        // If an AI endpoint is configured but currently unreachable, do not silently
        // downgrade to lexicon scoring. Keep score neutral so moderation stays AI-driven.
        if ($this->isApiConfigured()) {
            return 0.0;
        }

        return $this->scoreWithLexicon($normalized);
    }

    private function isApiConfigured(): bool
    {
        return rtrim(trim((string) $this->toxicityApiUrl), '/') !== '';
    }

    private function scoreWithLocalApi(string $text): ?float
    {
        $url = rtrim(trim((string) $this->toxicityApiUrl), '/');
        if ($url === '') {
            return null;
        }

        if (!str_ends_with($url, '/toxicity/score')) {
            $url .= '/toxicity/score';
        }

        $payload = json_encode(['text' => $text]);
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

        $toxicity = $decoded['toxicity'] ?? $decoded['score'] ?? null;
        if (!is_numeric($toxicity)) {
            return null;
        }

        return $this->clamp((float) $toxicity);
    }

    private function scoreWithLexicon(string $text): float
    {
        $matches = 0;
        foreach ($this->toxicLexicon as $term) {
            if (str_contains($text, $term)) {
                ++$matches;
            }
        }

        $uppercaseChars = preg_match_all('/[A-Z]/', $text);
        $totalChars = max(1, mb_strlen($text));
        $capsRatio = $uppercaseChars !== false ? $uppercaseChars / $totalChars : 0.0;

        $baseScore = min(90.0, $matches * 22.0);
        if ($capsRatio > 0.30) {
            $baseScore += 10.0;
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
