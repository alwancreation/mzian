<?php

declare(strict_types=1);

namespace App\AI\Analysis;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Deterministic understanding of free text (fr/en/ar) driven by
 * config/mzian/ai/conversation.yaml: sector, wanted/refused features, numbers, city.
 * Used by the mock AI provider and as fallback when a real AI provider fails.
 */
final class TextSignalExtractor
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/mzian/ai/conversation.yaml')]
        private readonly string $file,
    ) {
    }

    public function extract(string $text): TextSignals
    {
        $normalized = self::normalize($text);
        $config = $this->config();

        $sector = null;
        $bestPosition = \PHP_INT_MAX;
        foreach ($config['sector_keywords'] as $code => $keywords) {
            foreach ($keywords as $keyword) {
                $position = $this->find($normalized, self::normalize($keyword));
                if (null !== $position && $position < $bestPosition) {
                    $bestPosition = $position;
                    $sector = $code;
                }
            }
        }

        $features = [];
        foreach ($config['feature_keywords'] as $code => $keywords) {
            foreach ($keywords as $keyword) {
                $position = $this->find($normalized, self::normalize($keyword));
                if (null === $position) {
                    continue;
                }
                $negated = $this->isNegated($normalized, $position);
                // An explicit refusal wins over a mention.
                $features[$code] = isset($features[$code]) ? ($features[$code] && !$negated) : !$negated;
            }
        }
        // "PDF" or "e-signature" alone imply contracts management.
        if (($features['pdf_contracts'] ?? false) || ($features['e_signature'] ?? false)) {
            $features['contracts'] ??= true;
        }

        $numbers = [];
        foreach ($config['number_patterns'] as $key => $pattern) {
            if (preg_match('~'.$pattern.'~u', $normalized, $match)) {
                $numbers[$key] = (int) $match[1];
            }
        }

        $city = null;
        foreach ($config['cities'] as $candidate) {
            if (null !== $this->find($normalized, self::normalize($candidate))) {
                $city = self::displayCity($candidate);
                break;
            }
        }

        return new TextSignals($sector, $features, $numbers, $city);
    }

    /**
     * true = yes, false = no, null = not a yes/no answer.
     */
    public function yesNo(string $text): ?bool
    {
        $normalized = trim(self::normalize($text), " \t\n\r\0\x0B.!?,;");
        $config = $this->config();
        foreach ($config['no_words'] as $word) {
            $word = self::normalize($word);
            if ($normalized === $word || str_starts_with($normalized, $word.' ') || str_starts_with($normalized, $word.',')) {
                return false;
            }
        }
        foreach ($config['yes_words'] as $word) {
            $word = self::normalize($word);
            if ($normalized === $word || str_starts_with($normalized, $word.' ') || str_starts_with($normalized, $word.',')) {
                return true;
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, question: array<string, string>, requires?: string, sectors?: list<string>}>
     */
    public function followUps(): array
    {
        return $this->config()['follow_up'];
    }

    /**
     * @return array<string, list<string>>
     */
    public function equivalents(): array
    {
        return $this->config()['feature_equivalents'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function knownSectorCodes(): array
    {
        return array_keys($this->config()['sector_keywords']);
    }

    public static function normalize(string $text): string
    {
        $text = str_replace(['’', '`', '´'], "'", $text);
        $text = mb_strtolower($text);
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if (null !== $transliterator) {
                $text = (string) $transliterator->transliterate($text);
            }
        }

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    private function find(string $haystack, string $needle): ?int
    {
        if ('' === $needle) {
            return null;
        }
        // Latin keywords must match whole words ("api" must not match "rapide");
        // Arabic keywords may carry prefixes ("ال", "و"...), so a substring is enough.
        if (preg_match('/\p{Arabic}/u', $needle)) {
            $position = mb_strpos($haystack, $needle);

            return false === $position ? null : $position;
        }
        if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack, $match, \PREG_OFFSET_CAPTURE)) {
            return mb_strlen(substr($haystack, 0, $match[0][1]));
        }

        return null;
    }

    private function isNegated(string $text, int $position): bool
    {
        $window = mb_substr($text, max(0, $position - 22), min(22, $position));
        // Only look inside the current clause.
        $window = (string) preg_replace('/^.*[.,;!?:]/u', '', $window);
        foreach ($this->config()['negations'] as $negation) {
            $negation = self::normalize($negation);
            if (str_contains(' '.$window, ' '.ltrim($negation)) || str_contains($window, $negation)) {
                return true;
            }
        }

        return false;
    }

    private static function displayCity(string $city): string
    {
        return preg_match('/\p{Arabic}/u', $city) ? $city : mb_convert_case($city, \MB_CASE_TITLE);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->config ??= Yaml::parseFile($this->file);
    }
}
