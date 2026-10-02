<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * RBAC: anonymous, customers and administrators each see only what they are allowed to.
 */
final class AccessControlTest extends WebTestCase
{
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/en/login');

        $this->client->request('GET', '/fr/compte/projets');
        self::assertResponseRedirects('/fr/connexion');
    }

    public function testCustomersCannotReachTheAdminArea(): void
    {
        $this->loginAs($this->factory()->customer()->getUser());

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/list/customers');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminsCanReachEveryAdminList(): void
    {
        $this->loginAs($this->factory()->admin());

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        foreach (['customers', 'leads', 'requirements', 'orders', 'payments', 'invoices', 'hosting', 'domains', 'deployments', 'tests', 'notifications', 'logs', 'agent-runs'] as $section) {
            $this->client->request('GET', '/admin/list/'.$section);
            self::assertResponseIsSuccessful($section);
        }
        $this->client->request('GET', '/admin/list/unknown');
        self::assertResponseStatusCodeSame(404);
    }

    public function testACustomerCannotSeeAnotherCustomersProject(): void
    {
        $factory = $this->factory();
        $owner = $factory->customer('owner@example.com');
        $other = $factory->customer('other@example.com');
        $project = $factory->project($owner);

        $this->loginAs($other->getUser());
        $this->client->request('GET', '/fr/compte/projets/'.$project->getReference());
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($owner->getUser());
        $this->client->request('GET', '/fr/compte/projets/'.$project->getReference());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Atlas Cars');
    }
}
