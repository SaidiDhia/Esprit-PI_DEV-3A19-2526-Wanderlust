<?php

namespace App\Service;

class AiReviewResult
{
    public function __construct(
        private string $sentiment,
        private string $summary,
        private string $languageQuality,
    ) {
    }

    public function getSentiment(): string
    {
        return $this->sentiment;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getLanguageQuality(): string
    {
        return $this->languageQuality;
    }
}
