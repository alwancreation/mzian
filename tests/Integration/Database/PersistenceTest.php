<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Catalog\Entity\Solution;
use App\Catalog\Entity\SolutionFeature;
use App\Catalog\Enum\SolutionCategory;
use App\Customer\Entity\Customer;
use App\Order\Entity\Order;
use App\Order\Entity\Quote;
use App\Order\Entity\QuoteItem;
use App\Pricing\PriceLineType;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectCostEntry;
use App\Project\Entity\ProjectTask;
use App\Project\Enum\CostCategory;
use App\Project\Enum\PipelineStep;
use App\Project\Enum\ProjectStatus;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\RequirementItemSource;
use App\Security\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAFullProjectGraphRoundTrips(): void
    {
        $user = new User('owner@riad.ma', 'Amina', 'El Idrissi');
        $user->setPassword('hash');
        $customer = new Customer($user);
        $customer->setCompanyName('Riad Amina');

        $solution = new Solution('hotel_website', 'site-hotel', ['fr' => 'Site hôtel', 'en' => 'Hotel website'], SolutionCategory::Booking, 30000, 6, 'booking');
        new SolutionFeature($solution, 'online_booking', ['fr' => 'Réservation en ligne'], true);

        $requirement = new Requirement('fr');
        $requirement->setSector('hotel');
        $requirement->setAnswer('rooms_count', 8);
        $requirement->upsertItem('online_booking', 'Réservation en ligne', true, RequirementItemSource::Questionnaire);
        $requirement->upsertItem('online_booking', 'Réservation en ligne', false, RequirementItemSource::Conversation, 0.9);

        $project = new Project('PRJ-2026-0001', 'mzian-client-1', 'Riad Amina', $requirement);
        $project->setCustomer($customer);
        $project->setSolution($solution);
        $project->setBudget(15000);
        new ProjectTask($project, PipelineStep::Hosting);
        new ProjectCostEntry($project, CostCategory::Hosting, 4000, 'USD', 'Hosting 1 year', 'mock-123', 'project-1-hosting', true);

        $quote = new Quote('Q-2026-00001', $project, $requirement, $solution, 'USD', 18700, 23700, ['online_booking'], new \DateTimeImmutable('+30 days'));
        new QuoteItem($quote, 'solution', 'Site hôtel', PriceLineType::Development, 12000, 18500, false, true, 0);
        new QuoteItem($quote, 'margin', 'Margin', PriceLineType::Margin, 0, 0, false, false, 1);
        $quote->accept();
        $order = new Order('MZ-2026-00001', $customer, $quote);

        foreach ([$user, $customer, $solution, $requirement, $project, $quote, $order] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Project::class)->findOneBy(['reference' => 'PRJ-2026-0001']);
        self::assertNotNull($reloaded);
        self::assertSame(ProjectStatus::Draft, $reloaded->getStatus());
        self::assertSame('Riad Amina', $reloaded->getCustomer()?->getCompanyName());
        self::assertSame(11000, $reloaded->getRemainingBudget());
        self::assertSame(23700, $reloaded->getOrder()?->getTotal());
        self::assertCount(1, $reloaded->getOrder()->getItems(), 'Only customer-visible quote lines are copied to the order.');
        self::assertFalse($reloaded->getRequirement()->getItem('online_booking')?->getValue());
        self::assertSame(8, $reloaded->getRequirement()->getAnswers()['rooms_count']);
        self::assertSame(PipelineStep::Hosting, $reloaded->getTasks()->first()->getStep());
    }

    public function testCostEntriesAreIdempotent(): void
    {
        $project = new Project('PRJ-2026-0002', 'mzian-client-2', 'Demo', new Requirement());
        $this->em->persist($project->getRequirement());
        $this->em->persist($project);
        new ProjectCostEntry($project, CostCategory::Domain, 1200, 'USD', 'Domain', null, 'project-2-domain');
        $this->em->flush();

        new ProjectCostEntry($project, CostCategory::Domain, 1200, 'USD', 'Domain (retry)', null, 'project-2-domain');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
