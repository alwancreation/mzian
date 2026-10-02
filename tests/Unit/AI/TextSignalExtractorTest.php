<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI;

use App\AI\Analysis\TextSignalExtractor;
use PHPUnit\Framework\TestCase;

final class TextSignalExtractorTest extends TestCase
{
    private TextSignalExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TextSignalExtractor(\dirname(__DIR__, 3).'/config/mzian/ai/conversation.yaml');
    }

    public function testFrenchCarRentalRequestFromTheSpecification(): void
    {
        $signals = $this->extractor->extract("Je suis propriétaire d'une agence de location de voitures à Marrakech. J'ai 15 voitures et je veux permettre aux clients de réserver en ligne.");

        self::assertSame('car_rental', $signals->sector);
        self::assertSame('Marrakech', $signals->city);
        self::assertSame(['fleet_size' => 15], $signals->numbers);
        self::assertSame(['online_reservations'], $signals->wanted());
    }

    public function testNegationsAreUnderstood(): void
    {
        $signals = $this->extractor->extract('Je veux des contrats PDF mais pas de signature électronique, et sans paiement en ligne.');

        self::assertEqualsCanonicalizing(['contracts', 'pdf_contracts'], $signals->wanted());
        self::assertEqualsCanonicalizing(['e_signature', 'online_payments'], $signals->refused());
    }

    public function testEnglishAndArabic(): void
    {
        $english = $this->extractor->extract('We run a riad in Fes with 8 rooms and need online booking.');
        self::assertSame('hotel', $english->sector);
        self::assertSame(8, $english->numbers['rooms_count']);

        $arabic = $this->extractor->extract('لدي مطعم في الرباط وأريد قائمة الطعام والحجز');
        self::assertSame('restaurant', $arabic->sector);
        self::assertSame('الرباط', $arabic->city);
        self::assertContains('menu', $arabic->wanted());
    }

    public function testWholeWordMatchingForLatinKeywords(): void
    {
        // "api" (integrations) must not match inside "rapide".
        self::assertSame([], $this->extractor->extract('Un site rapide et joli')->wanted());
    }

    public function testYesNoAnswers(): void
    {
        self::assertTrue($this->extractor->yesNo('Oui.'));
        self::assertTrue($this->extractor->yesNo('yes please'));
        self::assertTrue($this->extractor->yesNo('نعم'));
        self::assertFalse($this->extractor->yesNo('Non merci'));
        self::assertFalse($this->extractor->yesNo('لا'));
        self::assertNull($this->extractor->yesNo('Je ne sais pas encore'));
    }
}
