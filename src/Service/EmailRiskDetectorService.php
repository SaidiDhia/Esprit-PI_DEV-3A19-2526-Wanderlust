<?php

namespace App\Service;

class EmailRiskDetectorService
{
    /**
     * @param array<int, string> $emailVerificationExemptEmails
     */
    public function __construct(
        private readonly AnomalyDetectionService $anomalyDetectionService,
        private readonly array $emailVerificationExemptEmails = [],
    )
    {
    }

    /**
     * @var array<int, string>
     */
    private array $disposableDomains = [
        'mailinator.com',
        'guerrillamail.com',
        '10minutemail.com',
        'tempmail.com',
        'trashmail.com',
        'yopmail.com',
        'sharklasers.com',
        'throwawaymail.com',
        'dispostable.com',
        'getnada.com',
        'maildrop.cc',
        'temp-mail.org',
    ];

    /**
     * @return array{is_suspicious:bool,score:int,reasons:array<int,string>}
     */
    public function assess(string $email): array
    {
        $normalized = mb_strtolower(trim($email));
        $reasons = [];
        $score = 0;

        if (!str_contains($normalized, '@')) {
            return [
                'is_suspicious' => true,
                'score' => 100,
                'reasons' => ['Invalid email structure'],
            ];
        }

        [$localPart, $domain] = explode('@', $normalized, 2);
        $localPart = trim($localPart);
        $domain = trim($domain);

        if ($localPart === '' || $domain === '') {
            return [
                'is_suspicious' => true,
                'score' => 100,
                'reasons' => ['Invalid email structure'],
            ];
        }

        if (in_array($domain, $this->disposableDomains, true)) {
            $score += 80;
            $reasons[] = 'Disposable email domain detected';
        }

        if ((bool) preg_match('/^[a-z]{1,2}[0-9]{5,}$/i', $localPart)) {
            $score += 20;
            $reasons[] = 'Random local-part pattern';
        }

        if ((bool) preg_match('/[0-9]{6,}/', $localPart)) {
            $score += 15;
            $reasons[] = 'Long numeric sequence in local-part';
        }

        if ((bool) preg_match('/^[a-z0-9._%+-]{1,3}$/i', $localPart)) {
            $score += 15;
            $reasons[] = 'Very short alias local-part';
        }

        if ((bool) preg_match('/\.(xyz|top|click|work)$/i', $domain)) {
            $score += 15;
            $reasons[] = 'High-risk low-trust domain suffix';
        }

        $aiScore = $this->scoreWithAi($localPart, $domain);
        if ($aiScore !== null) {
            $score = max($score, (int) round($aiScore));
            if ($aiScore >= 55.0) {
                $reasons[] = sprintf('AI anomaly score %.2f indicates suspicious email pattern', $aiScore);
            }
        }

        $score = max(0, min(100, $score));

        return [
            'is_suspicious' => $score >= 35,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }

    public function shouldRequireVerification(string $email, bool $isAdmin = false): bool
    {
        if ($isAdmin || $this->isVerificationExempt($email)) {
            return false;
        }

        $assessment = $this->assess($email);

        return (bool) ($assessment['is_suspicious'] ?? false);
    }

    private function isVerificationExempt(string $email): bool
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        foreach ($this->emailVerificationExemptEmails as $exemptEmail) {
            if ($normalized === mb_strtolower(trim((string) $exemptEmail))) {
                return true;
            }
        }

        return false;
    }

    private function scoreWithAi(string $localPart, string $domain): ?float
    {
        $localLength = mb_strlen($localPart);
        $domainLength = mb_strlen($domain);

        $digitsCount = preg_match_all('/\d/', $localPart);
        $digitsRatio = $localLength > 0
            ? (float) ($digitsCount !== false ? $digitsCount : 0) / $localLength
            : 0.0;

        $specialCount = preg_match_all('/[^a-z0-9]/i', $localPart);
        $specialRatio = $localLength > 0
            ? (float) ($specialCount !== false ? $specialCount : 0) / $localLength
            : 0.0;

        $entropyHint = min(1.0, ($digitsRatio * 0.65) + ($specialRatio * 0.35));
        $isLowTrustTld = (bool) preg_match('/\.(xyz|top|click|work)$/i', $domain);
        $disposableHit = in_array($domain, $this->disposableDomains, true) ? 1.0 : 0.0;

        // Reuse anomaly AI as a binary pattern detector over engineered features.
        // The anomaly service currently maps normal/high inversely in this project,
        // so we invert it back to obtain suspicious-high scoring.
        $anomalyScore = $this->anomalyDetectionService->score([
            'clicks_per_minute' => (float) min(300, $localLength * 6),
            'time_between_actions_seconds' => (float) max(1.0, 80.0 - ($digitsRatio * 60.0) - ($specialRatio * 25.0)),
            'booking_frequency' => (float) (($entropyHint * 10.0) + ($disposableHit * 7.0)),
            'cancel_booking_ratio' => (float) min(1.0, ($digitsRatio + $specialRatio + ($isLowTrustTld ? 0.35 : 0.0)) / 2.0),
            'session_duration_minutes' => (float) max(1.0, 35.0 - ($domainLength / 3.0)),
        ]);

        if ($anomalyScore === null) {
            return null;
        }

        return max(0.0, min(100.0, 100.0 - $anomalyScore));
    }
}
