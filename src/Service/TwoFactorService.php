<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\TFAMethod;

class TwoFactorService
{
    private const SESSION_CODE = 'tfa.login.code';
    private const SESSION_CODE_EXPIRES_AT = 'tfa.login.code_expires_at';
    private const SESSION_LAST_SENT_AT = 'tfa.login.last_sent_at';

    public function __construct(
        private readonly EmailService $emailService,
        private readonly SmsService $smsService,
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
        $raw = random_bytes(20);
        return $this->base32Encode($raw);
    }

    public function getOtpAuthUri(User $user, string $issuer = 'Wanderlust'): ?string
    {
        $secret = $user->getTfaSecret();
        if (!$secret) {
            return null;
        }

        $label = rawurlencode($issuer.':'.$user->getEmail());
        return sprintf('otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30', $label, $secret, rawurlencode($issuer));
    }

    public function getOtpQrCodeUrl(User $user, string $issuer = 'Wanderlust'): ?string
    {
        $otpAuthUri = $this->getOtpAuthUri($user, $issuer);
        if (!$otpAuthUri) {
            return null;
        }

        return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.rawurlencode($otpAuthUri);
    }

    public function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);
        for ($offset = -$window; $offset <= $window; ++$offset) {
            if (hash_equals($this->calculateTotp($secret, $timeSlice + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    private function calculateTotp(string $secret, int $timeSlice): string
    {
        $binarySecret = $this->base32Decode($secret);
        $timeBytes = pack('N*', 0, $timeSlice);
        $hmac = hash_hmac('sha1', $timeBytes, $binarySecret, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $chunk = substr($hmac, $offset, 4);
        $value = unpack('N', $chunk)[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; ++$i) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $binary = '';
        $length = strlen($secret);

        for ($i = 0; $i < $length; ++$i) {
            if (!isset($alphabet[$secret[$i]])) {
                continue;
            }
            $binary .= str_pad(decbin($alphabet[$secret[$i]]), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
