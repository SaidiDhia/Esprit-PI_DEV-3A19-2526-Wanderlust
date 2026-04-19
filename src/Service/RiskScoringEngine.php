<?php

namespace App\Service;

class RiskScoringEngine
{
    /**
     * @param array<string, float|int> $signals
     */
    public function compute(array $signals): float
    {
        $anomaly = $this->clamp((float) ($signals['anomaly_score'] ?? 0.0));
        $clickSpeed = $this->clamp((float) ($signals['click_speed_score'] ?? 0.0));
        $loginFailure = $this->clamp((float) ($signals['login_failure_score'] ?? 0.0));
        $messageToxicity = $this->clamp((float) ($signals['message_toxicity_score'] ?? 0.0));
        $cancellationAbuse = $this->clamp((float) ($signals['cancellation_abuse_score'] ?? 0.0));
        $marketplaceFraud = $this->clamp((float) ($signals['marketplace_fraud_score'] ?? 0.0));

        $riskScore =
            (0.22 * $anomaly) +
            (0.16 * $clickSpeed) +
            (0.16 * $loginFailure) +
            (0.14 * $messageToxicity) +
            (0.16 * $cancellationAbuse) +
            (0.16 * $marketplaceFraud);

        return round($this->clamp($riskScore), 2);
    }

    public function classify(float $riskScore): array
    {
        $score = $this->clamp($riskScore);

        if ($score < 30.0) {
            return [
                'band' => 'normal',
                'recommended_action' => 'allow',
            ];
        }

        if ($score < 60.0) {
            return [
                'band' => 'suspicious',
                'recommended_action' => 'captcha_or_warning',
            ];
        }

        if ($score < 80.0) {
            return [
                'band' => 'abusive',
                'recommended_action' => 'temporary_block',
            ];
        }

        return [
            'band' => 'critical',
            'recommended_action' => 'manual_review_or_ban',
        ];
    }

    private function clamp(float $value): float
    {
        if ($value < 0) {
            return 0.0;
        }

        if ($value > 100) {
            return 100.0;
        }

        return $value;
    }
}
