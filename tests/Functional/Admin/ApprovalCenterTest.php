<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Service\PaymentService;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Provider\ProviderRegistry;
use App\Security\UserManager;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\PlatformFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApprovalCenterTest extends WebTestCase
{
    use CommerceFixtureTrait;
    use PlatformFixtureTrait;
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setUpPlatform();
    }

    private function paidProject(): Project
    {
        return $this->payOrder($this->placeOrder($this->factory()->customer('karim@atlas.ma')))->getProject();
    }

    private function reload(Project $project): Project
    {
        return $this->em()->getRepository(Project::class)->findOneBy(['reference' => $project->getReference()]) ?? throw new \LogicException();
    }

    public function testAdminReviewsAndApprovesAPaidProject(): void
    {
        $project = $this->paidProject();
        $this->loginAs($this->factory()->admin());

        $crawler = $this->client->request('GET', '/admin/approvals');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="pending-approvals"]', $project->getReference());

        $crawler = $this->client->request('GET', '/admin/projects/'.$project->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Atlas Cars');
        self::assertSelectorTextContains('[data-testid="money"]', 'Margin');
        self::assertSelectorTextContains('main', 'Decision required');
        self::assertSelectorTextContains('main', 'Gestion de location de voitures');

        $this->client->request('POST', '/admin/projects/'.$project->getId().'/decision/approve', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ProjectStatus::PendingAdminApproval, $this->reload($project)->getStatus());

        $this->client->submit($crawler->selectButton('✅ Approve')->form(['message' => 'OK for me']));
        self::assertResponseRedirects('/admin/projects/'.$project->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="project-status"]', 'APPROVED');
        self::assertSelectorTextContains('[data-testid="timeline"]', 'PENDING_ADMIN_APPROVAL → APPROVED');
        self::assertSelectorTextContains('[data-testid="timeline"]', 'admin: Ada Admin');
    }

    public function testRequestChangesRoundTripWithTheCustomer(): void
    {
        $project = $this->paidProject();
        $this->loginAs($this->factory()->admin());
        $crawler = $this->client->request('GET', '/admin/projects/'.$project->getId());
        $this->client->submit($crawler->selectButton('✏️ Request changes')->form(['message' => 'Do you need English too?']));
        self::assertSame(ProjectStatus::ChangesRequested, $this->reload($project)->getStatus());

        $this->loginAs($project->getCustomer()->getUser());
        $crawler = $this->client->request('GET', '/fr/compte/projets/'.$project->getReference());
        self::assertSelectorTextContains('[data-testid="changes-requested"]', 'Do you need English too?');
        $this->client->submit($crawler->selectButton('Envoyer ma réponse')->form(['message' => 'Oui, français et anglais.']));
        self::assertResponseRedirects('/fr/compte/projets/'.$project->getReference());
        self::assertSame(ProjectStatus::PendingAdminApproval, $this->reload($project)->getStatus());
    }

    public function testAdminConfirmsABankTransfer(): void
    {
        static::getContainer()->get(ProviderRegistry::class)->findByCode('bank_transfer')?->setEnabled(true);
        $this->em()->flush();
        $order = $this->placeOrder($this->factory()->customer('cash@example.com'));
        $payment = static::getContainer()->get(PaymentService::class)->start($order, 'bank_transfer', new CheckoutUrls('https://mzian.test/ok', 'https://mzian.test/ko'));

        $this->loginAs($order->getCustomer()->getUser());
        $this->client->request('GET', '/fr/compte/commandes/'.$order->getNumber());
        self::assertSelectorTextContains('[data-testid="bank-instructions"]', $order->getNumber());

        $this->loginAs($this->factory()->admin());
        $this->client->request('GET', '/admin/approvals');
        self::assertSelectorTextContains('main', $order->getNumber());
        $crawler = $this->client->request('GET', '/admin/projects/'.$order->getProject()->getId());
        $this->client->submit($crawler->selectButton('Confirm transfer received')->form(['note' => 'BMCE ref 42']));
        self::assertResponseRedirects();
        self::assertSame(ProjectStatus::PendingAdminApproval, $this->reload($order->getProject())->getStatus());
        self::assertNotNull($payment->getId());
    }

    public function testAdminApiDecisions(): void
    {
        $project = $this->paidProject();
        $manager = static::getContainer()->get(UserManager::class);
        $adminToken = $manager->createApiToken($this->factory()->admin(), 'ops')[1];
        $customerToken = $manager->createApiToken($project->getCustomer()->getUser(), 'mine')[1];
        $call = function (string $method, string $uri, string $token, ?array $body = null): array {
            $this->client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], content: null !== $body ? (string) json_encode($body) : null);

            return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        };

        $call('GET', '/api/v1/admin/approvals', $customerToken);
        self::assertResponseStatusCodeSame(403);
        $call('POST', '/api/v1/admin/projects/'.$project->getReference().'/approve', $customerToken);
        self::assertResponseStatusCodeSame(403);

        $list = $call('GET', '/api/v1/admin/approvals', $adminToken);
        self::assertSame($project->getReference(), $list['pending'][0]['reference']);
        self::assertArrayHasKey('margin', $list['pending'][0]['money']);

        $call('POST', '/api/v1/admin/projects/'.$project->getReference().'/reject', $adminToken, ['message' => '']);
        self::assertResponseStatusCodeSame(422);

        $approved = $call('POST', '/api/v1/admin/projects/'.$project->getReference().'/approve', $adminToken, ['message' => 'via API']);
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', $approved['status']);

        $call('POST', '/api/v1/admin/projects/'.$project->getReference().'/approve', $adminToken);
        self::assertResponseStatusCodeSame(409, 'Approving twice is refused by the state machine.');
    }
}
