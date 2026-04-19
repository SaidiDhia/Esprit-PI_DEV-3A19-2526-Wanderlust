<?php

namespace App\Service;

class RuleEngineApiService
{
    public function __construct(private readonly ?string $rulesApiUrl = null)
    {
    }

    /**
     * @param array<string, float|int|bool|string|null> $metrics
     *
     * @return array<string, mixed>|null
     */
    public function score(string $userId, array $metrics): ?array
    {
        $url = rtrim((string) $this->rulesApiUrl, '/');
        if ($url === '' || trim($userId) === '') {
            return null;
        }

        $payload = json_encode([
            'user_id' => $userId,
            'metrics' => $metrics,
        ]);
        if ($payload === false) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 2,
            ],
        ]);

        $response = @file_get_contents($url . '/rules/score', false, $context);
        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function trackAction(?string $userId, string $module, string $action): void
    {
        $url = rtrim((string) $this->rulesApiUrl, '/');
        $cleanUserId = trim((string) $userId);

        if ($url === '' || $cleanUserId === '') {
            return;
        }

        $payload = json_encode([
            'user_id' => $cleanUserId,
            'module' => $module,
            'action' => $action,
        ]);
        if ($payload === false) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 1,
            ],
        ]);

        @file_get_contents($url . '/rules/track-action', false, $context);
    }
}
