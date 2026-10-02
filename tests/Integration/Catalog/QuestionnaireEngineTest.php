<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Admin\Controller\CatalogAdminController;
use App\Catalog\Questionnaire\InvalidAnswerException;
use App\Catalog\Questionnaire\QuestionnaireEngine;
use App\Catalog\Service\CatalogProvider;
use App\Tests\Support\CatalogFixtureTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class QuestionnaireEngineTest extends KernelTestCase
{
    use CatalogFixtureTrait;

    private QuestionnaireEngine $engine;
    private CatalogProvider $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->importCatalog();
        $this->engine = static::getContainer()->get(QuestionnaireEngine::class);
        $this->catalog = static::getContainer()->get(CatalogProvider::class);
    }

    public function testQuestionsDependOnTheSectorAndPreviousAnswers(): void
    {
        $carRental = $this->catalog->sector('car_rental');
        $codes = static fn (array $questions) => array_map(static fn ($q) => $q->getCode(), $questions);

        $visible = $codes($this->engine->visibleQuestions($carRental, []));
        self::assertContains('fleet_size', $visible);
        self::assertContains('contracts', $visible);
        self::assertNotContains('pdf_contracts', $visible, 'Conditional question hidden until contracts = yes');
        self::assertNotContains('domain_name', $visible);
        self::assertNotContains('menu_online', $visible, 'Restaurant questions are not asked to car rental agencies');

        $visible = $codes($this->engine->visibleQuestions($carRental, ['contracts' => 'yes', 'has_domain' => 'yes']));
        self::assertContains('pdf_contracts', $visible);
        self::assertContains('domain_name', $visible);

        $visible = $codes($this->engine->visibleQuestions($carRental, ['contracts' => 'no']));
        self::assertNotContains('pdf_contracts', $visible);
    }

    public function testNextQuestionAndProgress(): void
    {
        $restaurant = $this->catalog->sector('restaurant');
        self::assertSame('goal', $this->engine->nextQuestion($restaurant, [])?->getCode());
        self::assertSame('menu_online', $this->engine->nextQuestion($restaurant, ['goal' => ['present']])?->getCode());

        $progress = $this->engine->progress($restaurant, ['goal' => ['present']]);
        self::assertSame(1, $progress['answered']);
        self::assertGreaterThan(1, $progress['total']);
    }

    public function testAnswersAreValidatedAndNormalized(): void
    {
        $carRental = $this->catalog->sector('car_rental');
        $find = fn (string $code) => $this->engine->findVisible($carRental, ['contracts' => 'yes'], $code);

        self::assertSame(15, $this->engine->normalizeAnswer($find('fleet_size'), '15'));
        self::assertSame('yes', $this->engine->normalizeAnswer($find('contracts'), true));
        self::assertSame(['present', 'receive_bookings'], $this->engine->normalizeAnswer($find('goal'), ['present', 'receive_bookings', 'present']));

        $this->expectException(InvalidAnswerException::class);
        $this->engine->normalizeAnswer($find('contracts'), 'maybe');
    }

    public function testInvalidNumbersAreRejected(): void
    {
        $question = $this->engine->findVisible($this->catalog->sector('car_rental'), [], 'fleet_size');
        foreach (['-1', 'abc', '2.5', ''] as $invalid) {
            try {
                $this->engine->normalizeAnswer($question, $invalid);
                self::fail('Expected rejection of '.$invalid);
            } catch (InvalidAnswerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFeaturesAreDerivedFromAnswers(): void
    {
        $features = $this->engine->featuresFrom($this->catalog->sector('car_rental'), [
            'goal' => ['receive_bookings'],
            'contracts' => 'yes',
            'pdf_contracts' => 'yes',
            'vehicle_tracking' => 'no',
            'languages' => 'several',
        ]);
        sort($features);

        self::assertSame(['contracts', 'multilingual', 'online_reservations', 'pdf_contracts'], $features);
    }

    public function testConditionLinter(): void
    {
        self::assertNull(CatalogAdminController::lintCondition("answers['contracts'] == 'yes'"));
        self::assertNull(CatalogAdminController::lintCondition("has('online_payments') and answers['x'] != 'no'"));
        self::assertNotNull(CatalogAdminController::lintCondition("system('rm -rf /')"));
        self::assertNotNull(CatalogAdminController::lintCondition("answers['x'] =="));
    }
}
