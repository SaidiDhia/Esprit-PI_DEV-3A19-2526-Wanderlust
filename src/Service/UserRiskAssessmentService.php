<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class UserRiskAssessmentService
{
    private bool $riskTableEnsured = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly RiskScoringEngine $riskScoringEngine,
        private readonly MessageToxicityService $messageToxicityService,
        private readonly BotBehaviorService $botBehaviorService,
        private readonly AnomalyDetectionService $anomalyDetectionService,
        private readonly RuleEngineApiService $ruleEngineApiService,
        private readonly EmailService $emailService,
    ) {
    }

    public function assessByActorId(?string $userId): void
    {
        $userId = trim((string) $userId);
        if ($userId === '') {
            return;
        }

        $this->ensureRiskTable();
        $previousSignalScores = $this->loadPreviousSignalScores($userId);

        $metrics = $this->collectMetrics($userId);
        $ruleResponse = $this->ruleEngineApiService->score($userId, $metrics);

        $signals = [
            'anomaly_score' => $this->computeAnomalyScore($userId, $metrics),
            'click_speed_score' => $this->resolveRuleOrFallback('click_speed_score', $ruleResponse, fn (): float => $this->computeClickSpeedScore($userId)),
            'login_failure_score' => $this->resolveRuleOrFallback('login_failure_score', $ruleResponse, fn (): float => $this->computeLoginFailureScore($userId)),
            'message_toxicity_score' => $this->computeMessageToxicityScore($userId),
            'bot_behavior_score' => $this->computeBotBehaviorScore($userId, $metrics),
            'cancellation_abuse_score' => $this->resolveRuleOrFallback('cancellation_abuse_score', $ruleResponse, fn (): float => $this->computeCancellationAbuseScore($userId)),
            'marketplace_fraud_score' => $this->resolveRuleOrFallback('marketplace_fraud_score', $ruleResponse, fn (): float => $this->computeMarketplaceFraudScore($userId)),
        ];

        $riskScore = $this->riskScoringEngine->compute($signals);
        $classification = $this->riskScoringEngine->classify($riskScore);

        $details = [
            'signals' => $signals,
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        try {
            $this->connection->executeStatement(
                "INSERT INTO risk_assessment (
                    user_id,
                    risk_score,
                    anomaly_score,
                    click_speed_score,
                    login_failure_score,
                    message_toxicity_score,
                    bot_behavior_score,
                    cancellation_abuse_score,
                    marketplace_fraud_score,
                    risk_band,
                    recommended_action,
                    details_json,
                    updated_at
                ) VALUES (
                    :user_id,
                    :risk_score,
                    :anomaly_score,
                    :click_speed_score,
                    :login_failure_score,
                    :message_toxicity_score,
                    :bot_behavior_score,
                    :cancellation_abuse_score,
                    :marketplace_fraud_score,
                    :risk_band,
                    :recommended_action,
                    :details_json,
                    :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    risk_score = VALUES(risk_score),
                    anomaly_score = VALUES(anomaly_score),
                    click_speed_score = VALUES(click_speed_score),
                    login_failure_score = VALUES(login_failure_score),
                    message_toxicity_score = VALUES(message_toxicity_score),
                    bot_behavior_score = VALUES(bot_behavior_score),
                    cancellation_abuse_score = VALUES(cancellation_abuse_score),
                    marketplace_fraud_score = VALUES(marketplace_fraud_score),
                    risk_band = VALUES(risk_band),
                    recommended_action = VALUES(recommended_action),
                    details_json = VALUES(details_json),
                    updated_at = VALUES(updated_at)",
                [
                    'user_id' => $userId,
                    'risk_score' => $riskScore,
                    'anomaly_score' => $signals['anomaly_score'],
                    'click_speed_score' => $signals['click_speed_score'],
                    'login_failure_score' => $signals['login_failure_score'],
                    'message_toxicity_score' => $signals['message_toxicity_score'],
                    'bot_behavior_score' => $signals['bot_behavior_score'],
                    'cancellation_abuse_score' => $signals['cancellation_abuse_score'],
                    'marketplace_fraud_score' => $signals['marketplace_fraud_score'],
                    'risk_band' => $classification['band'],
                    'recommended_action' => $classification['recommended_action'],
                    'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );

            $this->notifyRiskSignalEscalations(
                $userId,
                $signals,
                $previousSignalScores,
                (string) ($classification['recommended_action'] ?? 'manual_review_or_ban')
            );

            $this->notifyCancellationPatternIfNeeded($userId);
        } catch (\Throwable) {
            // Risk scoring is non-blocking by design.
        }
    }

    /**
     * @return array<string, float>
     */
    private function loadPreviousSignalScores(string $userId): array
    {
        try {
            $row = $this->connection->executeQuery(
                "SELECT anomaly_score, bot_behavior_score
                 FROM risk_assessment
                 WHERE user_id = :user_id
                 LIMIT 1",
                ['user_id' => $userId]
            )->fetchAssociative();

            if (!is_array($row)) {
                return [];
            }

            return [
                'anomaly_score' => (float) ($row['anomaly_score'] ?? 0.0),
                'bot_behavior_score' => (float) ($row['bot_behavior_score'] ?? 0.0),
                'marketplace_fraud_score' => (float) ($row['marketplace_fraud_score'] ?? 0.0),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, float|int> $signals
     * @param array<string, float> $previousSignalScores
     */
    private function notifyRiskSignalEscalations(
        string $userId,
        array $signals,
        array $previousSignalScores,
        string $recommendedAction
    ): void {
        $thresholds = [
            'anomaly_score' => 70.0,
            'bot_behavior_score' => 70.0,
            'cancellation_abuse_score' => 60.0,
            'marketplace_fraud_score' => 65.0,
        ];

        $labels = [
            'anomaly_score' => 'Potential anomaly behavior detected',
            'bot_behavior_score' => 'Potential bot-like behavior detected',
            'cancellation_abuse_score' => 'Potential booking cancellation abuse detected',
            'marketplace_fraud_score' => 'Potential fake or suspicious marketplace behavior detected',
        ];

        $triggered = [];
        foreach ($thresholds as $signalKey => $threshold) {
            $current = (float) ($signals[$signalKey] ?? 0.0);
            $previous = (float) ($previousSignalScores[$signalKey] ?? 0.0);

            if ($current >= $threshold && $previous < $threshold) {
                $triggered[] = [
                    'key' => $signalKey,
                    'label' => (string) ($labels[$signalKey] ?? $signalKey),
                    'score' => $current,
                ];
            }
        }

        if ($triggered === []) {
            return;
        }

        $userRow = $this->loadUserIdentity($userId);
        if ($userRow === null) {
            return;
        }

        $userEmail = trim((string) ($userRow['email'] ?? ''));
        $userName = trim((string) ($userRow['full_name'] ?? ''));
        if ($userName === '') {
            $userName = $userEmail !== '' ? $userEmail : $userId;
        }

        $adminEmails = $this->loadAdminEmails();

        foreach ($triggered as $event) {
            try {
                if ($userEmail !== '') {
                    $this->emailService->sendRiskDetectionUserAlert(
                        $userEmail,
                        $userName,
                        (string) $event['label'],
                        (float) $event['score'],
                        $recommendedAction
                    );
                }

                foreach ($adminEmails as $adminEmail) {
                    $this->emailService->sendRiskDetectionAdminAlert(
                        $adminEmail,
                        $userName,
                        $userEmail,
                        (string) $event['label'],
                        (float) $event['score'],
                        $recommendedAction
                    );
                }
            } catch (\Throwable) {
                // Email notifications are best-effort and must not block risk scoring.
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadUserIdentity(string $userId): ?array
    {
        try {
            $row = $this->connection->executeQuery(
                "SELECT id, full_name, email
                 FROM users
                 WHERE id = :user_id
                 LIMIT 1",
                ['user_id' => $userId]
            )->fetchAssociative();

            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function loadAdminEmails(): array
    {
        try {
            $rows = $this->connection->executeQuery(
                "SELECT email
                 FROM users
                 WHERE role = 'ADMIN'
                   AND is_active = 1
                   AND email IS NOT NULL
                   AND TRIM(email) != ''"
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }

        $emails = [];
        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function notifyCancellationPatternIfNeeded(string $userId): void
    {
        $threshold = 5;
        $windowDays = 30;
        $cooldownHours = 24;

        try {
            $cancelCount = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM booking
                 WHERE user_id = :user_id
                   AND status = 'CANCELLED'
                   AND cancelled_by = 'GUEST'
                   AND cancelled_at IS NOT NULL
                   AND cancelled_at >= DATE_SUB(NOW(), INTERVAL :window_days DAY)",
                [
                    'user_id' => $userId,
                    'window_days' => $windowDays,
                ]
            )->fetchOne();
        } catch (\Throwable) {
            return;
        }

        if ($cancelCount < $threshold) {
            return;
        }

        try {
            $recentAlertCount = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM activity_log
                 WHERE module = 'risk'
                   AND action = 'cancellation_pattern_alert_sent'
                   AND user_id = :user_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :cooldown_hours HOUR)",
                [
                    'user_id' => $userId,
                    'cooldown_hours' => $cooldownHours,
                ]
            )->fetchOne();
        } catch (\Throwable) {
            $recentAlertCount = 1;
        }

        if ($recentAlertCount > 0) {
            return;
        }

        $identity = $this->loadUserIdentity($userId);
        if ($identity === null) {
            return;
        }

        $userEmail = trim((string) ($identity['email'] ?? ''));
        if ($userEmail === '') {
            return;
        }

        $userName = trim((string) ($identity['full_name'] ?? ''));
        if ($userName === '') {
            $userName = $userEmail;
        }

        try {
            $this->emailService->sendCancellationPatternUserAlert($userEmail, $userName, $cancelCount, $windowDays);
        } catch (\Throwable) {
            return;
        }

        try {
            $this->connection->insert('activity_log', [
                'module' => 'risk',
                'action' => 'cancellation_pattern_alert_sent',
                'user_id' => $userId,
                'user_name' => $userName,
                'user_avatar' => null,
                'target_type' => 'user',
                'target_id' => $userId,
                'target_name' => $userEmail,
                'target_image' => null,
                'content' => sprintf('Cancellation pattern alert sent after %d guest cancellations in %d days.', $cancelCount, $windowDays),
                'destination' => '/admin/dashboard?section=risk',
                'metadata_json' => json_encode([
                    'cancel_count' => $cancelCount,
                    'window_days' => $windowDays,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best-effort activity audit only.
        }
    }

    /**
     * @return array<string, float|int|bool|string|null>
     */
    private function collectMetrics(string $userId): array
    {
        try {
            $actionsPerMinute = (int) $this->connection->executeQuery(
                "SELECT COUNT(*) FROM activity_log WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
                ['user_id' => $userId]
            )->fetchOne();

            $actionsFiveMinutes = (int) $this->connection->executeQuery(
                "SELECT COUNT(*) FROM activity_log WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                ['user_id' => $userId]
            )->fetchOne();

            $bookingsPerDay = (int) $this->connection->executeQuery(
                "SELECT COUNT(*) FROM booking WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $sessionDurationMinutes = (int) $this->connection->executeQuery(
                "SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MIN(created_at), MAX(created_at)), 0)
                 FROM activity_log
                 WHERE user_id = :user_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                ['user_id' => $userId]
            )->fetchOne();

            $cancelledBookings = (int) $this->connection->executeQuery(
                "SELECT COUNT(*) FROM booking WHERE user_id = :user_id AND status = 'CANCELLED' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $totalBookings = (int) $this->connection->executeQuery(
                "SELECT COUNT(*) FROM booking WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $failedLogins2m = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM activity_log
                 WHERE user_id = :user_id
                   AND module = 'auth'
                   AND action IN ('login_failed', 'face_id_login_failed')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)",
                ['user_id' => $userId]
            )->fetchOne();

            $marketplaceOrders24h = 0;
            $marketplaceProducts24h = 0;
            $marketplaceOrderCancelRatio30d = 0.0;
            try {
                $marketplaceOrders24h = (int) $this->connection->executeQuery(
                    "SELECT COUNT(*) FROM facture WHERE user_id = :user_id AND date_facture >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                    ['user_id' => $userId]
                )->fetchOne();

                $marketplaceProducts24h = (int) $this->connection->executeQuery(
                    "SELECT COUNT(*) FROM products WHERE userId = :user_id AND created_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                    ['user_id' => $userId]
                )->fetchOne();

                $marketplaceOrders30d = (int) $this->connection->executeQuery(
                    "SELECT COUNT(*) FROM facture WHERE user_id = :user_id AND date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                    ['user_id' => $userId]
                )->fetchOne();

                $marketplaceCancelled30d = (int) $this->connection->executeQuery(
                    "SELECT COUNT(*) FROM facture WHERE user_id = :user_id AND delivery_status = 'cancelled' AND date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                    ['user_id' => $userId]
                )->fetchOne();

                $marketplaceOrderCancelRatio30d = $marketplaceOrders30d > 0 ? round($marketplaceCancelled30d / $marketplaceOrders30d, 4) : 0.0;
            } catch (\Throwable) {
                $marketplaceOrders24h = 0;
                $marketplaceProducts24h = 0;
                $marketplaceOrderCancelRatio30d = 0.0;
            }

            $newDeviceFlag = false;
            $geoJumpFlag = false;
            $recentLogins = $this->connection->executeQuery(
                "SELECT metadata_json, created_at
                 FROM activity_log
                 WHERE user_id = :user_id
                   AND module = 'auth'
                   AND action = 'login_success'
                 ORDER BY created_at DESC
                 LIMIT 2",
                ['user_id' => $userId]
            )->fetchAllAssociative();

            if (count($recentLogins) >= 2) {
                $latest = is_array($recentLogins[0]) ? $recentLogins[0] : [];
                $previous = is_array($recentLogins[1]) ? $recentLogins[1] : [];

                $latestMetadata = $this->decodeMetadata($latest['metadata_json'] ?? null);
                $previousMetadata = $this->decodeMetadata($previous['metadata_json'] ?? null);

                $latestFingerprint = (string) ($latestMetadata['device_fingerprint'] ?? '');
                $previousFingerprint = (string) ($previousMetadata['device_fingerprint'] ?? '');
                $newDeviceFlag = $latestFingerprint !== '' && $previousFingerprint !== '' && $latestFingerprint !== $previousFingerprint;

                $latestCountry = strtolower((string) ($latestMetadata['country'] ?? ''));
                $previousCountry = strtolower((string) ($previousMetadata['country'] ?? ''));
                $geoJumpFlag = $latestCountry !== '' && $previousCountry !== '' && $latestCountry !== $previousCountry;
            }

            return [
                'clicks_per_minute' => $actionsPerMinute,
                'time_between_actions_seconds' => $actionsFiveMinutes > 0 ? round(300 / $actionsFiveMinutes, 2) : 300,
                'booking_frequency' => $bookingsPerDay,
                'cancel_booking_ratio' => $totalBookings > 0 ? round($cancelledBookings / $totalBookings, 4) : 0.0,
                'session_duration_minutes' => $sessionDurationMinutes,
                'failed_logins_2m' => $failedLogins2m,
                'new_device' => $newDeviceFlag,
                'geo_jump' => $geoJumpFlag,
                'marketplace_orders_24h' => $marketplaceOrders24h,
                'marketplace_products_24h' => $marketplaceProducts24h,
                'marketplace_order_cancel_ratio_30d' => $marketplaceOrderCancelRatio30d,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function ensureRiskTable(): void
    {
        if ($this->riskTableEnsured) {
            return;
        }

        $this->riskTableEnsured = true;

        try {
            $this->connection->executeStatement("CREATE TABLE IF NOT EXISTS risk_assessment (
                id BIGINT AUTO_INCREMENT NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                risk_score DECIMAL(5,2) NOT NULL,
                anomaly_score DECIMAL(5,2) NOT NULL,
                click_speed_score DECIMAL(5,2) NOT NULL,
                login_failure_score DECIMAL(5,2) NOT NULL,
                message_toxicity_score DECIMAL(5,2) NOT NULL,
                cancellation_abuse_score DECIMAL(5,2) NOT NULL,
                bot_behavior_score DECIMAL(5,2) NOT NULL,
                marketplace_fraud_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                risk_band VARCHAR(16) NOT NULL,
                recommended_action VARCHAR(32) NOT NULL,
                details_json LONGTEXT DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_risk_user_id (user_id),
                INDEX idx_risk_score (risk_score),
                INDEX idx_risk_updated_at (updated_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB");
        } catch (\Throwable) {
            // Keep service best-effort.
        }

        try {
            $this->connection->executeStatement("ALTER TABLE risk_assessment ADD COLUMN bot_behavior_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER message_toxicity_score");
        } catch (\Throwable) {
            // Column already exists or cannot be altered in current rollout.
        }

        try {
            $this->connection->executeStatement("ALTER TABLE risk_assessment ADD COLUMN marketplace_fraud_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER cancellation_abuse_score");
        } catch (\Throwable) {
            // Column already exists or cannot be altered in current rollout.
        }
    }

    /**
     * @param array<string, float|int|bool|string|null> $metrics
     */
    private function computeAnomalyScore(string $userId, array $metrics): float
    {
        $apiScore = $this->anomalyDetectionService->score([
            'clicks_per_minute' => (float) ($metrics['clicks_per_minute'] ?? 0.0),
            'time_between_actions_seconds' => (float) ($metrics['time_between_actions_seconds'] ?? 300.0),
            'booking_frequency' => (float) ($metrics['booking_frequency'] ?? 0.0),
            'cancel_booking_ratio' => (float) ($metrics['cancel_booking_ratio'] ?? 0.0),
            'session_duration_minutes' => (float) ($metrics['session_duration_minutes'] ?? 0.0),
        ]);

        if ($apiScore !== null) {
            return $apiScore;
        }

        try {
            $actions30m = (int) $this->connection->executeQuery(
                "SELECT COUNT(*) FROM activity_log WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
                ['user_id' => $userId]
            )->fetchOne();

            $actions5m = (int) $this->connection->executeQuery(
                "SELECT COUNT(*) FROM activity_log WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                ['user_id' => $userId]
            )->fetchOne();

            $distinctActions = (int) $this->connection->executeQuery(
                "SELECT COUNT(DISTINCT action) FROM activity_log WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
                ['user_id' => $userId]
            )->fetchOne();

            $score = min(100.0, ($actions30m * 1.2) + max(0, $actions5m - 25) * 2.5);
            if ($actions30m > 40 && $distinctActions <= 2) {
                $score += 20.0;
            }

            return min(100.0, $score);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function computeClickSpeedScore(string $userId): float
    {
        try {
            $bucketPeak = (int) $this->connection->executeQuery(
                "SELECT COALESCE(MAX(bucket_count), 0) FROM (
                    SELECT COUNT(*) AS bucket_count
                    FROM activity_log
                    WHERE user_id = :user_id
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                    GROUP BY FLOOR(UNIX_TIMESTAMP(created_at) / 10)
                ) t",
                ['user_id' => $userId]
            )->fetchOne();

            if ($bucketPeak <= 8) {
                return 0.0;
            }

            return min(100.0, ($bucketPeak - 8) * 8.0);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function computeLoginFailureScore(string $userId): float
    {
        try {
            $failures = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM activity_log
                 WHERE user_id = :user_id
                   AND module = 'auth'
                   AND action IN ('login_failed', 'face_id_login_failed')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                ['user_id' => $userId]
            )->fetchOne();

            return min(100.0, $failures * 20.0);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function computeMessageToxicityScore(string $userId): float
    {
        try {
            $messageRows = $this->connection->executeQuery(
                "SELECT content
                 FROM message
                 WHERE sender_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT 20",
                ['user_id' => $userId]
            )->fetchAllAssociative();

            $reviewRows = $this->connection->executeQuery(
                "SELECT comment AS content
                 FROM review
                 WHERE user_id = :user_id
                   AND comment IS NOT NULL
                   AND TRIM(comment) != ''
                 ORDER BY created_at DESC
                 LIMIT 20",
                ['user_id' => $userId]
            )->fetchAllAssociative();

            $testimonialRows = $this->connection->executeQuery(
                "SELECT content
                 FROM testimonials
                 WHERE user_id = :user_id
                   AND TRIM(content) != ''
                 ORDER BY created_at DESC
                 LIMIT 20",
                ['user_id' => $userId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return 0.0;
        }

        $rows = array_merge($messageRows, $reviewRows, $testimonialRows);
        if ($rows === []) {
            return 0.0;
        }

        $sum = 0.0;
        $count = 0;
        $peak = 0.0;

        foreach ($rows as $row) {
            $text = trim((string) ($row['content'] ?? ''));
            if ($text === '') {
                continue;
            }

            $score = $this->messageToxicityService->score($text);
            $sum += $score;
            if ($score > $peak) {
                $peak = $score;
            }
            ++$count;
        }

        if ($count === 0) {
            return 0.0;
        }

        $average = $sum / $count;

        // Keep persistent toxic behavior important (average) while still reacting to severe threats (peak).
        $finalScore = max($average, $peak * 0.85);
        if ($peak >= 95.0) {
            $finalScore = max($finalScore, 80.0);
        }

        return min(100.0, round($finalScore, 2));
    }

    private function computeBotBehaviorScore(string $userId, array $metrics): float
    {
        try {
            $activityRows = $this->connection->executeQuery(
                "SELECT module, action, content, destination, target_type, target_name
                 FROM activity_log
                 WHERE user_id = :user_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY created_at DESC
                 LIMIT 25",
                ['user_id' => $userId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return 0.0;
        }

        if ($activityRows === []) {
            return 0.0;
        }

        $parts = [];
        foreach ($activityRows as $row) {
            $fragment = trim(implode(' ', array_filter([
                (string) ($row['module'] ?? ''),
                (string) ($row['action'] ?? ''),
                (string) ($row['content'] ?? ''),
                (string) ($row['destination'] ?? ''),
                (string) ($row['target_type'] ?? ''),
                (string) ($row['target_name'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== '')));

            if ($fragment !== '') {
                $parts[] = $fragment;
            }
        }

        if ($parts === []) {
            return 0.0;
        }

        $summary = implode("\n", $parts);
        $score = $this->botBehaviorService->score($summary);

        $clickSpeed = (float) ($metrics['clicks_per_minute'] ?? 0.0);
        if ($clickSpeed > 0.0) {
            $score = max($score, min(100.0, $clickSpeed * 2.5));
        }

        $distinctActions = count(array_unique(array_map(
            static fn (array $row): string => strtolower(trim((string) ($row['module'] ?? '')) . ':' . trim((string) ($row['action'] ?? ''))),
            $activityRows
        )));

        if (count($activityRows) >= 10 && $distinctActions <= 3) {
            $score = max($score, 75.0);
        }

        return min(100.0, round($score, 2));
    }

    private function computeCancellationAbuseScore(string $userId): float
    {
        try {
            $totalBookings = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM booking
                 WHERE user_id = :user_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            if ($totalBookings === 0) {
                return 0.0;
            }

            $cancelledBookings = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM booking
                 WHERE user_id = :user_id
                   AND status = 'CANCELLED'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $quickCancels = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM booking
                 WHERE user_id = :user_id
                   AND status = 'CANCELLED'
                   AND cancelled_at IS NOT NULL
                   AND TIMESTAMPDIFF(MINUTE, created_at, cancelled_at) <= 30
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $ratio = $cancelledBookings / max(1, $totalBookings);
            $score = $ratio * 100.0;

            if ($totalBookings >= 5 && $ratio > 0.70) {
                $score = max($score, 80.0);
            }

            $score += min(30.0, $quickCancels * 10.0);

            $burst = $this->computeCancellationBurstMetrics($userId);
            if ($burst['max_burst_size'] >= 3) {
                $score = max($score, 75.0);
            }
            if ($burst['max_burst_size'] >= 4) {
                $score = max($score, 90.0);
            }

            if ($burst['rapid_pair_count'] >= 2) {
                $score += 15.0;
            }

            if ($burst['closest_interval_minutes'] !== null) {
                if ($burst['closest_interval_minutes'] <= 10) {
                    $score += 15.0;
                } elseif ($burst['closest_interval_minutes'] <= 30) {
                    $score += 8.0;
                }
            }

            return min(100.0, round($score, 2));
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function computeMarketplaceFraudScore(string $userId): float
    {
        try {
            $buyerOrders30d = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM facture
                 WHERE user_id = :user_id
                   AND date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $buyerCancelled30d = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM facture
                 WHERE user_id = :user_id
                   AND delivery_status = 'cancelled'
                   AND date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $buyerRapidBurst10m = (int) $this->connection->executeQuery(
                "SELECT COALESCE(MAX(bucket_count), 0) FROM (
                    SELECT COUNT(*) AS bucket_count
                    FROM facture
                    WHERE user_id = :user_id
                      AND date_facture >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                    GROUP BY FLOOR(UNIX_TIMESTAMP(date_facture) / 600)
                ) t",
                ['user_id' => $userId]
            )->fetchOne();

            $sellerProducts30d = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM products
                 WHERE userId = :user_id
                   AND created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $sellerProducts24h = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM products
                 WHERE userId = :user_id
                   AND created_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $sellerCancelledOrders30d = (int) $this->connection->executeQuery(
                "SELECT COUNT(DISTINCT f.id_facture)
                 FROM facture f
                 JOIN facture_product fp ON fp.facture_id = f.id_facture
                 JOIN products p ON p.id = fp.product_id
                 WHERE p.userId = :user_id
                   AND f.delivery_status = 'cancelled'
                   AND f.date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $sellerOrders30d = (int) $this->connection->executeQuery(
                "SELECT COUNT(DISTINCT f.id_facture)
                 FROM facture f
                 JOIN facture_product fp ON fp.facture_id = f.id_facture
                 JOIN products p ON p.id = fp.product_id
                 WHERE p.userId = :user_id
                   AND f.date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $suspiciousPriceProducts = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM products
                 WHERE userId = :user_id
                   AND (price <= 0.50 OR price >= 10000)
                   AND created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();
        } catch (\Throwable) {
            return 0.0;
        }

        $buyerCancelRatio = $buyerOrders30d > 0 ? ($buyerCancelled30d / $buyerOrders30d) : 0.0;
        $sellerCancelRatio = $sellerOrders30d > 0 ? ($sellerCancelledOrders30d / $sellerOrders30d) : 0.0;

        $score = 0.0;
        $score += min(45.0, $buyerCancelRatio * 60.0);
        $score += min(30.0, $sellerCancelRatio * 50.0);

        if ($buyerRapidBurst10m >= 3) {
            $score += 20.0;
        }
        if ($buyerRapidBurst10m >= 5) {
            $score += 15.0;
        }

        if ($sellerProducts24h >= 6) {
            $score += 20.0;
        } elseif ($sellerProducts24h >= 3) {
            $score += 10.0;
        }

        if ($suspiciousPriceProducts >= 2) {
            $score += 18.0;
        } elseif ($suspiciousPriceProducts === 1) {
            $score += 8.0;
        }

        if ($sellerProducts30d >= 1 && $sellerOrders30d === 0 && $sellerProducts24h >= 4) {
            $score += 12.0;
        }

        return min(100.0, round($score, 2));
    }

    /**
     * @return array{max_burst_size:int,rapid_pair_count:int,closest_interval_minutes:?int}
     */
    private function computeCancellationBurstMetrics(string $userId): array
    {
        try {
            $rows = $this->connection->executeQuery(
                "SELECT cancelled_at
                 FROM booking
                 WHERE user_id = :user_id
                   AND status = 'CANCELLED'
                   AND cancelled_at IS NOT NULL
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 ORDER BY cancelled_at ASC",
                ['user_id' => $userId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return [
                'max_burst_size' => 0,
                'rapid_pair_count' => 0,
                'closest_interval_minutes' => null,
            ];
        }

        $times = [];
        foreach ($rows as $row) {
            $raw = (string) ($row['cancelled_at'] ?? '');
            if ($raw === '') {
                continue;
            }

            $ts = strtotime($raw);
            if ($ts === false) {
                continue;
            }

            $times[] = $ts;
        }

        $count = count($times);
        if ($count < 2) {
            return [
                'max_burst_size' => $count,
                'rapid_pair_count' => 0,
                'closest_interval_minutes' => null,
            ];
        }

        sort($times);

        $closestIntervalMinutes = null;
        $rapidPairCount = 0;
        $maxBurstSize = 1;

        for ($i = 1; $i < $count; ++$i) {
            $deltaMinutes = (int) floor(($times[$i] - $times[$i - 1]) / 60);

            if ($closestIntervalMinutes === null || $deltaMinutes < $closestIntervalMinutes) {
                $closestIntervalMinutes = $deltaMinutes;
            }

            if ($deltaMinutes <= 45) {
                ++$rapidPairCount;
            }
        }

        $windowSeconds = 2 * 60 * 60;
        $left = 0;
        for ($right = 0; $right < $count; ++$right) {
            while ($times[$right] - $times[$left] > $windowSeconds) {
                ++$left;
            }

            $burstSize = $right - $left + 1;
            if ($burstSize > $maxBurstSize) {
                $maxBurstSize = $burstSize;
            }
        }

        return [
            'max_burst_size' => $maxBurstSize,
            'rapid_pair_count' => $rapidPairCount,
            'closest_interval_minutes' => $closestIntervalMinutes,
        ];
    }

    /**
     * @param array<string, mixed>|null $ruleResponse
     */
    private function resolveRuleOrFallback(string $key, ?array $ruleResponse, callable $fallback): float
    {
        if (is_array($ruleResponse) && isset($ruleResponse[$key]) && is_numeric($ruleResponse[$key])) {
            $value = (float) $ruleResponse[$key];

            if ($value < 0.0) {
                return 0.0;
            }

            if ($value > 100.0) {
                return 100.0;
            }

            return round($value, 2);
        }

        $fallbackValue = (float) $fallback();
        if ($fallbackValue < 0.0) {
            return 0.0;
        }
        if ($fallbackValue > 100.0) {
            return 100.0;
        }

        return round($fallbackValue, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
