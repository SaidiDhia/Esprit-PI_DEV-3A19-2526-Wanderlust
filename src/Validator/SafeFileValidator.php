<?php

namespace App\Validator;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates files without requiring php_fileinfo extension
 * Falls back to extension-based validation if MIME guessing fails
 */
class SafeFileValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (null === $value || '' === $value) {
            return;
        }

        if (!$constraint instanceof SafeFile) {
            throw new UnexpectedTypeException($constraint, SafeFile::class);
        }

        if (!$value instanceof UploadedFile) {
            throw new UnexpectedValueException($value, UploadedFile::class);
        }

        // Check file size
        if ($constraint->maxSize !== null) {
            $maxBytes = $this->parseSize($constraint->maxSize);
            if ($value->getSize() > $maxBytes) {
                $this->context->buildViolation($constraint->maxSizeMessage)
                    ->setParameter('{{ size }}', $this->formatBytes($value->getSize()))
                    ->setParameter('{{ limit }}', $this->formatBytes($maxBytes))
                    ->addViolation();
                return;
            }
        }

        // Check MIME type safely
        if ($constraint->mimeTypes !== null && count($constraint->mimeTypes) > 0) {
            $validMimeType = $this->getMimeTypeSafely($value);
            
            if ($validMimeType && !in_array($validMimeType, (array) $constraint->mimeTypes, true)) {
                $this->context->buildViolation($constraint->mimeTypesMessage)
                    ->setParameter('{{ type }}', $validMimeType)
                    ->setParameter('{{ types }}', implode(', ', (array) $constraint->mimeTypes))
                    ->addViolation();
            }
        }
    }

    /**
     * Get MIME type safely with fallback to extension-based detection
     */
    private function getMimeTypeSafely(UploadedFile $file): ?string
    {
        // Try server MIME detection
        try {
            $mimeType = (string) $file->getMimeType();
            if ($mimeType !== '' && str_starts_with($mimeType, 'image/')) {
                return $mimeType;
            }
        } catch (\Throwable) {
            // fileinfo not available
        }

        // Try client MIME
        $clientMime = (string) $file->getClientMimeType();
        if ($clientMime !== '' && str_starts_with($clientMime, 'image/')) {
            return $clientMime;
        }

        // Fallback: detect from extension
        $extension = strtolower((string) $file->getClientOriginalExtension());
        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    private function parseSize(string $size): int
    {
        $size = trim($size);
        $units = ['B' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3];
        
        foreach ($units as $unit => $multiplier) {
            if (str_ends_with(strtoupper($size), $unit)) {
                $number = (int) substr($size, 0, -1);
                return $number * $multiplier;
            }
        }
        
        return (int) $size;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
