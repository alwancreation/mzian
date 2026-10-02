<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\AI\Conversation\ConversationAssistant;
use App\Tests\Support\PlatformFixtureTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConversationAssistantTest extends KernelTestCase
{
    use PlatformFixtureTrait;

    public function testSpecificationDialogueUpdatesTheRequirement(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $assistant = static::getContainer()->get(ConversationAssistant::class);

        $conversation = $assistant->start('fr');
        $turn = $assistant->handle($conversation, "Je suis propriétaire d'une agence de location de voitures à Marrakech. J'ai 15 voitures et je veux permettre aux clients de réserver en ligne.");
        $requirement = $assistant->requirementFor($conversation);
        self::assertSame('car_rental', $requirement->getSector());
        self::assertSame('Marrakech', $requirement->getCity());
        self::assertSame(15, $requirement->getItem('fleet_size')?->getValue());
        self::assertTrue($requirement->getItem('feature.online_reservations')?->getValue());
        self::assertStringContainsString('Marrakech', $turn->reply);

        // Spec: "Je veux une gestion de contrats." -> "Voulez-vous générer automatiquement les contrats PDF ?"
        $turn = $assistant->handle($conversation, 'Je veux une gestion de contrats.');
        self::assertStringContainsString('Voulez-vous générer automatiquement les contrats PDF ?', $turn->reply);
        self::assertSame('pdf_contracts', $turn->nextQuestionKey);

        // "Oui." -> "Voulez-vous une signature électronique ?"
        $turn = $assistant->handle($conversation, 'Oui.');
        self::assertTrue($requirement->getItem('feature.pdf_contracts')?->getValue());
        self::assertStringContainsString('Voulez-vous une signature électronique ?', $turn->reply);

        // "Non." -> requirement updated with a refusal.
        $turn = $assistant->handle($conversation, 'Non.');
        self::assertFalse($requirement->getItem('feature.e_signature')?->getValue());
        self::assertTrue($requirement->getItem('feature.contracts')?->getValue());
        self::assertCount(9, $conversation->getMessages(), 'greeting + 4 user + 4 assistant messages');
    }

    public function testUnknownSectorTriggersTheSectorQuestionAndInvalidCodesAreIgnored(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $ai = $this->useScriptedAi();
        $ai->script[] = [
            'reply' => 'Quel est votre secteur ?', 'sector' => 'casino', 'business_name' => null, 'city' => null,
            'facts' => [], 'features_add' => ['online_payments', 'hack_the_planet'], 'features_remove' => [], 'next_question_key' => 'sector', 'ready' => true,
        ];
        $assistant = static::getContainer()->get(ConversationAssistant::class);
        $conversation = $assistant->start('fr');
        $turn = $assistant->handle($conversation, 'Bonjour, je veux un site');

        $requirement = $assistant->requirementFor($conversation);
        self::assertNull($requirement->getSector(), 'Unknown sector codes from the AI are ignored');
        self::assertTrue($requirement->getItem('feature.online_payments')?->getValue());
        self::assertNull($requirement->getItem('feature.hack_the_planet'));
        self::assertFalse($turn->ready, 'Never ready without a sector');
    }
}
