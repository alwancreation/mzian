<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Entity\Question;
use App\Catalog\Entity\Solution;
use App\Catalog\Import\CatalogImporter;
use App\Catalog\Service\CatalogProvider;
use App\Shared\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogImporterTest extends KernelTestCase
{
    public function testImportIsIdempotentAndKeepsAdminEditsUnlessUpdating(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $importer = $container->get(CatalogImporter::class);
        $em = $container->get(EntityManagerInterface::class);

        $first = $importer->import();
        self::assertSame(11, $first['solutions']);
        self::assertSame(11, $first['sectors']);
        self::assertGreaterThan(40, $first['questions']);
        self::assertSame(3, $first['plans']);

        // Prices come from data, in minor units.
        $solution = $em->getRepository(Solution::class)->findOneBy(['code' => 'car_rental_management']);
        self::assertSame(32000, $solution->getBasePrice());
        self::assertSame(6, $solution->getEstimatedDevelopmentDays());
        self::assertTrue($solution->getFeature('contracts')?->isIncluded());
        self::assertSame(4000, $solution->getFeature('pdf_contracts')?->getPrice());

        // An admin changes a price: a plain re-import keeps it...
        $solution->setBasePrice(35000);
        $em->flush();
        $second = $importer->import();
        self::assertSame(0, $second['solutions'] + $second['features'] + $second['questions'] + $second['plans']);
        $em->refresh($solution);
        self::assertSame(35000, $solution->getBasePrice());
        self::assertCount(11, $em->getRepository(Solution::class)->findAll());

        // ...while --update restores the YAML value.
        $importer->import(update: true);
        $em->refresh($solution);
        self::assertSame(32000, $solution->getBasePrice());

        $rules = $container->get(SettingsService::class)->get('analysis')['solution_rules'];
        self::assertSame('website_starter', end($rules)['solution']);
    }

    public function testBooleanQuestionsGetYesNoOptionsWithFeatures(): void
    {
        self::bootKernel();
        static::getContainer()->get(CatalogImporter::class)->import();

        $question = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Question::class)->findOneBy(['code' => 'pdf_contracts']);
        self::assertSame(['pdf_contracts'], $question->featuresFor('yes'));
        self::assertSame([], $question->featuresFor('no'));
        self::assertSame("answers['contracts'] == 'yes'", $question->getCondition());
        self::assertSame('Voulez-vous générer automatiquement les contrats PDF ?', $question->getLabel('fr'));

        $provider = static::getContainer()->get(CatalogProvider::class);
        self::assertSame('car_rental_management', $provider->solutionBySlug('gestion-location-voiture', 'fr')?->getCode());
        self::assertSame('car_rental', $provider->sectorBySlug('car-rental', 'en')?->getCode());
    }
}
