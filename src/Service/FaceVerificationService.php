<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FaceVerificationService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
        #[Autowire('%env(default::DEEPFACE_API_URL)%')]
        private readonly string $deepFaceApiUrl,
        #[Autowire('%env(default::DEEPFACE_MODEL_NAME)%')]
        private readonly string $modelName,
        #[Autowire('%env(default::DEEPFACE_DETECTOR_BACKEND)%')]
        private readonly string $detectorBackend,
    ) {
    }

    public function verifyBase64SelfieAgainstReference(string $selfieBase64, string $referencePath): bool
    {
        if (!is_file($referencePath)) {
            $this->logDiagnostics('Reference image file does not exist: '.$referencePath);
            return false;
        }

        $referenceBinary = @file_get_contents($referencePath);
        if ($referenceBinary === false || $referenceBinary === '') {
            $this->logDiagnostics('Reference image file is empty or unreadable.');
            return false;
        }

        $selfieDataUri = $this->normalizeImageDataUri($selfieBase64);
        if ($selfieDataUri === null) {
            $this->logDiagnostics('Invalid selfie payload.');
            return false;
        }

        $selfieBinary = $this->decodeDataUriToBinary($selfieDataUri);
        if ($selfieBinary === null || $selfieBinary === '') {
            $this->logDiagnostics('Unable to decode selfie payload to binary image data.');
            return false;
        }

        $selfieTempFile = tempnam(sys_get_temp_dir(), 'face-selfie-');
        if ($selfieTempFile === false) {
            $this->logDiagnostics('Unable to create temporary file for selfie image.');
            return false;
        }

        try {
            file_put_contents($selfieTempFile, $selfieBinary);
        } catch (\Throwable) {
            @unlink($selfieTempFile);
            $this->logDiagnostics('Unable to write selfie image to temporary file.');
            return false;
        }

        try {
            $apiUrl = $this->resolveVerifyUrl($this->deepFaceApiUrl);

            $formData = new FormDataPart([
                'img1' => DataPart::fromPath($referencePath, 'img1.jpg', 'image/jpeg'),
                'img2' => DataPart::fromPath($selfieTempFile, 'img2.jpg', 'image/jpeg'),
            ]);

            $response = $this->httpClient->request('POST', $apiUrl, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = trim($response->getContent(false));

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logDiagnostics(sprintf('DeepFace API returned HTTP %d. Body: %s', $statusCode, $rawBody !== '' ? $rawBody : '[empty]'));
                return false;
            }

            $decoded = json_decode($rawBody, true);
            $data = is_array($decoded) ? $decoded : $response->toArray(false);
            if (!is_array($data)) {
                $this->logDiagnostics('DeepFace API response is not a JSON object/array. Body: '.($rawBody !== '' ? $rawBody : '[empty]'));
                return false;
            }

            $result = $this->extractVerificationResult($data);
            if ($result === null) {
                $this->logDiagnostics('DeepFace API response did not include a recognized verification result shape: '.json_encode($data));
                return false;
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->logDiagnostics('DeepFace exception: '.$exception->getMessage());
            return false;
        } finally {
            @unlink($selfieTempFile);
        }
    }

    private function resolveVerifyUrl(string $configuredUrl): string
    {
        $base = trim($configuredUrl);
        if ($base === '') {
            return 'http://127.0.0.1:5000/verify';
        }

        $base = rtrim($base, '/');
        if (str_ends_with(strtolower($base), '/verify')) {
            return $base;
        }

        return $base.'/verify';
    }

    private function extractVerificationResult(array $data): ?bool
    {
        $candidates = [$data];

        if (isset($data[0]) && is_array($data[0])) {
            $candidates[] = $data[0];
        }

        if (isset($data['result']) && is_array($data['result'])) {
            $candidates[] = $data['result'];
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists('verified', $candidate)) {
                $value = $candidate['verified'];
                if (is_bool($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $normalized = strtolower(trim($value));
                    if (in_array($normalized, ['true', '1', 'yes'], true)) {
                        return true;
                    }
                    if (in_array($normalized, ['false', '0', 'no'], true)) {
                        return false;
                    }
                }

                if (is_numeric($value)) {
                    return (float) $value > 0;
                }
            }

            if (isset($candidate['distance'], $candidate['threshold']) && is_numeric($candidate['distance']) && is_numeric($candidate['threshold'])) {
                return (float) $candidate['distance'] <= (float) $candidate['threshold'];
            }
        }

        return null;
    }

    private function normalizeImageDataUri(string $dataUri): ?string
    {
        $payload = $dataUri;
        $prefix = 'data:image/jpeg;base64,';

        if (str_contains($dataUri, ',')) {
            $parts = explode(',', $dataUri, 2);
            if (count($parts) !== 2) {
                return null;
            }

            $prefix = str_ends_with(strtolower($parts[0]), ';base64') ? ($parts[0].',') : $prefix;
            [, $payload] = explode(',', $dataUri, 2);
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        return $prefix.base64_encode($decoded);
    }

    private function decodeDataUriToBinary(string $dataUri): ?string
    {
        $payload = $dataUri;
        if (str_contains($dataUri, ',')) {
            [, $payload] = explode(',', $dataUri, 2);
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }

    private function logDiagnostics(string $message): void
    {
        @file_put_contents(
            rtrim($this->logsDir, '\\/').DIRECTORY_SEPARATOR.'face_verification.log',
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND
        );
    }
}
