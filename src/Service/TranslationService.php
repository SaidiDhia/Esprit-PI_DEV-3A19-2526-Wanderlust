<?php

namespace App\Service;

class TranslationService
{
    private const API_URL = 'https://api.mymemory.translated.net/get';
    private const LANG_PAIRS = [
        ['fr', 'en'],
        ['en', 'fr'],
    ];

    public function translateAuto(string $text): array
    {
        if (trim($text) === '') {
            return ['translatedText' => $text, 'sourceLang' => 'en', 'targetLang' => 'en', 'success' => false];
        }

        $detectedLang = $this->detectLanguage(substr($text, 0, 240));

        $pairs = $detectedLang === 'fr'
            ? [['fr', 'en'], ['en', 'fr']]
            : [['en', 'fr'], ['fr', 'en']];

        $translated = null;
        $sourceLang = $pairs[0][0];
        $targetLang = $pairs[0][1];

        foreach ($pairs as [$src, $tgt]) {
            $candidate = $this->callMyMemory($text, $src, $tgt);
            if ($candidate !== null && $this->isUsefulTranslation($text, $candidate)) {
                $translated = $candidate;
                $sourceLang = $src;
                $targetLang = $tgt;
                break;
            }
        }

        $success = $translated !== null;

        return [
            'translatedText' => $success ? $translated : $text,
            'sourceLang'     => $sourceLang,
            'targetLang'     => $targetLang,
            'success'        => $success,
        ];
    }

    private function detectLanguage(string $sample): string
    {
        $lower   = mb_strtolower($sample);
        $frScore = 0;
        $enScore = 0;

        $normalized = preg_replace('/[^\p{L}\p{N}\s\']/u', ' ', $lower) ?? $lower;
        $tokens = preg_split('/\s+/u', trim($normalized)) ?: [];
        $tokenSet = array_fill_keys($tokens, true);

        $frWords = [
            'le','la','les','de','du','des','un','une','est','et','en','je','pour','dans','sur','avec',
            'vous','nous','pas','mais','que','qui','au','aux','ce','cette','ces','mon','ton','son',
            'bonjour','salut','merci','quoi','comment','ca','ça','ici','oui','non'
        ];
        $enWords = [
            'the','a','an','is','are','was','i','you','he','she','we','they','this','that','with','for',
            'what','why','how','hello','hi','guys','up','thanks','please','my','your','our','their',
            'in','on','at','from','to','and','or','not'
        ];

        foreach ($frWords as $w) {
            if (isset($tokenSet[$w])) {
                $frScore++;
            }
        }
        foreach ($enWords as $w) {
            if (isset($tokenSet[$w])) {
                $enScore++;
            }
        }

        foreach (mb_str_split($sample) as $c) {
            if (str_contains('àâäéèêëîïôùûüçœæ', $c)) $frScore += 3;
        }

        if (preg_match('/\b(l\'|d\'|qu\'|j\')/u', $lower) === 1) {
            $frScore += 2;
        }

        if (preg_match('/\b(i\'m|you\'re|we\'re|they\'re|don\'t|can\'t|what\'s)\b/u', $lower) === 1) {
            $enScore += 2;
        }

        if ($frScore === $enScore) {
            return 'en';
        }

        return $frScore > $enScore ? 'fr' : 'en';
    }

    private function isUsefulTranslation(string $original, string $translated): bool
    {
        $o = trim(mb_strtolower($original));
        $t = trim(mb_strtolower($translated));

        if ($t === '' || $t === $o) {
            return false;
        }

        return true;
    }

    private function callMyMemory(string $text, string $source, string $target): ?string
    {
        $url = self::API_URL . '?q=' . urlencode($text) . '&langpair=' . urlencode("$source|$target");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 WanderlustApp/1.0',
        ]);
        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode !== 200 || !$response) return null;

        $data       = json_decode($response, true);
        $translated = $data['responseData']['translatedText'] ?? null;

        if (!$translated || stripos($translated, 'MYMEMORY WARNING') !== false) return null;

        return $translated;
    }
}