<?php

namespace App\Security;

use App\Entity\User;
use App\Service\ActivityLogger;
use App\Service\DeviceTrustService;
use App\Service\EmailRiskDetectorService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginSuccessSubscriber implements EventSubscriberInterface
{
    private const EMAIL_VERIFICATION_ROLLOUT_CUTOFF = '2026-04-18 00:00:00';

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly DeviceTrustService $deviceTrustService,
        private readonly EmailRiskDetectorService $emailRiskDetectorService,
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailService $emailService,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $request = $event->getRequest();
            $path = $request->getPathInfo();
            $method = str_contains($path, '/face-id') ? 'face_id' : 'password';
            $userAgent = (string) $request->headers->get('User-Agent', '');
            $acceptLanguage = (string) $request->headers->get('Accept-Language', '');
            $platformHint = (string) $request->headers->get('Sec-CH-UA-Platform', '');
            $deviceFingerprint = trim((string) $request->request->get('device_fingerprint', ''));
            if ($deviceFingerprint === '') {
                $deviceFingerprint = sha1($userAgent . '|' . $acceptLanguage . '|' . $platformHint);
            }

            $firebaseDeviceId = trim((string) $request->request->get('firebase_device_id', ''));

            $deviceMac = trim((string) $request->request->get('device_mac', ''));
            if ($deviceMac === '') {
                $deviceMac = trim((string) $request->headers->get('X-Device-Mac', ''));
            }
            if ($deviceMac === '') {
                $deviceMac = 'Not available (web browsers do not expose MAC addresses)';
            }

            $clientIp = (string) ($request->getClientIp() ?? 'unknown');
            $location = $this->resolveLocationLabel($clientIp);

            $userId = (string) ($user->getId() ?? '');
            $deviceState = $this->deviceTrustService->registerDevice(
                $userId,
                $deviceFingerprint,
                $deviceMac,
                $firebaseDeviceId,
                $clientIp,
                $location,
            );

            if ((bool) ($deviceState['should_alert'] ?? false)) {
                $this->emailService->sendNewDeviceLoginAlert($user, $deviceMac, $deviceFingerprint, $clientIp, $location, $firebaseDeviceId);

                $this->activityLogger->logAction($user, 'auth', 'new_device_login_alert_sent', [
                    'targetType' => 'session',
                    'targetName' => 'New device login alert',
                    'destination' => $path,
                    'metadata' => [
                        'ip' => $clientIp,
                        'location' => $location,
                        'device_fingerprint' => $deviceFingerprint,
                        'device_mac' => $deviceMac,
                        'firebase_device_id' => $firebaseDeviceId,
                    ],
                ]);
            }

            // Force EMAIL verification at sign-in for suspicious email patterns.
            if (!$user->isAdmin() && !$this->isExistingUserBeforeRollout($user) && $user->getTfaMethod()->value === 'NONE') {
                $emailRisk = $this->emailRiskDetectorService->assess((string) $user->getEmail());
                $requiresEmailVerification = $this->emailRiskDetectorService->shouldRequireVerification((string) $user->getEmail(), $user->isAdmin());
                if ($requiresEmailVerification) {
                    $user->setTfaMethod(\App\Enum\TFAMethod::EMAIL);
                    try {
                        $this->entityManager->flush();
                    } catch (\Throwable) {
                        // best effort
                    }

                    $this->activityLogger->logAction($user, 'auth', 'email_risk_forced_tfa', [
                        'targetType' => 'user',
                        'targetId' => $user->getId(),
                        'targetName' => $user->getEmail(),
                        'metadata' => [
                            'email_risk_score' => (int) ($emailRisk['score'] ?? 0),
                            'email_risk_reasons' => (array) ($emailRisk['reasons'] ?? []),
                        ],
                    ]);
                }
            }

            $this->activityLogger->logAction($user, 'auth', 'login_success', [
                'targetType' => 'session',
                'targetName' => 'User login',
                'destination' => $path,
                'metadata' => [
                    'method' => $method,
                    'ip' => $clientIp,
                    'location' => $location,
                    'user_agent' => substr($userAgent, 0, 255),
                    'device_fingerprint' => $deviceFingerprint,
                    'device_mac' => $deviceMac,
                    'firebase_device_id' => $firebaseDeviceId,
                ],
            ]);
        }

        $request = $event->getRequest();
        $session = $request->getSession();
        if (!$session) {
            return;
        }

        $session->remove('tfa.verified_user_id');
        $session->remove('tfa.login.code');
        $session->remove('tfa.login.code_expires_at');
        $session->remove('tfa.login.last_sent_at');
    }

    private function isExistingUserBeforeRollout(User $user): bool
    {
        $createdAt = $user->getCreatedAt();
        if (!$createdAt instanceof \DateTimeImmutable) {
            return false;
        }

        $cutoff = new \DateTimeImmutable(self::EMAIL_VERIFICATION_ROLLOUT_CUTOFF);

        return $createdAt <= $cutoff;
    }

    private function resolveLocationLabel(string $ipAddress): string
    {
        $ip = trim($ipAddress);
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return 'Local machine';
        }

        if ($this->isPrivateIp($ip)) {
            return 'Private network';
        }

        $endpoint = sprintf('http://ip-api.com/json/%s?fields=status,country,regionName,city,query', rawurlencode($ip));
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        try {
            $response = @file_get_contents($endpoint, false, $context);
            if (!is_string($response) || $response === '') {
                return 'Unknown location';
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded) || (string) ($decoded['status'] ?? '') !== 'success') {
                return 'Unknown location';
            }

            $city = trim((string) ($decoded['city'] ?? ''));
            $region = trim((string) ($decoded['regionName'] ?? ''));
            $country = trim((string) ($decoded['country'] ?? ''));

            $parts = array_values(array_filter([$city, $region, $country], static fn (string $part): bool => $part !== ''));
            if ($parts === []) {
                return 'Unknown location';
            }

            return implode(', ', $parts);
        } catch (\Throwable) {
            return 'Unknown location';
        }
    }

    private function isPrivateIp(string $ip): bool
    {
        $result = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return $result === false;
    }
}
