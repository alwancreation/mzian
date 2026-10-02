<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\AI\Analysis\RequirementAnalysis;
use App\Catalog\Service\CatalogProvider;
use App\Pricing\PriceLine;
use App\Pricing\Proposal;
use App\Pricing\ProposalBuilder;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\RequirementItemSource;
use App\Shared\Settings\SettingsService;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProposalBuilderTest extends KernelTestCase
{
    use PlatformFixtureTrait;

    private ProposalBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $this->builder = static::getContainer()->get(ProposalBuilder::class);
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function requirement(array $answers = ['has_domain' => 'no'], ?string $desiredDomain = null): Requirement
    {
        $requirement = new Requirement('fr');
        $requirement->setSector('car_rental');
        $requirement->setBusinessName('Atlas Cars');
        $requirement->setCity('Marrakech');
        foreach ($answers as $code => $value) {
            $requirement->setAnswer($code, $value);
        }
        if (isset($answers['domain_name'])) {
            $requirement->upsertItem('domain_name', 'Domain', $answers['domain_name'], RequirementItemSource::Questionnaire);
        }
        if (null !== $desiredDomain) {
            $requirement->upsertItem('desired_domain', 'Desired domain', $desiredDomain, RequirementItemSource::Questionnaire);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($requirement);
        $em->flush();

        return $requirement;
    }

    /**
     * @param list<string> $features
     * @param list<string> $unsupported
     */
    private function analysis(array $features = ['website', 'vehicle_management', 'customer_management', 'contracts', 'pdf_contracts', 'statistics'], array $unsupported = [], string $complexity = 'medium'): RequirementAnalysis
    {
        return new RequirementAnalysis('web_application', 'car_rental_management', $features, $complexity, 8, ['storage_gb' => 20, 'database' => true, 'ssl' => true, 'email_accounts' => 5], true, 'Recommendation', 0.9, $unsupported);
    }

    public function testCarRentalProposalIsPricedFromCatalogHostingAndDomain(): void
    {
        $proposal = $this->builder->build($this->requirement(), $this->analysis());
        $b = $proposal->breakdown;

        // 20 GB needed: the 10 GB starter plan is skipped, the business plan ($72/year) is chosen.
        self::assertSame('business', $proposal->hostingPlan?->getCode());
        self::assertSame(Proposal::DOMAIN_REGISTER, $proposal->domainMode);
        self::assertSame('atlas-cars.com', $proposal->domain);
        self::assertSame('business', $proposal->subscriptionPlan?->getCode(), 'Cheapest plan covering the $29 maintenance.');

        // development 320 x 1.25 + options 90 + hosting 72 + domain 12 + infra 10 + AI 5 = 589, fees 2.9% + 0.30,
        // margin max(50, 80) → 689.30 rounded up to 690 (margin 80.69 after fees).
        self::assertSame(60931, $b->costPrice);
        self::assertSame(69000, $b->sellingPrice);
        self::assertSame(8069, $b->margin);
        self::assertSame(2900, $b->recurringMonthly);

        $customerTotal = array_sum(array_map(static fn (PriceLine $l) => $l->price, $b->customerLines()));
        self::assertSame($b->sellingPrice, $customerTotal, 'Customer lines add up to the price.');
        self::assertSame('Hébergement 1re année — Business (30 GB, 3 DB, 20 e-mails)', $b->line('hosting')?->label);
        self::assertSame('Nom de domaine atlas-cars.com (1 an)', $b->line('domain')?->label);
        self::assertSame('Contrats PDF automatiques', $b->line('option.pdf_contracts')?->label);
        self::assertNull($b->line('email'), 'E-mail accounts included in the hosting plan are not charged twice.');
        foreach (['infrastructure', 'ai', 'payment_fees', 'margin'] as $internal) {
            self::assertFalse($b->line($internal)?->customerVisible, $internal.' is internal.');
        }
        self::assertSame((int) ceil(60931 * 1.15), $proposal->getBudget());
        self::assertSame([], $proposal->warnings);
    }

    public function testExistingDomainIsNotCharged(): void
    {
        $proposal = $this->builder->build($this->requirement(['has_domain' => 'yes', 'domain_name' => 'atlas-cars.ma']), $this->analysis());

        self::assertSame(Proposal::DOMAIN_OWN, $proposal->domainMode);
        self::assertSame('atlas-cars.ma', $proposal->domain);
        self::assertNull($proposal->breakdown->line('domain'));
        self::assertSame(0, $proposal->breakdown->costOf(\App\Pricing\PriceLineType::Domain));
    }

    public function testTakenDomainGetsAnAvailableAlternative(): void
    {
        $proposal = $this->builder->build($this->requirement(desiredDomain: 'google.com'), $this->analysis());

        self::assertSame(Proposal::DOMAIN_REGISTER, $proposal->domainMode);
        self::assertSame('atlas-cars.com', $proposal->domain);
        self::assertTrue($proposal->isAlternativeDomain());
        self::assertContains('pricing.warning.domain_taken', $proposal->warnings);
    }

    public function testAvailableDesiredDomainIsUsedWithItsTldPrice(): void
    {
        $proposal = $this->builder->build($this->requirement(desiredDomain: 'atlas-location.ma'), $this->analysis());

        self::assertSame('atlas-location.ma', $proposal->domain);
        self::assertFalse($proposal->isAlternativeDomain());
        self::assertSame(2500, $proposal->breakdown->line('domain')?->cost, '.ma costs $25 at the mock registrar.');
    }

    public function testCustomFeaturesArePricedAndFlagged(): void
    {
        $proposal = $this->builder->build($this->requirement(), $this->analysis(unsupported: ['fleet_gps_api']));

        $line = $proposal->breakdown->line('custom.fleet_gps_api');
        self::assertNotNull($line);
        self::assertSame(8000, $line->price);
        self::assertSame('Développement sur mesure : Fleet gps api', $line->label);
        self::assertContains('pricing.warning.custom_features', $proposal->warnings);
    }

    public function testAdminPolicyChangesApplyImmediatelyAndMinimumMarginHolds(): void
    {
        $settings = static::getContainer()->get(SettingsService::class);
        $settings->set('pricing', ['minimum_margin' => 300, 'target_margin' => 300] + $settings->get('pricing'));

        $proposal = $this->builder->build($this->requirement(), $this->analysis());

        self::assertGreaterThanOrEqual(30000, $proposal->breakdown->margin);
        self::assertSame(30000, $proposal->policy->minimumMargin);
    }

    public function testExplicitSubscriptionChoiceAndNone(): void
    {
        self::assertSame('pro', $this->builder->build($this->requirement(), $this->analysis(), 'pro')->subscriptionPlan?->getCode());

        $none = $this->builder->build($this->requirement(), $this->analysis(), 'none');
        self::assertNull($none->subscriptionPlan);
        self::assertSame(0, $none->breakdown->recurringMonthly);
    }

    public function testEveryCatalogSolutionHasAStartingPriceAboveCostWithTheMinimumMargin(): void
    {
        $solutions = static::getContainer()->get(CatalogProvider::class)->enabledSolutions();
        self::assertCount(11, $solutions);
        $minimum = $this->builder->policy()->minimumMargin;

        foreach ($solutions as $solution) {
            $breakdown = $this->builder->startingPrice($solution);
            self::assertNotNull($breakdown->line('hosting'), $solution->getCode().' has a hosting plan.');
            self::assertGreaterThanOrEqual($minimum, $breakdown->margin, $solution->getCode());
            self::assertSame($breakdown->sellingPrice - $breakdown->costPrice, $breakdown->margin);
        }
        $prices = $this->builder->startingPrices($solutions);
        self::assertLessThan($prices['custom_web_application'], $prices['website_starter']);
    }
}
