<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class EmailService
{
    public function __construct(
        #[Autowire('%app.mail_from_address%')]
        private string $fromAddress,
        #[Autowire('%app.mail_from_name%')]
        private string $fromName,
        #[Autowire('%kernel.logs_dir%')]
        private string $logsDir,
        #[Autowire('%env(string:SMTP_HOST)%')]
        private string $smtpHost,
        #[Autowire('%env(int:SMTP_PORT)%')]
        private int $smtpPort,
        #[Autowire('%env(string:SMTP_USERNAME)%')]
        private string $smtpUsername,
        #[Autowire('%env(string:SMTP_PASSWORD)%')]
        private string $smtpPassword,
        #[Autowire('%env(string:SMTP_ENCRYPTION)%')]
        private string $smtpEncryption,
    ) {
    }

    public function sendPasswordResetCode(User $user, string $code): bool
    {
        $subject = 'Your Wanderlust password reset code';
        $body = sprintf(
            "Hello %s,\n\nUse this 6-digit code to reset your Wanderlust password:\n\n%s\n\nThis code expires in 10 minutes. If you did not request this, you can ignore this email.\n\nRegards,\n%s",
            $user->getFullName(),
            $code,
            $this->fromName,
        );

        return $this->sendMail($user->getEmail(), $subject, nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    }

    public function sendTwoFactorCode(User $user, string $code): bool
    {
        $subject = 'Your Wanderlust verification code';
        $body = sprintf(
            "Hello %s,\n\nYour verification code is: %s\n\nThis code expires soon. If you did not request it, ignore this message.\n\nRegards,\n%s",
            $user->getFullName(),
            $code,
            $this->fromName,
        );

        return $this->sendMail($user->getEmail(), $subject, nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    }

    private function sendMail(string $to, string $subject, string $htmlBody): bool
    {
        $smtpResult = false;

        try {
            $smtpResult = $this->sendViaSmtp($to, $subject, $htmlBody);
        } catch (\Throwable) {
            $smtpResult = false;
        }

        if ($smtpResult === false) {
            @file_put_contents(
                rtrim($this->logsDir, '\\/').DIRECTORY_SEPARATOR.'mail.log',
                sprintf("[%s] To: %s | Subject: %s\n%s\n\n", date('Y-m-d H:i:s'), $to, $subject, strip_tags($htmlBody)),
                FILE_APPEND
            );
        }

        return true;
    }

    private function sendViaSmtp(string $to, string $subject, string $htmlBody): bool
    {
        $transportHost = $this->smtpHost;
        $transportPort = $this->smtpPort;
        $transportTimeout = 15;

        $socket = @stream_socket_client(sprintf('%s:%d', $transportHost, $transportPort), $errorNumber, $errorMessage, $transportTimeout);
        if (!is_resource($socket)) {
            return false;
        }

        stream_set_timeout($socket, $transportTimeout);

        $this->smtpReadResponse($socket, [220]);
        $this->smtpWriteCommand($socket, sprintf('EHLO %s', gethostname() ?: 'localhost'), [250]);

        if ($this->smtpEncryption === 'tls') {
            $this->smtpWriteCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return false;
            }
            $this->smtpWriteCommand($socket, sprintf('EHLO %s', gethostname() ?: 'localhost'), [250]);
        }

        $this->smtpWriteCommand($socket, 'AUTH LOGIN', [334]);
        $this->smtpWriteCommand($socket, base64_encode($this->smtpUsername), [334]);
        $this->smtpWriteCommand($socket, base64_encode($this->smtpPassword), [235]);
        $this->smtpWriteCommand($socket, sprintf('MAIL FROM:<%s>', $this->fromAddress), [250]);
        $this->smtpWriteCommand($socket, sprintf('RCPT TO:<%s>', $to), [250, 251]);
        $this->smtpWriteCommand($socket, 'DATA', [354]);

        $message = implode("\r\n", [
            'From: '.$this->fromName.' <'.$this->fromAddress.'>',
            'To: <'.$to.'>',
            'Subject: '.$subject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            '',
            $htmlBody,
            '.',
        ]);

        fwrite($socket, $message."\r\n");
        $this->smtpReadResponse($socket, [250]);
        $this->smtpWriteCommand($socket, 'QUIT', [221]);
        fclose($socket);

        return true;
    }

    private function smtpWriteCommand($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command."\r\n");
        return $this->smtpReadResponse($socket, $expectedCodes);
    }

    private function smtpReadResponse($socket, array $expectedCodes): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^([0-9]{3})([ -])/', $line, $matches)) {
                $code = (int) $matches[1];
                if ($matches[2] === ' ') {
                    if (!in_array($code, $expectedCodes, true)) {
                        fclose($socket);
                        throw new \RuntimeException(sprintf('SMTP error: %s', trim($response)));
                    }

                    return $response;
                }
            }
        }

        fclose($socket);
        throw new \RuntimeException('SMTP connection closed unexpectedly.');
    }
}
