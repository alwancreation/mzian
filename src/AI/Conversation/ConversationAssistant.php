<?php

declare(strict_types=1);

namespace App\AI\Conversation;

use App\AI\AIGateway;
use App\AI\Analysis\PromptBuilder;
use App\AI\Analysis\TextSignalExtractor;
use App\AI\Dto\AIMessage;
use App\AI\Dto\AIRequest;
use App\AI\Exception\AIException;
use App\AI\Schema\JsonSchemaValidator;
use App\Catalog\Service\CatalogProvider;
use App\Customer\Entity\Customer;
use App\Provider\Exception\ProviderException;
use App\Requirement\Entity\Conversation;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\MessageRole;
use App\Requirement\Enum\RequirementItemSource;
use App\Requirement\Repository\RequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Describe your project" chat: every customer message updates the structured
 * Requirement (sector, features wanted/refused, facts) and the assistant asks the
 * next useful question. Works with any AI provider; falls back to the
 * deterministic responder if the provider fails.
 */
final readonly class ConversationAssistant
{
    public const TASK = 'conversation_turn';
    public const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private AIGateway $gateway,
        private PromptBuilder $prompts,
        private JsonSchemaValidator $schemas,
        private TextSignalExtractor $extractor,
        private MockConversationResponder $fallback,
        private CatalogProvider $catalog,
        private RequirementRepository $requirements,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private LoggerInterface $aiLogger,
    ) {
    }

    public function start(string $locale, ?Customer $customer = null, ?string $sector = null): Conversation
    {
        $conversation = new Conversation($locale);
        $conversation->setCustomer($customer);
        $requirement = new Requirement($locale);
        $requirement->setConversation($conversation);
        $requirement->setCustomer($customer);
        if (null !== $customer) {
            $requirement->setBusinessName($customer->getCompanyName());
            $requirement->setCity($customer->getCity());
        }
        if (null !== $sector && null !== $this->catalog->sector($sector)) {
            $requirement->setSector($sector);
            $conversation->setSector($sector);
        }
        $conversation->addMessage(MessageRole::Assistant, $this->translator->trans('chat.greeting', [], null, $locale), ['engine' => 'template']);
        $conversation->setContext(['asked' => [], 'pending_question' => null, 'ready' => false, 'user_messages' => 0]);

        $this->em->persist($conversation);
        $this->em->persist($requirement);
        $this->em->flush();

        return $conversation;
    }

    public function requirementFor(Conversation $conversation): Requirement
    {
        return $this->requirements->findOneBy(['conversation' => $conversation]) ?? throw new \LogicException('Conversation without requirement.');
    }

    public function handle(Conversation $conversation, string $message): ConversationTurn
    {
        $message = mb_substr(trim($message), 0, self::MAX_MESSAGE_LENGTH);
        if ('' === $message) {
            throw new \InvalidArgumentException('Empty message.');
        }
        $requirement = $this->requirementFor($conversation);
        $context = $conversation->getContext();
        $state = [
            'locale' => $conversation->getLocale(),
            'sector' => $requirement->getSector(),
            'features' => $this->featureStates($requirement),
            'asked' => (array) ($context['asked'] ?? []),
            'pending_question' => $context['pending_question'] ?? null,
            'user_messages' => (int) ($context['user_messages'] ?? 0),
        ];
        $conversation->addMessage(MessageRole::User, $message);

        $engine = 'rules';
        $fallbackUsed = false;
        $usage = [];
        try {
            $response = $this->gateway->complete(new AIRequest(
                self::TASK,
                $this->prompts->conversationSystemPrompt($conversation->getLocale(), $this->extractor->followUps(), $this->catalog->knownFeatureCodes(), $this->extractor->knownSectorCodes()),
                array_map(static fn (array $m) => new AIMessage('assistant' === $m['role'] ? AIMessage::ASSISTANT : AIMessage::USER, $m['content']), $conversation->transcript(20)),
                $this->schemas->schema(self::TASK),
                'conversation_turn',
                700,
                0.3,
                ['state' => $state, 'message' => $message],
            ));
            $data = (array) $response->data;
            $engine = $response->simulated ? 'rules' : 'ai:'.$response->provider;
            $usage = $response->usage();
        } catch (AIException|ProviderException $e) {
            $this->aiLogger->warning('Conversation turn fell back to the deterministic responder', ['error' => $e->getMessage()]);
            $data = $this->fallback->respond($state, $message);
            $fallbackUsed = true;
        }

        $this->apply($conversation, $requirement, $data);
        $reply = mb_substr((string) $data['reply'], 0, 1500);
        $conversation->addMessage(MessageRole::Assistant, $reply, ['engine' => $engine, 'fallback' => $fallbackUsed, 'usage' => $usage]);
        $this->em->flush();

        return new ConversationTurn($reply, (bool) ($conversation->getContext()['ready'] ?? false), $data['next_question_key'] ?? null, $engine, $fallbackUsed);
    }

    /**
     * Applies a (schema-valid) assistant turn, keeping only catalog-known codes.
     *
     * @param array<string, mixed> $data
     */
    private function apply(Conversation $conversation, Requirement $requirement, array $data): void
    {
        $locale = $conversation->getLocale();
        $sector = \is_string($data['sector'] ?? null) ? $this->catalog->sector($data['sector']) : null;
        if (null !== $sector && $requirement->getSector() !== $sector->getCode()) {
            $requirement->setSector($sector->getCode());
            $conversation->setSector($sector->getCode());
            $requirement->upsertItem('sector', 'Sector', $sector->getCode(), RequirementItemSource::Conversation, 0.8);
        }
        if (\is_string($data['business_name'] ?? null) && '' !== trim($data['business_name']) && null === $requirement->getBusinessName()) {
            $requirement->setBusinessName(mb_substr(trim($data['business_name']), 0, 160));
        }
        if (\is_string($data['city'] ?? null) && '' !== trim($data['city'])) {
            $requirement->setCity(mb_substr(trim($data['city']), 0, 120));
            $requirement->upsertItem('city', 'City', $requirement->getCity(), RequirementItemSource::Conversation, 0.8);
        }
        foreach ((array) ($data['facts'] ?? []) as $fact) {
            $key = (string) ($fact['key'] ?? '');
            if (preg_match('/^[a-z0-9_]{2,60}$/', $key) && !str_starts_with($key, 'feature')) {
                $value = (string) ($fact['value'] ?? '');
                $requirement->upsertItem($key, $this->translator->trans('chat.fact.'.$key, [], null, $locale), ctype_digit($value) ? (int) $value : mb_substr($value, 0, 300), RequirementItemSource::Conversation, 0.8);
            }
        }
        $known = $this->catalog->knownFeatureCodes();
        foreach ([true => (array) ($data['features_add'] ?? []), false => (array) ($data['features_remove'] ?? [])] as $wanted => $codes) {
            foreach ($codes as $code) {
                if (\in_array($code, $known, true)) {
                    $requirement->upsertItem('feature.'.$code, $this->featureLabel((string) $code, $locale), (bool) $wanted, RequirementItemSource::Conversation, 0.9);
                }
            }
        }

        $context = $conversation->getContext();
        $next = $data['next_question_key'] ?? null;
        $asked = (array) ($context['asked'] ?? []);
        if (\is_string($next) && '' !== $next) {
            $asked[] = $next;
        }
        $conversation->setContext([
            'asked' => array_values($asked),
            'pending_question' => \is_string($next) ? $next : null,
            'ready' => (bool) ($data['ready'] ?? false) && null !== $requirement->getSector(),
            'user_messages' => (int) ($context['user_messages'] ?? 0) + 1,
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function featureStates(Requirement $requirement): array
    {
        $states = [];
        foreach ($requirement->getItems() as $item) {
            if (str_starts_with($item->getKey(), 'feature.')) {
                $states[substr($item->getKey(), 8)] = true === $item->getValue();
            }
        }

        return $states;
    }

    private function featureLabel(string $code, string $locale): string
    {
        foreach ($this->catalog->enabledSolutions() as $solution) {
            if (null !== $feature = $solution->getFeature($code)) {
                return $feature->getName($locale);
            }
        }

        return $code;
    }
}
