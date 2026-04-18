<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiReviewService
{
    private const GEMINI_API_URL_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=';
    private const TIMEOUT_SECONDS = 15;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }
    
    public function analyzeReview(?string $reviewText, int $rating = -1): AiReviewResult
    {
        $text = trim((string) $reviewText);

        if ($text === '' && $rating < 1) {
            return new AiReviewResult('NEUTRAL', 'No review text provided for AI analysis.', 'UNKNOWN');
        }

        $apiKey = trim((string) ($_SERVER['GEMINI_API_KEY'] ?? $_ENV['GEMINI_API_KEY'] ?? $_SERVER['APP_GEMINI_API_KEY'] ?? ''));
        if ($apiKey === '') {
            return new AiReviewResult('NEUTRAL', '', 'UNKNOWN');
        }

        try {
            return $this->callGeminiApi($apiKey, $text, $rating);
        } catch (\Throwable $e) {
            return new AiReviewResult('NEUTRAL', '', 'UNKNOWN');
        }
    }

    private function callGeminiApi(string $apiKey, string $reviewText, int $rating): AiReviewResult
    {
        $prompt = 'Analyse this accommodation review and respond ONLY with valid JSON on a single line, '
            . 'no markdown, no explanation. Format: '
            . '{"sentiment":"POSITIVE","language_quality":"GOOD","summary":"One sentence summary."}. '
            . 'sentiment must be exactly POSITIVE, NEUTRAL, or NEGATIVE. '
            . 'language_quality must be exactly GOOD or BAD and should judge the quality/tone/profanity level '
            . 'of the language used (not the place). '
            . 'If review language is insulting, offensive, vulgar, or contains a threat of violence/death, language_quality must be BAD. '
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

        $url = $this->buildGeminiApiUrl($apiKey);
        $response = $this->httpClient->request('POST', $url, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('HTTP %d from gemini-2.5-flash: %s', $statusCode, mb_substr($body, 0, 140)));
        }

        return $this->parseGeminiResponse($body, $rating, $reviewText);
    }

    private function parseGeminiResponse(string $responseBody, int $rating, string $reviewText): AiReviewResult
    {
        $decoded = json_decode($responseBody, true);
        $text = '';

        if (is_array($decoded)) {
            $text = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
        }

        if ($text === '' && preg_match('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $responseBody, $matches)) {
            $text = stripslashes(str_replace('\\n', ' ', $matches[1]));
        }

        if ($text === '') {
            throw new \RuntimeException('Unable to parse Gemini response payload.');
        }

        $normalized = trim($text);
        $normalized = preg_replace('/^```(?:json)?\s*/iu', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*```$/u', '', $normalized) ?? $normalized;

        $analysisPayload = null;
        $decodedText = json_decode($normalized, true);
        if (is_array($decodedText)) {
            $analysisPayload = $decodedText;
        } else {
            $firstBrace = mb_strpos($normalized, '{');
            $lastBrace = mb_strrpos($normalized, '}');
            if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
                $jsonFragment = mb_substr($normalized, $firstBrace, ($lastBrace - $firstBrace) + 1);
                $decodedFragment = json_decode($jsonFragment, true);
                if (is_array($decodedFragment)) {
                    $analysisPayload = $decodedFragment;
                }
            }
        }

        $sentiment = $this->inferSentimentFromText($reviewText, $rating);
        $hasExplicitSentiment = false;
        if (is_array($analysisPayload)) {
            $candidate = strtoupper((string) ($analysisPayload['sentiment'] ?? ''));
            if (in_array($candidate, ['POSITIVE', 'NEUTRAL', 'NEGATIVE'], true)) {
                $sentiment = $candidate;
                $hasExplicitSentiment = true;
            }
        }
        if ($sentiment === 'NEUTRAL' && preg_match('/"sentiment"\s*:\s*"(POSITIVE|NEUTRAL|NEGATIVE)"/iu', $normalized, $sentMatch)) {
            $sentiment = strtoupper($sentMatch[1]);
            $hasExplicitSentiment = true;
        }
        if (!$hasExplicitSentiment && $sentiment === 'NEUTRAL') {
            $sentiment = $this->inferSentimentFromText($reviewText, $rating);
        }

        $languageQuality = 'GOOD';
        if (is_array($analysisPayload)) {
            $rawLanguageQuality = (string) ($analysisPayload['language_quality'] ?? $analysisPayload['languageQuality'] ?? $analysisPayload['language'] ?? '');
            $candidate = strtoupper($rawLanguageQuality);
            if (in_array($candidate, ['GOOD', 'BAD'], true)) {
                $languageQuality = $candidate;
            }
        }
        if (preg_match('/"(?:language_quality|languageQuality|language)"\s*:\s*"(GOOD|BAD)"/iu', $normalized, $langMatch)) {
            $languageQuality = strtoupper($langMatch[1]);
        }
        if ($this->containsProfanity($reviewText) || $this->containsViolentThreat($reviewText)) {
            $languageQuality = 'BAD';
        }

        $summary = '';
        if (is_array($analysisPayload)) {
            $summary = trim((string) ($analysisPayload['summary'] ?? $analysisPayload['resume'] ?? ''));
        }
        if ($summary === '' && preg_match('/"(?:summary|resume)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $normalized, $sumMatch)) {
            $summary = trim(stripslashes($sumMatch[1]));
        }

        if ($summary === '') {
            $summary = $this->buildSummaryFallback($reviewText, $normalized);
        }

        if ($summary === '') {
            $summary = 'Resume non disponible.';
        }

        return new AiReviewResult($sentiment, mb_substr($summary, 0, 255), $languageQuality);
    }

    private function buildGeminiApiUrl(string $apiKey): string
    {
        return self::GEMINI_API_URL_BASE . rawurlencode($apiKey);
    }

    private function containsProfanity(string $reviewText): bool
    {
        $normalizedText = mb_strtolower($reviewText);

        return preg_match('/\b(fuck|shit|bitch|asshole|bastard|damn|motherfucker)\b/u', $normalizedText) === 1;
    }

    private function inferSentimentFromText(string $reviewText, int $rating): string
    {
        $normalizedText = mb_strtolower(trim($reviewText));

        if ($normalizedText !== '') {
            if (preg_match('/\b(love|great|excellent|amazing|awesome|perfect|wonderful|nice|good|beautiful)\b/u', $normalizedText) === 1) {
                return 'POSITIVE';
            }

            if (preg_match('/\b(hate|terrible|awful|horrible|bad|worst|disappointing|poor|trash|fuck|shit)\b/u', $normalizedText) === 1) {
                return 'NEGATIVE';
            }

            if ($this->containsViolentThreat($normalizedText)) {
                return 'NEGATIVE';
            }
        }

        if ($rating >= 4) {
            return 'POSITIVE';
        }

        if ($rating <= 2) {
            return 'NEGATIVE';
        }

        return 'NEUTRAL';
    }

    private function buildSummaryFallback(string $reviewText, string $normalizedGeminiText): string
    {
        $reviewText = trim($reviewText);
        if ($reviewText !== '') {
            $reviewText = preg_replace('/\s+/u', ' ', $reviewText) ?? $reviewText;
            return mb_substr($reviewText, 0, 255);
        }

        $normalizedGeminiText = trim($normalizedGeminiText);
        if ($normalizedGeminiText !== '' && !preg_match('/^\s*[\{\[]/u', $normalizedGeminiText)) {
            return mb_substr(preg_replace('/\s+/u', ' ', $normalizedGeminiText) ?? $normalizedGeminiText, 0, 255);
        }

        return '';
    }

    private function containsViolentThreat(string $reviewText): bool
    {
        $normalizedText = mb_strtolower(trim($reviewText));
        if ($normalizedText === '') {
            return false;
        }

        return preg_match('/\b(i\s+want\s+(?:the\s+)?(?:owner|host|him|her|them|you)\s+to\s+die|(?:owner|host|you|he|she|they)\s+should\s+die|deserve(?:s)?\s+to\s+die|go\s+die|kill\s+(?:the\s+)?(?:owner|host|him|her|them|you)|murder\s+(?:the\s+)?(?:owner|host|him|her|them|you))\b/u', $normalizedText) === 1;
    }
}
