<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiReviewService
{
    private const GEMINI_URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';
    private const GEMINI_MODELS = ['gemini-2.5-flash', 'gemini-1.5-flash'];
    private const TIMEOUT_SECONDS = 15;
    private const ABUSIVE_WORDS = [
        'fuck', 'fucking', 'shit', 'bitch', 'asshole', 'idiot', 'stupid',
        'merde', 'debil', 'debile', 'con',
    ];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }
    
    public function analyzeReview(?string $reviewText, int $rating = -1): AiReviewResult
    {
        $text = trim((string) $reviewText);

        if ($text === '' && $rating < 1) {
            return new AiReviewResult('NEUTRAL', 'No review text provided for AI analysis.', 'UNKNOWN');
        }

        try {
            return $this->callGeminiApi($text, $rating);
        } catch (\Throwable $e) {
            $fallbackSentiment = $this->inferSentimentFromTextAndRating($text, $rating);
            return new AiReviewResult(
                $fallbackSentiment,
                $this->buildFallbackSummary($fallbackSentiment, $text),
                $this->detectLanguageQuality($text)
            );
        }
    }

    private function callGeminiApi(string $reviewText, int $rating): AiReviewResult
    {
        $apiKey = $this->getGeminiApiKey();

        $prompt = 'Analyse this accommodation review and respond ONLY with valid JSON on a single line, '
            . 'no markdown, no explanation. Format: '
            . '{"sentiment":"POSITIVE","language_quality":"GOOD","summary":"One sentence summary."}. '
            . 'sentiment must be exactly POSITIVE, NEUTRAL, or NEGATIVE. '
            . 'language_quality must be exactly GOOD or BAD and should judge the quality/tone/profanity level '
            . 'of the language used (not the place). '
            . 'If review language is insulting, offensive, or vulgar, language_quality must be BAD. '
            . 'Review text: "' . addslashes($reviewText) . '". '
            . 'User rating: ' . $rating . '/5.';

        $payload = [
            'contents' => [[
                'parts' => [[
                    'text' => $prompt,
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 120,
            ],
        ];

        $lastError = null;
        foreach (self::GEMINI_MODELS as $model) {
            $url = sprintf(self::GEMINI_URL_TEMPLATE, $model, $apiKey);
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);

            if ($statusCode === 200) {
                return $this->parseGeminiResponse($body, $reviewText, $rating);
            }

            $lastError = sprintf('HTTP %d from %s: %s', $statusCode, $model, mb_substr($body, 0, 140));
            if (in_array($statusCode, [400, 401, 403], true)) {
                break;
            }
        }

        throw new \RuntimeException($lastError ?? 'Gemini request failed.');
    }

    private function getGeminiApiKey(): string
    {
        $apiKey = (string) ($_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? '');
        if ($apiKey === '') {
            throw new \RuntimeException('Missing GEMINI_API_KEY environment variable.');
        }

        return $apiKey;
    }

    private function parseGeminiResponse(string $responseBody, string $reviewText, int $rating): AiReviewResult
    {
        $decoded = json_decode($responseBody, true);
        $text = '';

        if (is_array($decoded)) {
            $text = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
        }

        if ($text === '' && preg_match('/"text"\s*:\s*"((?:[^"\\]|\\.)*)"/u', $responseBody, $matches)) {
            $text = stripslashes(str_replace('\\n', ' ', $matches[1]));
        }

        if ($text === '') {
            throw new \RuntimeException('Unable to parse Gemini response payload.');
        }

        $fallbackSentiment = $this->inferSentimentFromTextAndRating($reviewText, $rating);
        $fallbackLanguage = $this->detectLanguageQuality($reviewText);
        $fallbackSummary = $this->buildFallbackSummary($fallbackSentiment, $reviewText);

        $structured = $this->extractStructuredPayload($text);

        if (is_array($structured)) {
            $sentiment = strtoupper((string) ($structured['sentiment'] ?? $fallbackSentiment));
            if (!in_array($sentiment, ['POSITIVE', 'NEUTRAL', 'NEGATIVE'], true)) {
                $sentiment = $fallbackSentiment;
            }

            $languageQuality = strtoupper((string) ($structured['language_quality'] ?? $fallbackLanguage));
            if (!in_array($languageQuality, ['GOOD', 'BAD', 'UNKNOWN'], true)) {
                $languageQuality = $fallbackLanguage;
            }

            $summary = trim((string) ($structured['summary'] ?? ''));
            if ($summary === '') {
                $summary = $fallbackSummary;
            }

            return new AiReviewResult($sentiment, mb_substr($summary, 0, 255), $languageQuality);
        }

        $sentiment = $fallbackSentiment;
        if (preg_match('/"sentiment"\s*:\s*"(POSITIVE|NEUTRAL|NEGATIVE)"/iu', $text, $sentMatch)) {
            $sentiment = strtoupper($sentMatch[1]);
        }

        $languageQuality = $fallbackLanguage;
        if (preg_match('/"language_quality"\s*:\s*"(GOOD|BAD)"/iu', $text, $langMatch)) {
            $languageQuality = strtoupper($langMatch[1]);
        }

        $summary = $fallbackSummary;
        if (preg_match('/"summary"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $text, $sumMatch)) {
            $summary = trim(stripslashes($sumMatch[1]));
        }

        return new AiReviewResult($sentiment, mb_substr($summary, 0, 255), $languageQuality);
    }

    private function extractStructuredPayload(string $text): ?array
    {
        $clean = trim($text);

        // Handles outputs wrapped in markdown fences like ```json ... ```.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/isu', $clean, $fenced)) {
            $clean = trim($fenced[1]);
        }

        $asJson = json_decode($clean, true);
        if (is_array($asJson)) {
            return $asJson;
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $clean, $objectMatch)) {
            $nested = json_decode($objectMatch[0], true);
            if (is_array($nested)) {
                return $nested;
            }
        }

        return null;
    }

    private function detectLanguageQuality(string $reviewText): string
    {
        $text = mb_strtolower(trim($reviewText));
        if ($text === '') {
            return 'UNKNOWN';
        }

        foreach (self::ABUSIVE_WORDS as $word) {
            if (str_contains($text, $word)) {
                return 'BAD';
            }
        }

        if (mb_strlen($text) < 4) {
            return 'BAD';
        }

        return 'GOOD';
    }

    private function inferSentimentFromTextAndRating(string $reviewText, int $rating): string
    {
        $text = mb_strtolower($reviewText);

        if ($rating >= 4) {
            return 'POSITIVE';
        }
        if ($rating > 0 && $rating <= 2) {
            return 'NEGATIVE';
        }

        if (str_contains($text, 'bad') || str_contains($text, 'terrible') || str_contains($text, 'awful')) {
            return 'NEGATIVE';
        }
        if (str_contains($text, 'great') || str_contains($text, 'excellent') || str_contains($text, 'amazing')) {
            return 'POSITIVE';
        }

        return 'NEUTRAL';
    }

    private function buildFallbackSummary(string $sentiment, string $reviewText): string
    {
        $trimmed = trim($reviewText);
        if ($trimmed === '') {
            return 'No written review summary available.';
        }

        if ($sentiment === 'NEGATIVE') {
            return 'The review describes a negative experience.';
        }
        if ($sentiment === 'POSITIVE') {
            return 'The review describes a positive experience.';
        }

        return 'The review describes a mixed or neutral experience.';
    }
}
