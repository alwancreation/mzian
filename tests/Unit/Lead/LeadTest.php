<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lead;

use App\Lead\Entity\Lead;
use App\Lead\Enum\LeadStatus;
use PHPUnit\Framework\TestCase;

final class LeadTest extends TestCase
{
    public function testStatusOnlyMovesForwardInTheFunnel(): void
    {
        $lead = new Lead('  Owner@Example.COM ', 'Owner');
        self::assertSame('owner@example.com', $lead->getEmail());

        $lead->advanceTo(LeadStatus::Quoted);
        $lead->advanceTo(LeadStatus::Engaged);
        self::assertSame(LeadStatus::Quoted, $lead->getStatus());

        $lead->advanceTo(LeadStatus::Converted);
        self::assertSame(LeadStatus::Converted, $lead->getStatus());
    }

    public function testActivitiesAreRecorded(): void
    {
        $lead = new Lead('a@b.c', 'A');
        $lead->addActivity('created', 'Lead created', ['source' => 'google']);

        self::assertCount(1, $lead->getActivities());
        self::assertSame(['source' => 'google'], $lead->getActivities()->first()->getMetadata());
    }
}
