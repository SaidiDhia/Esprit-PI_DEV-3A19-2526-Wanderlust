<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * File constraint that doesn't require php_fileinfo extension
 * @Annotation
 */
#[\Attribute]
class SafeFile extends Constraint
{
    public ?string $maxSize = null;
    public array|string|null $mimeTypes = null;
    public string $maxSizeMessage = 'The file is too large ({{ limit }} allowed).';
    public string $mimeTypesMessage = 'This file type is not allowed.';

    public function __construct(
        mixed $options = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct($options, $groups, $payload);
    }
}
