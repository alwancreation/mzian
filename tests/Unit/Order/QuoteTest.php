<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use App\Catalog\Entity\Solution;
use App\Catalog\Enum\SolutionCategory;
use App\Order\Entity\Quote;
use App\Order\Enum\QuoteStatus;
use App\Project\Entity\Project;
use App\Requirement\Entity\Requirement;
use PHPUnit\Framework\TestCase;

final class QuoteTest extends TestCase
{
    private function quote(int $cost, int $price, string $validUntil = '+30 days'): Quote
    {
        $requirement = new Requirement();
        $project = new Project('PRJ-1', 'mzian-client-1', 'Demo', $requirement);
        $solution = new Solution('website_starter', 'site-vitrine', ['fr' => 'Site vitrine'], SolutionCategory::Website, 10000, 3, 'website-starter');

        return new Quote('Q-1', $project, $requirement, $solution, 'USD', $cost, $price, [], new \DateTimeImmutable($validUntil));
    }

    public function testMarginIsDerivedFromCostAndSellingPrice(): void
    {
        $quote = $this->quote(18700, 23700);

        self::assertSame(5000, $quote->getMargin());
        self::assertSame(21.1, $quote->getMarginPercentage());
    }

    public function testAcceptingAnExpiredQuoteIsRefused(): void
    {
        $quote = $this->quote(100, 200, '-1 day');

        self::assertTrue($quote->isExpired());
        $this->expectException(\LogicException::class);
        $quote->accept();
    }

    public function testAQuoteCanOnlyBeAcceptedOnce(): void
    {
        $quote = $this->quote(100, 200);
        $quote->accept();
        self::assertSame(QuoteStatus::Accepted, $quote->getStatus());

        $this->expectException(\LogicException::class);
        $quote->accept();
    }
}
