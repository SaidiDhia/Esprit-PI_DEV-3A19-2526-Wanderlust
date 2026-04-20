<?php

namespace App\Security;

use App\Service\ActivityLogger;
use App\Service\EmailService;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

class LoginFailureSubscriber implements EventSubscriberInterface
{
    private const ALERT_THRESHOLD = 3;
    private const ALERT_WINDOW_MINUTES = 10;
    private const ALERT_COOLDOWN_MINUTES = 30;

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly Connection $connection,
        private readonly EmailService $emailService,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $email = trim((string) $request->request->get('email', ''));
        $clientIp = (string) ($request->getClientIp() ?? 'unknown');
        $userAgent = substr((string) $request->headers->get('User-Agent', ''), 0, 255);

        $this->activityLogger->logAction(null, 'auth', 'login_failed', [
            'targetType' => 'session',
            'targetName' => 'Login failure',
            'content' => $email !== '' ? sprintf('Failed login attempt for %s', $email) : 'Failed login attempt',
            'destination' => $request->getPathInfo(),
            'metadata' => [
                'email' => $email !== '' ? $email : null,
                'ip' => $clientIp,
                'user_agent' => $userAgent,
            ],
        ]);

        $this->maybeSendRepeatedFailureAlerts($email, $clientIp);
    }

    private function maybeSendRepeatedFailureAlerts(string $email, string $clientIp): void
    {
        if ($email === '') {
            return;
        }

        try {
            $recentFailures = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM activity_log
                 WHERE module = 'auth'
                   AND action = 'login_failed'
                   AND content LIKE :email_pattern
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :window_minutes MINUTE)",
                [
                    'email_pattern' => '%' . $email . '%',
                    'window_minutes' => self::ALERT_WINDOW_MINUTES,
                ]
            )->fetchOne();
        } catch (\Throwable) {
            return;
        }

        if ($recentFailures < self::ALERT_THRESHOLD) {
            return;
        }

        try {
            $recentAlerts = (int) $this->connection->executeQuery(
                "SELECT COUNT(*)
                 FROM activity_log
                 WHERE module = 'auth'
                   AND action = 'login_failure_alert_sent'
                   AND target_name = :email
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :cooldown_minutes MINUTE)",
                [
                    'email' => $email,
                    'cooldown_minutes' => self::ALERT_COOLDOWN_MINUTES,
                ]
            )->fetchOne();
        } catch (\Throwable) {
            $recentAlerts = 1;
        }

        if ($recentAlerts > 0) {
            return;
        }

        $userName = $email;
        try {
            $userRow = $this->connection->executeQuery(
                "SELECT full_name
                 FROM users
                 WHERE LOWER(email) = LOWER(:email)
                 LIMIT 1",
                ['email' => $email]
            )->fetchAssociative();

            if (is_array($userRow)) {
                $resolvedName = trim((string) ($userRow['full_name'] ?? ''));
                if ($resolvedName !== '') {
                    $userName = $resolvedName;
                }
            }
        } catch (\Throwable) {
            // Best effort only.
        }

        try {
            $this->emailService->sendRepeatedLoginFailureUserAlert($email, $userName, $recentFailures, $clientIp);
        } catch (\Throwable) {
            // Best effort only.
        }

        try {
            $adminRows = $this->connection->executeQuery(
                "SELECT email
                 FROM users
                 WHERE role = 'ADMIN'
                   AND is_active = 1
                   AND email IS NOT NULL
                   AND TRIM(email) != ''"
            )->fetchAllAssociative();

            foreach ($adminRows as $adminRow) {
                $adminEmail = trim((string) ($adminRow['email'] ?? ''));
                if ($adminEmail === '') {
                    continue;
                }

                $this->emailService->sendRepeatedLoginFailureAdminAlert($adminEmail, $userName, $email, $recentFailures, $clientIp);
            }
        } catch (\Throwable) {
            // Best effort only.
        }

        $this->activityLogger->logAction(null, 'auth', 'login_failure_alert_sent', [
            'targetType' => 'user_email',
            'targetName' => $email,
            'content' => sprintf('Repeated failed login alert sent after %d attempts', $recentFailures),
            'metadata' => [
                'email' => $email,
                'attempts' => $recentFailures,
                'source_ip' => $clientIp,
            ],
        ]);
    }
}
