<?php

namespace App\Service;

class AnomalyDetectionService
{
    public function __construct(private readonly ?string $anomalyApiUrl = null)
    {
    }

    /**
     * @param array<string, float|int> $features
     */
    public function score(array $features): ?float
    {
        $url = rtrim((string) $this->anomalyApiUrl, '/');
        if ($url === '') {
            return null;
        }

        $payload = json_encode(['features' => $features]);
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

        $result = @file_get_contents($url . '/anomaly/score', false, $context);
        if ($result === false) {
            return null;
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded) || !isset($decoded['risk_score']) || !is_numeric($decoded['risk_score'])) {
            return null;
        }

        $score = (float) $decoded['risk_score'];
        if ($score < 0.0) {
            return 0.0;
        }

        if ($score > 100.0) {
            return 100.0;
        }

        return round($score, 2);
    }
}
