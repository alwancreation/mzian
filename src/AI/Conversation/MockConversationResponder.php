<?php

declare(strict_types=1);

namespace App\AI\Conversation;

use App\AI\Analysis\TextSignalExtractor;
use App\Catalog\Service\CatalogProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deterministic assistant turn: understands the message with TextSignalExtractor,
 * records the answer to the pending question and asks the next most relevant
 * follow-up question (children of a feature just confirmed come first).
 */
final readonly class MockConversationResponder
{
    private const MAX_QUESTIONS = 7;

    public function __construct(
        private TextSignalExtractor $extractor,
        private CatalogProvider $catalog,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $state {locale, sector, features: {code: bool}, asked: list, pending_question, user_messages}
     *
     * @return array<string, mixed> conversation_turn schema
     */
    public function respond(array $state, string $message): array
    {
        $locale = (string) ($state['locale'] ?? 'fr');
        $known = (array) ($state['features'] ?? []);
        $asked = array_values((array) ($state['asked'] ?? []));
        $pending = $state['pending_question'] ?? null;
        $signals = $this->extractor->extract($message);

        $add = $signals->wanted();
        $remove = $signals->refused();
        $answeredPending = false;
        $yesNo = $this->extractor->yesNo($message);
        if (\is_string($pending) && 'sector' !== $pending && null !== $yesNo && !isset($signals->features[$pending])) {
            $yesNo ? $add[] = $pending : $remove[] = $pending;
            $answeredPending = true;
        }

        $sector = $state['sector'] ?? null;
        if (null === $sector && null !== $signals->sector) {
            $sector = $signals->sector;
            $answeredPending = $answeredPending || 'sector' === $pending;
        }

        $facts = [];
        foreach ($signals->numbers as $key => $value) {
            $facts[] = ['key' => $key, 'value' => (string) $value];
        }

        $features = $known;
        foreach ($add as $code) {
            $features[$code] = true;
        }
        foreach ($remove as $code) {
            $features[$code] = false;
        }

        $next = $this->nextQuestion($sector, $features, $asked, $add);
        $userMessages = (int) ($state['user_messages'] ?? 0) + 1;
        $ready = null !== $sector && (null === $next || $userMessages >= 5 || \count($asked) >= 4);

        $parts = [];
        $ack = $this->acknowledge($locale, null !== $sector && $sector !== ($state['sector'] ?? null) ? $sector : null, $signals->city, $signals->numbers, $add, $remove);
        if ('' !== $ack) {
            $parts[] = $ack;
        } elseif (!$answeredPending) {
            $parts[] = $this->translator->trans('chat.not_understood', [], null, $locale);
        } else {
            $parts[] = $this->translator->trans('chat.noted', [], null, $locale);
        }
        if (null !== $next) {
            $parts[] = $this->question($next, $locale);
            if ($ready) {
                $parts[] = $this->translator->trans('chat.ready_anytime', [], null, $locale);
            }
        } elseif ($ready) {
            $parts[] = $this->translator->trans('chat.ready', [], null, $locale);
        }

        return [
            'reply' => implode(' ', $parts),
            'sector' => $sector,
            'business_name' => null,
            'city' => $signals->city,
            'facts' => $facts,
            'features_add' => array_values(array_unique($add)),
            'features_remove' => array_values(array_unique($remove)),
            'next_question_key' => $next,
            'ready' => $ready,
        ];
    }

    /**
     * @param array<string, bool> $features
     * @param list<string>        $asked
     * @param list<string>        $justAdded
     */
    private function nextQuestion(?string $sector, array $features, array $asked, array $justAdded): ?string
    {
        if (null === $sector) {
            return \count(array_keys($asked, 'sector', true)) < 2 ? 'sector' : null;
        }
        if (\count($asked) >= self::MAX_QUESTIONS) {
            return null;
        }
        $candidates = [];
        foreach ($this->extractor->followUps() as $followUp) {
            $key = $followUp['key'];
            if ('sector' === $key || \in_array($key, $asked, true) || \array_key_exists($key, $features)) {
                continue;
            }
            if (isset($followUp['sectors']) && !\in_array($sector, $followUp['sectors'], true)) {
                continue;
            }
            if (isset($followUp['requires']) && true !== ($features[$followUp['requires']] ?? null)) {
                continue;
            }
            if (isset($followUp['requires']) && \in_array($followUp['requires'], $justAdded, true)) {
                return $key; // follow-up of what the customer just confirmed
            }
            $candidates[] = $key;
        }

        return $candidates[0] ?? null;
    }

    public function question(string $key, string $locale): string
    {
        foreach ($this->extractor->followUps() as $followUp) {
            if ($followUp['key'] === $key) {
                return $followUp['question'][$locale] ?? $followUp['question']['fr'];
            }
        }

        return '';
    }

    /**
     * @param array<string, int> $numbers
     * @param list<string>       $added
     * @param list<string>       $removed
     */
    private function acknowledge(string $locale, ?string $sector, ?string $city, array $numbers, array $added, array $removed): string
    {
        $pieces = [];
        if (null !== $sector) {
            $pieces[] = self::lowerFirst($this->catalog->sector($sector)?->getName($locale) ?? $sector);
        }
        if (null !== $city) {
            $pieces[] = $this->translator->trans('chat.in_city', ['%city%' => $city], null, $locale);
        }
        foreach ($numbers as $key => $value) {
            $pieces[] = $this->translator->trans('chat.number.'.$key, ['%count%' => $value], null, $locale);
        }
        foreach ($added as $code) {
            $pieces[] = $this->featureName($code, $locale);
        }
        foreach ($removed as $code) {
            $pieces[] = $this->translator->trans('chat.without', ['%feature%' => $this->featureName($code, $locale)], null, $locale);
        }
        if ([] === $pieces) {
            return '';
        }

        return $this->translator->trans('chat.understood', ['%summary%' => implode(', ', array_unique($pieces))], null, $locale);
    }

    private function featureName(string $code, string $locale): string
    {
        foreach ($this->catalog->enabledSolutions() as $solution) {
            $feature = $solution->getFeature($code);
            if (null !== $feature) {
                return self::lowerFirst($feature->getName($locale));
            }
        }

        return str_replace('_', ' ', $code);
    }

    private static function lowerFirst(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
