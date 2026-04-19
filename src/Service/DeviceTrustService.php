<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class DeviceTrustService
{
    private bool $tableEnsured = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{is_new_device:bool,should_alert:bool,known_devices:int}
     */
    public function registerDevice(
        string $userId,
        string $deviceFingerprint,
        ?string $deviceMac,
        ?string $firebaseDeviceId,
        string $ipAddress,
        string $location,
    ): array {
        if (trim($userId) === '' || trim($deviceFingerprint) === '') {
            return [
                'is_new_device' => false,
                'should_alert' => false,
                'known_devices' => 0,
            ];
        }

        $this->ensureTable();

        $knownDevices = $this->countKnownDevices($userId);
        $existing = $this->findDevice($userId, $deviceFingerprint);

        if (is_array($existing)) {
            try {
                $this->connection->update('user_login_device', [
                    'last_seen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'last_ip' => $this->truncate($ipAddress, 64),
                    'last_location' => $this->truncate($location, 255),
                    'device_mac' => $this->truncate($deviceMac, 128),
                    'firebase_device_id' => $this->truncate($firebaseDeviceId, 255),
                    'login_count' => ((int) ($existing['login_count'] ?? 0)) + 1,
                ], [
                    'id' => (int) $existing['id'],
                ]);
            } catch (\Throwable) {
                // best effort
            }

            return [
                'is_new_device' => false,
                'should_alert' => false,
                'known_devices' => $knownDevices,
            ];
        }

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->connection->insert('user_login_device', [
                'user_id' => $this->truncate($userId, 36),
                'device_fingerprint' => $this->truncate($deviceFingerprint, 64),
                'device_mac' => $this->truncate($deviceMac, 128),
                'firebase_device_id' => $this->truncate($firebaseDeviceId, 255),
                'first_ip' => $this->truncate($ipAddress, 64),
                'last_ip' => $this->truncate($ipAddress, 64),
                'first_location' => $this->truncate($location, 255),
                'last_location' => $this->truncate($location, 255),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'login_count' => 1,
            ]);
        } catch (\Throwable) {
            // best effort
        }

        return [
            'is_new_device' => true,
            'should_alert' => $knownDevices > 0,
            'known_devices' => $knownDevices,
        ];
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->tableEnsured = true;

        try {
            $this->connection->executeStatement(
                "CREATE TABLE IF NOT EXISTS user_login_device (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    user_id VARCHAR(36) NOT NULL,
                    device_fingerprint VARCHAR(64) NOT NULL,
                    device_mac VARCHAR(128) DEFAULT NULL,
                    firebase_device_id VARCHAR(255) DEFAULT NULL,
                    first_ip VARCHAR(64) DEFAULT NULL,
                    last_ip VARCHAR(64) DEFAULT NULL,
                    first_location VARCHAR(255) DEFAULT NULL,
                    last_location VARCHAR(255) DEFAULT NULL,
                    first_seen_at DATETIME NOT NULL,
                    last_seen_at DATETIME NOT NULL,
                    login_count INT NOT NULL DEFAULT 1,
                    INDEX idx_user_login_device_user_id (user_id),
                    UNIQUE KEY uniq_user_device_fingerprint (user_id, device_fingerprint),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB"
            );
        } catch (\Throwable) {
            // best effort
        }
    }

    private function countKnownDevices(string $userId): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM user_login_device WHERE user_id = :user_id',
                ['user_id' => $userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDevice(string $userId, string $deviceFingerprint): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, login_count FROM user_login_device WHERE user_id = :user_id AND device_fingerprint = :device_fingerprint LIMIT 1',
                [
                    'user_id' => $userId,
                    'device_fingerprint' => $deviceFingerprint,
                ]
            );

            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        return mb_substr($clean, 0, $maxLength);
    }
}
