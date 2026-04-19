<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\TFAMethod;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;

class TwoFactorService
{
    private const SESSION_CODE = 'tfa.login.code';
    private const SESSION_CODE_EXPIRES_AT = 'tfa.login.code_expires_at';
    private const SESSION_LAST_SENT_AT = 'tfa.login.last_sent_at';

    public function __construct(
        private readonly EmailService $emailService,
        private readonly SmsService $smsService,
        private readonly GoogleAuthenticatorInterface $googleAuthenticator,
    ) {
    }

    public function issueCodeForUser(User $user, \Symfony\Component\HttpFoundation\Session\SessionInterface $session): bool
    {
        $method = $user->getTfaMethod();
        if (!in_array($method, [TFAMethod::EMAIL, TFAMethod::SMS, TFAMethod::WHATSAPP], true)) {
            return false;
        }

        $code = (string) random_int(100000, 999999);
        $sent = false;

        if ($method === TFAMethod::EMAIL) {
            $sent = $this->emailService->sendTwoFactorCode($user, $code);
        } else {
            $phone = (string) $user->getPhoneNumber();
            if ($phone === '') {
                return false;
            }

            $channel = $method === TFAMethod::WHATSAPP ? 'whatsapp' : 'sms';
            $sent = $this->smsService->sendVerificationCode($phone, $code, $channel);

            if ($this->smsService->isManagedVerificationEnabled()) {
                if (!$sent) {
                    $this->clearCode($session);
                    return false;
                }

                $session->set(self::SESSION_LAST_SENT_AT, time());
                $session->remove(self::SESSION_CODE);
                $session->remove(self::SESSION_CODE_EXPIRES_AT);
                return true;
            }
        }

        if (!$sent) {
            $this->clearCode($session);
            return false;
        }

        $expiresAt = time() + 600;
        $session->set(self::SESSION_CODE, password_hash($code, PASSWORD_DEFAULT));
        $session->set(self::SESSION_CODE_EXPIRES_AT, $expiresAt);
        $session->set(self::SESSION_LAST_SENT_AT, time());

        return true;
    }

    public function canResendCode(\Symfony\Component\HttpFoundation\Session\SessionInterface $session): bool
    {
        $lastSentAt = (int) $session->get(self::SESSION_LAST_SENT_AT, 0);
        return $lastSentAt === 0 || (time() - $lastSentAt) >= 30;
    }

    public function verifyCode(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, string $inputCode): bool
    {
        $hash = (string) $session->get(self::SESSION_CODE, '');
        $expiresAt = (int) $session->get(self::SESSION_CODE_EXPIRES_AT, 0);

        if ($hash === '' || $expiresAt < time()) {
            return false;
        }

        return password_verify($inputCode, $hash);
    }

    public function verifyMessageCode(User $user, \Symfony\Component\HttpFoundation\Session\SessionInterface $session, string $inputCode): bool
    {
        $method = $user->getTfaMethod();
        $channel = $method === TFAMethod::WHATSAPP ? 'whatsapp' : 'sms';

        if (!$this->smsService->isManagedVerificationEnabled()) {
            return $this->verifyCode($session, $inputCode);
        }

        $phone = (string) $user->getPhoneNumber();
        if ($phone === '') {
            return false;
        }

        return $this->smsService->verifyReceivedCode($phone, $inputCode, $channel);
    }

    public function clearCode(\Symfony\Component\HttpFoundation\Session\SessionInterface $session): void
    {
        $session->remove(self::SESSION_CODE);
        $session->remove(self::SESSION_CODE_EXPIRES_AT);
        $session->remove(self::SESSION_LAST_SENT_AT);
    }

    public function generateAppSecret(): string
    {
        return $this->googleAuthenticator->generateSecret();
    }

    public function getOtpAuthUri(User $user, string $issuer = 'Wanderlust'): ?string
    {
        if (!$user->isGoogleAuthenticatorEnabled()) {
            return null;
        }

        return $this->googleAuthenticator->getQRContent($user);
    }

    public function getOtpQrCodeUrl(User $user, string $issuer = 'Wanderlust'): ?string
    {
        $otpAuthUri = $this->getOtpAuthUri($user, $issuer);
        if (!$otpAuthUri) {
            return null;
        }

        return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.rawurlencode($otpAuthUri);
    }

    public function verifyTotpCode(User $user, string $code): bool
    {
        if (!$user->isGoogleAuthenticatorEnabled()) {
            return false;
        }

        return $this->googleAuthenticator->checkCode($user, trim($code));
    }
}
