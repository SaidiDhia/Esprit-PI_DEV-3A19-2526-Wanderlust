<?php

namespace App\Service;

class ContentModerationService
{
    /**
     * @var array<int, string>
     */
    private array $mildWords = [
        'fuck',
        'fucking',
        'fk',
        'fck',
        'shit',
        'bitch',
        'asshole',
        'bastard',
        'idiot',
        'stupid',
        'trash',
        'damn',
        'wtf',
        'bs',
    ];

    /**
     * @var array<int, string>
     */
    private array $severeWords = [
        'die',
        'death',
        'kill',
        'kys',
        'suicide',
        'cancer',
    ];

    public function __construct(private readonly MessageToxicityService $messageToxicityService)
    {
    }

    /**
     * @return array{severity:string,score:float,should_hide:bool,reason:string,display_text:string}
     */
    public function moderateForDisplay(string $rawText): array
    {
        $text = trim($rawText);
        if ($text === '') {
            return [
                'severity' => 'none',
                'score' => 0.0,
                'should_hide' => false,
                'reason' => 'Empty text',
                'display_text' => '',
            ];
        }

        $score = $this->messageToxicityService->score($text);
        $lower = mb_strtolower($text);

        $hasMild = $this->containsAny($lower, $this->mildWords);
        $hasSevere = $this->containsAny($lower, $this->severeWords)
            || (bool) preg_match('/\b(hop(?:e|ing)?\s+(?:you|u)\s+(?:die|dead|death|get cancer)|wish\s+(?:you|u)\s+(?:die|dead|death|get cancer))\b/iu', $text);

        if ($hasSevere || $score >= 90.0) {
            return [
                'severity' => 'severe',
                'score' => $score,
                'should_hide' => true,
                'reason' => 'High-risk threatening content',
                'display_text' => 'This comment has been removed from public view due to policy violation.',
            ];
        }

        if ($hasMild || $score >= 55.0) {
            return [
                'severity' => 'mild',
                'score' => $score,
                'should_hide' => false,
                'reason' => 'Mild abusive language filtered',
                'display_text' => $this->maskProfanity($text),
            ];
        }

        return [
            'severity' => 'none',
            'score' => $score,
            'should_hide' => false,
            'reason' => 'No moderation needed',
            'display_text' => $text,
        ];
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            if ((bool) preg_match('/\b' . preg_quote($term, '/') . '\b/iu', $text)) {
                return true;
            }
        }

        return false;
    }

    private function maskProfanity(string $text): string
    {
        $masked = $text;
        foreach (array_merge($this->mildWords, $this->severeWords) as $term) {
            if ($term === '') {
                continue;
            }

            $pattern = '/\b' . preg_quote($term, '/') . '\b/iu';
            $masked = (string) preg_replace_callback($pattern, static function (array $matches): string {
                $word = (string) ($matches[0] ?? '');
                $length = mb_strlen($word);
                if ($length <= 1) {
                    return '*';
                }

                return mb_substr($word, 0, 1) . str_repeat('*', max(1, $length - 1));
            }, $masked);
        }

        return $masked;
    }
}
