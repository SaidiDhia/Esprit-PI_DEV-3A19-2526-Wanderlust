<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SmsService
{
    private string $twilioAccountSid;
    private string $twilioAuthToken;
    private string $twilioFromNumber;
    private string $twilioVerifyServiceSid;
    private bool $allowLogFallback;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
        #[Autowire('%env(default::TWILIO_ACCOUNT_SID)%')]
        ?string $twilioAccountSid,
        #[Autowire('%env(default::TWILIO_AUTH_TOKEN)%')]
        ?string $twilioAuthToken,
        #[Autowire('%env(default::TWILIO_FROM_NUMBER)%')]
        ?string $twilioFromNumber,
        #[Autowire('%env(default::TWILIO_VERIFY_SERVICE_SID)%')]
        ?string $twilioVerifyServiceSid,
        #[Autowire('%env(default::SMS_ALLOW_LOG_FALLBACK)%')]
        ?string $allowLogFallback,
    ) {
        $this->twilioAccountSid = (string) ($twilioAccountSid ?? '');
        $this->twilioAuthToken = (string) ($twilioAuthToken ?? '');
        $this->twilioFromNumber = (string) ($twilioFromNumber ?? '');
        $this->twilioVerifyServiceSid = (string) ($twilioVerifyServiceSid ?? '');
        $this->allowLogFallback = in_array(strtolower(trim((string) ($allowLogFallback ?? '0'))), ['1', 'true', 'yes', 'on'], true);
    }

    public function isManagedVerificationEnabled(): bool
    {
        return $this->twilioAccountSid !== '' && $this->twilioAuthToken !== '' && $this->twilioVerifyServiceSid !== '';
    }

    public function sendVerificationCode(string $phoneNumber, string $code, string $channel = 'sms'): bool
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['sms', 'whatsapp'], true)) {
            $channel = 'sms';
        }

        $message = sprintf('Your Wanderlust verification code is %s. It expires in 10 minutes.', $code);

        if ($this->isManagedVerificationEnabled()) {
            return $this->startVerify($phoneNumber, $channel);
        }

        if ($this->twilioAccountSid === '' || $this->twilioAuthToken === '' || $this->twilioFromNumber === '') {
            $this->logDiagnostics('Missing Twilio credentials or sender number.', $phoneNumber);
            $this->logFallback($phoneNumber, $message);
            return $this->allowLogFallback;
        }

        if ($channel === 'whatsapp') {
            $this->logDiagnostics('WhatsApp channel requires Twilio Verify. Set TWILIO_VERIFY_SERVICE_SID.', $phoneNumber);
            $this->logFallback($phoneNumber, $message);
            return $this->allowLogFallback;
        }

        try {
            $to = $this->normalizePhoneNumber($phoneNumber);
            $from = $this->normalizePhoneNumber($this->twilioFromNumber);
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $this->twilioAccountSid),
                [
                    'auth_basic' => [$this->twilioAccountSid, $this->twilioAuthToken],
                    'body' => [
                        'From' => $from,
                        'To' => $to,
                        'Body' => $message,
                    ],
                    'timeout' => 20,
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $payload = $response->toArray(false);
                if (($payload['status'] ?? '') === 'failed' || ($payload['error_code'] ?? null) !== null) {
                    $this->logDiagnostics(
                        sprintf('Twilio API returned failed status. error_code=%s error_message=%s', (string) ($payload['error_code'] ?? ''), (string) ($payload['error_message'] ?? '')),
                        $to
                    );
                    $this->logFallback($to, $message);
                    return $this->allowLogFallback;
                }

                return true;
            }
            $this->logDiagnostics(sprintf('Twilio API HTTP status %d.', $statusCode), $to ?? $phoneNumber);
        } catch (\Throwable $exception) {
            $this->logDiagnostics('Twilio exception: '.$exception->getMessage(), $phoneNumber);
        }

        $this->logFallback($phoneNumber, $message);
        return $this->allowLogFallback;
    }

    public function verifyReceivedCode(string $phoneNumber, string $code, string $channel = 'sms'): bool
    {
        if (!$this->isManagedVerificationEnabled()) {
            return false;
        }

        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['sms', 'whatsapp'], true)) {
            $channel = 'sms';
        }

        $to = $this->normalizePhoneNumber($phoneNumber);
        if ($channel === 'whatsapp' && !str_starts_with($to, 'whatsapp:')) {
            $to = 'whatsapp:'.$to;
        }
        $code = trim($code);

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://verify.twilio.com/v2/Services/%s/VerificationCheck', rawurlencode($this->twilioVerifyServiceSid)),
                [
                    'auth_basic' => [$this->twilioAccountSid, $this->twilioAuthToken],
                    'body' => [
                        'To' => $to,
                        'Code' => $code,
                    ],
                    'timeout' => 20,
                ]
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $this->logDiagnostics(sprintf('Twilio Verify check HTTP status %d.', $response->getStatusCode()), $to);
                return false;
            }

            $payload = $response->toArray(false);
            return ($payload['status'] ?? '') === 'approved' || (($payload['valid'] ?? false) === true);
        } catch (\Throwable $exception) {
            $this->logDiagnostics('Twilio Verify check exception: '.$exception->getMessage(), $to);
            return false;
        }
    }

    private function startVerify(string $phoneNumber, string $channel): bool
    {
        $to = $this->normalizePhoneNumber($phoneNumber);
        if ($channel === 'whatsapp' && !str_starts_with($to, 'whatsapp:')) {
            $to = 'whatsapp:'.$to;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://verify.twilio.com/v2/Services/%s/Verifications', rawurlencode($this->twilioVerifyServiceSid)),
                [
                    'auth_basic' => [$this->twilioAccountSid, $this->twilioAuthToken],
                    'body' => [
                        'To' => $to,
                        'Channel' => $channel,
                    ],
                    'timeout' => 20,
                ]
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $this->logDiagnostics(sprintf('Twilio Verify start HTTP status %d.', $response->getStatusCode()), $to);
                return false;
            }

            $payload = $response->toArray(false);
            return in_array((string) ($payload['status'] ?? ''), ['pending', 'approved'], true);
        } catch (\Throwable $exception) {
            $this->logDiagnostics('Twilio Verify start exception: '.$exception->getMessage(), $to);
            return false;
        }
    }

    private function logFallback(string $phoneNumber, string $message): void
    {
        @file_put_contents(
            rtrim($this->logsDir, '\\/').DIRECTORY_SEPARATOR.'sms.log',
            sprintf("[%s] [fallback] To: %s | %s\n", date('Y-m-d H:i:s'), $phoneNumber, $message),
            FILE_APPEND
        );
    }

    private function logDiagnostics(string $details, string $phoneNumber): void
    {
        @file_put_contents(
            rtrim($this->logsDir, '\/').DIRECTORY_SEPARATOR.'sms.log',
            sprintf("[%s] [twilio-error] To: %s | %s\n", date('Y-m-d H:i:s'), $phoneNumber, $details),
            FILE_APPEND
        );
    }

    private function normalizePhoneNumber(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        if (!str_starts_with($value, '+')) {
            $digits = preg_replace('/\D+/', '', $value) ?? '';
            if ($digits !== '') {
                $value = '+'.$digits;
            }
        }

        return $value;
    }
}
