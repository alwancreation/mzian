<?php

declare(strict_types=1);

namespace App\Tests\Functional\Customer;

use App\Customer\Entity\Customer;
use App\Delivery\CredentialService;
use App\Shared\Repository\AuditLogRepository;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountTest extends WebTestCase
{
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testEveryCustomerPageRenders(): void
    {
        $this->loginAs($this->factory()->customer()->getUser());

        foreach (['/fr/compte', '/fr/compte/projets', '/fr/compte/commandes', '/fr/compte/factures', '/fr/compte/domaines', '/fr/compte/hebergement', '/fr/compte/notifications', '/fr/compte/support', '/fr/compte/profil', '/en/account', '/ar/account/profile'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testProfileCanBeUpdated(): void
    {
        $customer = $this->factory()->customer();
        $this->loginAs($customer->getUser());

        $crawler = $this->client->request('GET', '/en/account/profile');
        $this->client->submit($crawler->filter('form[name="profile_form"]')->form([
            'profile_form[companyName]' => 'Atlas Cars SARL',
            'profile_form[city]' => 'Marrakech',
            'profile_form[locale]' => 'ar',
        ]));

        self::assertResponseRedirects('/ar/account/profile');
        $reloaded = $this->em()->getRepository(Customer::class)->find($customer->getId());
        self::assertSame('Marrakech', $reloaded?->getCity());
        self::assertSame('ar', $reloaded->getUser()->getLocale());
    }

    public function testApiTokenIsShownOnceAndStoredHashed(): void
    {
        $customer = $this->factory()->customer();
        $this->loginAs($customer->getUser());

        $crawler = $this->client->request('GET', '/en/account/profile');
        $form = $crawler->filter('button[name="create_token"]')->form(['token_name' => 'zapier']);
        $crawler = $this->client->submit($form);

        $clear = trim($crawler->filter('.alert-success code')->text());
        self::assertStringStartsWith('mzn_', $clear);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$clear]);
        self::assertResponseIsSuccessful();
    }

    public function testCredentialRevealRequiresOwnershipCsrfAndIsAudited(): void
    {
        $factory = $this->factory();
        $owner = $factory->customer('owner@example.com');
        $project = $factory->project($owner);
        $credential = static::getContainer()->get(CredentialService::class)->store($project, 'admin', 'Application admin', 'admin@atlas.test', 's3cret-P@ss');
        $this->em()->flush();
        $url = \sprintf('/en/account/projects/%s/credentials/%d', $project->getReference(), $credential->getId());

        // Not the owner.
        $this->loginAs($factory->customer('intruder@example.com')->getUser());
        $this->client->request('POST', $url, ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);

        // Owner without CSRF token.
        $this->loginAs($owner->getUser());
        $this->client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        // Owner with the form.
        $crawler = $this->client->request('GET', '/en/account/projects/'.$project->getReference());
        self::assertStringNotContainsString('s3cret-P@ss', (string) $this->client->getResponse()->getContent());
        $this->client->submit($crawler->selectButton('Show password')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('code', 's3cret-P@ss');

        $log = static::getContainer()->get(AuditLogRepository::class)->findOneBy(['action' => 'credential.revealed']);
        self::assertNotNull($log);
        self::assertStringNotContainsString('s3cret-P@ss', (string) json_encode([$log->getMetadata(), $log->getNewValue(), $log->getOldValue()]));
    }
}
