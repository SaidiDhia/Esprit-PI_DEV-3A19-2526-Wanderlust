<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService
{
    private const MODEL_ENDPOINT = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(string:GEMINI_API_KEY1)%')]
        private readonly string $apiKey,
        private readonly string $backupApiKey = '',
    ) {
    }

    public function generateResponse(string $prompt): string
    {
        $normalizedPrompt = trim($prompt);
        if ($normalizedPrompt === '') {
            return 'Error: Prompt cannot be empty.';
        }

        $keys = array_values(array_filter([$this->apiKey, $this->backupApiKey], static fn ($key) => is_string($key) && trim($key) !== ''));
        if (count($keys) === 0) {
            return 'Error: Gemini API key is not configured.';
        }

        $requestBody = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $normalizedPrompt],
                    ],
                ],
            ],
        ];

        $lastError = null;
        foreach ($keys as $key) {
            try {
                $response = $this->httpClient->request('POST', self::MODEL_ENDPOINT . trim($key), [
                    'json' => $requestBody,
                    'timeout' => 30,
                ]);

                $data = $response->toArray();
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    return trim((string) $data['candidates'][0]['content']['parts'][0]['text']);
                }

                $lastError = 'No response generated';
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }

        return 'Error: ' . ($lastError ?? 'No response generated');
    }

    public function translate(string $text, string $targetLanguage): string
    {
        $prompt = sprintf(
            "Translate this message to %s. Only return the translation, nothing else:\n\n%s",
            trim($targetLanguage) !== '' ? trim($targetLanguage) : 'English',
            trim($text)
        );

        return $this->generateResponse($prompt);
    }

    /**
     * @param array<int, array<string, mixed>> $recentMessages
     */
    public function smartReply(array $recentMessages, string $currentUserId): string
    {
        $context = "Here's a conversation history. Generate a helpful, natural reply:\n\n";

        foreach ($recentMessages as $msg) {
            $senderId = isset($msg['sender_id']) ? (string) $msg['sender_id'] : '';
            $sender = $senderId === $currentUserId ? 'Me' : ((string) ($msg['sender_name'] ?? 'User'));
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content !== '') {
                $context .= $sender . ': ' . $content . "\n";
            }
        }

        $context .= "\nGenerate a single, natural reply to continue this conversation:";

        return $this->generateResponse($context);
    }

    public function summarize(string $text): string
    {
        $prompt = sprintf(
            "Summarize this message in 2-3 bullet points. Be concise:\n\n%s",
            trim($text)
        );

        return $this->generateResponse($prompt);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function summarizeConversation(array $messages, int $limit = 20): string
    {
        $context = "Summarize this conversation in 3-5 bullet points:\n\n";

        $start = max(0, count($messages) - $limit);
        for ($i = $start; $i < count($messages); $i++) {
            $msg = $messages[$i];
            $sender = (string) ($msg['sender_name'] ?? 'User');
            $content = (string) ($msg['content'] ?? '');
            $type = strtoupper((string) ($msg['message_type'] ?? 'TEXT'));

            if ($type === 'IMAGE') {
                $content = '[Image]';
            } elseif ($type === 'VIDEO') {
                $content = '[Video]';
            } elseif ($type === 'AUDIO') {
                $content = '[Audio]';
            } elseif ($type === 'FILE') {
                $content = '[File]';
            }

            $context .= $sender . ': ' . trim($content) . "\n";
        }

        $context .= "\nProvide a concise summary with 3-5 bullet points:";

        return $this->generateResponse($context);
    }

    /**
     * @return string[]
     */
    public function suggestEmojis(string $text): array
    {
        $prompt = sprintf(
            "Based on this message: '%s', suggest 3 relevant emojis. Return ONLY the emojis separated by spaces, nothing else.",
            trim($text)
        );

        $response = $this->generateResponse($prompt);
        $emojis = array_values(array_filter(explode(' ', trim($response)), static fn ($emoji) => trim($emoji) !== ''));

        return array_slice($emojis, 0, 3);
    }

    public function enhanceActivityDescription(string $title, string $type, string $category): string
    {
        $safeTitle = trim($title) !== '' ? trim($title) : 'Activite';
        $safeType = trim($type) !== '' ? trim($type) : 'Aventure';
        $safeCategory = trim($category) !== '' ? trim($category) : 'Nature';

        $prompt = sprintf(
            "Write a clear tourism activity description in French (80-140 words), persuasive but realistic.\n" .
            "Activity title: %s\n" .
            "Type: %s\n" .
            "Category: %s\n" .
            "Constraints: plain text only, no markdown, no bullet points, no emojis.",
            $safeTitle,
            $safeType,
            $safeCategory
        );

        $response = trim($this->generateResponse($prompt));
        if ($response === '' || str_starts_with($response, 'Error:')) {
            return sprintf(
                "%s est une activite de type %s dans la categorie %s. Cette experience vous permet de profiter d'un cadre authentique, d'un rythme adapte a tous les niveaux et d'un accompagnement securise. Que vous veniez entre amis, en famille ou en petit groupe, vous profiterez d'un moment convivial, de decouvertes locales et de souvenirs memorables.",
                $safeTitle,
                mb_strtolower($safeType),
                mb_strtolower($safeCategory)
            );
        }

        return $response;
    }

    /**
     * @param string[] $activitiesInfo
     */
    public function enhanceEventEquipment(array $activitiesInfo): string
    {
        $cleanActivities = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            $activitiesInfo
        ), static fn (string $item): bool => $item !== ''));

        if ($cleanActivities === []) {
            return "Chaussures confortables, bouteille d'eau, creme solaire, casquette, trousse de premiers secours.";
        }

        $prompt = "Based on the activities below, provide one concise equipment list in French. "
            . "Return plain text only, comma-separated items, no markdown, no numbering.\n\n"
            . implode("\n", $cleanActivities);

        $response = trim($this->generateResponse($prompt));
        if ($response === '' || str_starts_with($response, 'Error:')) {
            return "Chaussures confortables, bouteille d'eau, creme solaire, casquette, lunettes de soleil, veste legere, trousse de premiers secours.";
        }

        return $response;
    }
}
