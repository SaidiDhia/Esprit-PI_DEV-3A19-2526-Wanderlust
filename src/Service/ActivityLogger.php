<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

class ActivityLogger
{
    private bool $activityTableEnsured = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly UserRiskAssessmentService $userRiskAssessmentService,
        private readonly RuleEngineApiService $ruleEngineApiService,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function logAction(?User $user, string $module, string $action, array $data = []): void
    {
        $payload = $this->buildPayload($user, $module, $action, $data);

        try {
            $this->connection->insert('activity_log', $payload);
            $this->ruleEngineApiService->trackAction($this->toNullableString($payload['user_id'] ?? null), $module, $action);
            $this->refreshRiskScoreIfPossible($payload['user_id'] ?? null);
        } catch (\Throwable $exception) {
            if ($this->shouldEnsureTable($exception)) {
                $this->ensureActivityLogTableExists();
                try {
                    $this->connection->insert('activity_log', $payload);
                    $this->ruleEngineApiService->trackAction($this->toNullableString($payload['user_id'] ?? null), $module, $action);
                    $this->refreshRiskScoreIfPossible($payload['user_id'] ?? null);
                    return;
                } catch (\Throwable) {
                    // Continue to best-effort fallback.
                }
            }

            $this->writeFallbackLog($module, $action, $data);
            // Activity logging is best-effort and must never block user actions.
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function buildPayload(?User $user, string $module, string $action, array $data): array
    {
        $actorId = $this->toNullableString($data['actorId'] ?? $user?->getId() ?? null);
        $actorName = $this->toNullableString($data['actorName'] ?? $user?->getFullName() ?? null);
        $actorAvatar = $this->toNullableString($data['actorAvatar'] ?? $user?->getProfilePicture() ?? null);

        $metadata = $data['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'module' => $this->truncate($module, 50),
            'action' => $this->truncate($action, 120),
            'user_id' => $this->truncate($actorId, 36),
            'user_name' => $this->truncate($actorName, 255),
            'user_avatar' => $this->truncate($actorAvatar, 255),
            'target_type' => $this->truncate($this->toNullableString($data['targetType'] ?? null), 80),
            'target_id' => $this->truncate($this->toNullableString($data['targetId'] ?? null), 120),
            'target_name' => $this->truncate($this->toNullableString($data['targetName'] ?? null), 255),
            'target_image' => $this->truncate($this->toNullableString($data['targetImage'] ?? null), 255),
            'content' => $this->truncate($this->toNullableString($data['content'] ?? null), 3000),
            'destination' => $this->truncate($this->toNullableString($data['destination'] ?? null), 255),
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    private function shouldEnsureTable(\Throwable $exception): bool
    {
        if ($this->activityTableEnsured) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'activity_log') && (
            str_contains($message, 'doesn\'t exist') ||
            str_contains($message, 'base table') ||
            str_contains($message, 'no such table')
        );
    }

    private function ensureActivityLogTableExists(): void
    {
        $this->activityTableEnsured = true;

        try {
            $this->connection->executeStatement("\n                CREATE TABLE IF NOT EXISTS activity_log (\n                    id BIGINT AUTO_INCREMENT NOT NULL,\n                    module VARCHAR(50) NOT NULL,\n                    action VARCHAR(120) NOT NULL,\n                    user_id VARCHAR(36) DEFAULT NULL,\n                    user_name VARCHAR(255) DEFAULT NULL,\n                    user_avatar VARCHAR(255) DEFAULT NULL,\n                    target_type VARCHAR(80) DEFAULT NULL,\n                    target_id VARCHAR(120) DEFAULT NULL,\n                    target_name VARCHAR(255) DEFAULT NULL,\n                    target_image VARCHAR(255) DEFAULT NULL,\n                    content LONGTEXT DEFAULT NULL,\n                    destination VARCHAR(255) DEFAULT NULL,\n                    metadata_json LONGTEXT DEFAULT NULL,\n                    created_at DATETIME NOT NULL,\n                    INDEX idx_activity_log_created_at (created_at),\n                    INDEX idx_activity_log_module (module),\n                    INDEX idx_activity_log_user_id (user_id),\n                    PRIMARY KEY(id)\n                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB\n            ");
        } catch (\Throwable) {
            // Keep best-effort behavior.
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFallbackLog(string $module, string $action, array $data): void
    {
        $projectDir = dirname(__DIR__, 2);
        $logPath = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'activity_fallback.log';

        $line = sprintf(
            "[%s] module=%s action=%s payload=%s\n",
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $module,
            $action,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents($logPath, $line, FILE_APPEND);
    }

    private function refreshRiskScoreIfPossible(mixed $userId): void
    {
        try {
            $this->userRiskAssessmentService->assessByActorId(is_scalar($userId) ? (string) $userId : null);
        } catch (\Throwable) {
            // Risk scoring should never block domain actions.
        }
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            $string = trim((string) $value);
            return $string !== '' ? $string : null;
        }

        return null;
    }

    private function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - 3) . '...';
    }
}
