<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class ModerationService
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * FIX: Raised from 0.7 → 0.8.
     * At 0.7 the AI was flagging perfectly normal content ("hello guys", short
     * travel notes, etc.).  0.8 keeps genuine threats blocked while allowing
     * everyday language through.
     */
    private const AUTO_HIDE_THRESHOLD = 0.8;

    private const FREE_MODELS = [
        'openrouter/auto',
        'meta-llama/llama-3.3-70b-instruct:free',
        'mistralai/mistral-small-3.1-24b-instruct:free',
        'google/gemma-3-27b-it:free',
        'qwen/qwq-32b:free',
        'deepseek/deepseek-chat-v3-0324:free',
    ];

    // ✅ STRING keys — PHP casts float keys to int (0), destroying all but the last tier.
    private const KEYWORD_RULES = [
        '0.95' => [
            'i will kill you',
            "i'll kill you",
            'kill yourself',
            'kys',
            'bomb threat',
            'i will murder',
            'death threat',
            'rape you',
            'child porn',
            'molest',
            'i want to kill',
            'shoot you dead',
            'je vais te tuer',
            'je vais vous tuer',
            'je te tuerai',
            'menace de mort',
            'je vais te violer',
            'porno enfant',
            'je vais te buter',
            'te buter',
            'te descendre',
            '/\b(kill|murder|execute|slaughter)\s+(you|your|ya|u|urself|yourself)\b/i',
            '/\b(bomb|terrorist|terrorism)\s+threat\b/i',
            '/\b(rape|molest|child\s+porn)\b/i',
            '/\bdeath\s+threat\b/i',
            '/\bje\s+vais\s+te\s+(tuer|buter|descendre)\b/i',
            '/\bmenace\s+de\s+mort\b/i',
        ],
        '0.85' => [
            'kill you',
            'kill ya',
            'kill u',
            'murder you',
            'stab you',
            'shoot you',
            'blow up',
            'terrorist',
            'terrorism',
            'nazi',
            'genocide',
            'go die',
            'i hope you die',
            'hang yourself',
            'te tuer',
            'te massacrer',
            'terroriste',
            'va te pendre',
            'crève',
            'va crever',
            'nique ta mère',
            'va mourir',
            '/\b(kill|murder|stab|shoot)\s+(you|ya|u)\b/i',
            '/\bblow\s+up\b/i',
            '/\b(terrorist|terrorism|nazi|genocide)\b/i',
            '/\bgo\s+die\b/i',
            '/\bhang\s+yourself\b/i',
            '/\bte\s+tuer\b/i',
            '/\bcrève\b/i',
            '/\bnique\s+ta\s+mère\b/i',
        ],
        '0.75' => [
            'fuck you',
            'fucking idiot',
            'piece of shit',
            'go fuck yourself',
            'motherfucker',
            'die bitch',
            'stupid bitch',
            'va te faire foutre',
            'ferme ta gueule',
            'ta gueule',
            'connard',
            'connasse',
            'enculé',
            'sale pute',
            'grosse pute',
            'va te faire enculer',
            'bâtard',
            'je te déteste',
            'ordure',
            'pourriture',
            'déchet',
            'sous-merde',
            '/\bfuck\s+you\b/i',
            '/\bfucking\s+(idiot|moron)\b/i',
            '/\bpiece\s+of\s+shit\b/i',
            '/\bgo\s+fuck\s+yourself\b/i',
            '/\bmotherfucker\b/i',
            '/\bdie\s+bitch\b/i',
            '/\bva\s+te\s+faire\s+foutre\b/i',
            '/\b(connard|connasse|enculé|salope|pute)\b/i',
        ],
        '0.45' => [
            'idiot',
            'stupid',
            'dumb',
            'moron',
            'jerk',
            'shut up',
            'you suck',
            'imbécile',
            'abruti',
            'crétin',
            'débile',
            'nul',
            'ferme-la',
            'tais-toi',
            "va-t'en",
            'tu es nul',
            '/\b(idiot|stupid|dumb|moron|jerk)\b/i',
            '/\bshut\s+up\b/i',
            '/\b(imbécile|abruti|crétin|débile)\b/i',
            '/\bferme\s+la\b/i',
        ],
    ];

    private LoggerInterface $logger;

    public function __construct(private readonly string $openrouterApiKey, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    public function moderate(string $content): array
    {
        if (trim($content) === '') {
            return ['score' => 0.0, 'reason' => 'Empty content', 'shouldHide' => false];
        }

        // Try AI models first
        if (!empty($this->openrouterApiKey) && str_starts_with($this->openrouterApiKey, 'sk-or-v1-')) {
            foreach (self::FREE_MODELS as $model) {
                $aiResult = $this->callOpenRouter($content, $model);
                if ($aiResult !== null) {
                    $this->logger->info('AI moderation succeeded', ['model' => $model, 'score' => $aiResult['score']]);
                    return $aiResult;
                }
                $this->logger->warning('AI model failed, trying next', ['model' => $model]);
            }
            $this->logger->warning('All AI models failed, falling back to local rules');
        } else {
            $this->logger->error('OpenRouter API key missing or invalid');
        }

        // Local rules fallback
        return $this->localModeration($content);
    }

    private function callOpenRouter(string $content, string $model): ?array
    {
        $systemPrompt =
            'You are a strict content moderation AI for a multilingual travel blog. ' .
            'Analyze the text (may be French or English) and return ONLY a valid JSON object with two fields: ' .
            '"score" (float 0.0 to 1.0, where 0=completely safe, 1=extremely harmful/threatening) ' .
            'and "reason" (max 15 words). ' .
            'IMPORTANT: Normal greetings ("hello", "hi guys", "bonjour", "salut"), travel stories, ' .
            'opinions, and everyday language must score below 0.2. ' .
            'Only explicit threats of violence, hate speech, or illegal content should score above 0.8. ' .
            'No markdown, no code fences, no preamble. ' .
            'Example safe: {"score":0.03,"reason":"Normal travel greeting."} ' .
            'Example unsafe: {"score":0.95,"reason":"Direct death threat."}';

        $body = json_encode([
            'model'       => $model,
            'max_tokens'  => 100,
            'temperature' => 0.1,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $content],
            ],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openrouterApiKey,
                'HTTP-Referer: http://localhost',
                'X-Title: Wanderlust Blog Moderation',
            ],
        ]);

        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        $this->logger->debug('OpenRouter API call', [
            'model'            => $model,
            'status'           => $statusCode,
            'error'            => $curlError,
            'response_preview' => substr($response, 0, 200),
        ]);

        if ($curlError || $statusCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!$text) return null;

        $text = preg_replace('/```json\s*|\s*```/s', '', trim($text));
        if (preg_match('/\{[^{}]*\}/s', $text, $matches)) {
            $text = $matches[0];
        }

        $parsed = json_decode($text, true);
        if (!isset($parsed['score'])) return null;

        $score      = max(0.0, min(1.0, (float) $parsed['score']));
        $reason     = $parsed['reason'] ?? 'No reason';
        $shouldHide = $score >= self::AUTO_HIDE_THRESHOLD;

        return ['score' => $score, 'reason' => $reason, 'shouldHide' => $shouldHide];
    }

    private function localModeration(string $content): array
    {
        $lowerContent = mb_strtolower($content, 'UTF-8');

        // Cast string key back to float for proper score value
        foreach (self::KEYWORD_RULES as $scoreStr => $rules) {
            $score = (float) $scoreStr;
            foreach ($rules as $rule) {
                if (str_starts_with($rule, '/')) {
                    if (preg_match($rule, $content)) {
                        $this->logger->info('Local regex blocked', ['pattern' => $rule, 'score' => $score]);
                        return $this->buildResult($score);
                    }
                } elseif (str_contains($lowerContent, $rule)) {
                    $this->logger->info('Local string blocked', ['keyword' => $rule, 'score' => $score]);
                    return $this->buildResult($score);
                }
            }
        }

        return $this->buildResult(0.05, 'No harmful content detected.');
    }

    private function buildResult(float $score, ?string $reason = null): array
    {
        $reason = $reason ?? $this->localReason($score);
        return [
            'score'      => $score,
            'reason'     => $reason,
            'shouldHide' => $score >= self::AUTO_HIDE_THRESHOLD,
        ];
    }

    private function localReason(float $score): string
    {
        if ($score >= 0.90) return 'Contenu menaçant ou dangereux détecté.';
        if ($score >= 0.80) return 'Langage violent ou menaçant.';
        if ($score >= 0.65) return 'Langage très offensant ou haineux.';
        return 'Langage légèrement inapproprié.';
    }
}