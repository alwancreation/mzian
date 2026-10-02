<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\Entity\User;
use App\Security\Repository\UserRepository;
use App\Shared\Repository\AuditLogRepository;
use App\Tests\Support\Factory;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testCustomerCanLogInAndIsRedirectedToTheirArea(): void
    {
        $this->factory()->customer('karim@example.com');

        $crawler = $this->client->request('GET', '/fr/connexion');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'email' => 'karim@example.com',
            'password' => Factory::PASSWORD,
        ]));

        self::assertResponseRedirects('/fr/compte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Tableau de bord');

        $logs = static::getContainer()->get(AuditLogRepository::class)->findBy(['action' => 'security.login']);
        self::assertCount(1, $logs);
    }

    public function testAdministratorsLandOnTheAdminDashboard(): void
    {
        $this->factory()->admin('ada@mzian.test');

        $crawler = $this->client->request('GET', '/en/login');
        $this->client->submit($crawler->selectButton('Log in')->form(['email' => 'ada@mzian.test', 'password' => Factory::PASSWORD]));

        self::assertResponseRedirects('/admin');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->factory()->customer('karim@example.com');

        $crawler = $this->client->request('GET', '/en/login');
        $this->client->submit($crawler->selectButton('Log in')->form(['email' => 'karim@example.com', 'password' => 'wrong-password']));
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-error');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken()?->getUser());
    }

    public function testLoginWithoutCsrfTokenIsRejected(): void
    {
        $this->factory()->customer('karim@example.com');

        $this->client->request('POST', '/fr/connexion', ['email' => 'karim@example.com', 'password' => Factory::PASSWORD]);
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-error');
        $this->client->request('GET', '/fr/compte');
        self::assertResponseRedirects();
    }

    public function testDisabledAccountCannotLogIn(): void
    {
        $customer = $this->factory()->customer('karim@example.com');
        $customer->getUser()->setActive(false);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/fr/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['email' => 'karim@example.com', 'password' => Factory::PASSWORD]));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-error', 'désactivé');
    }

    public function testRegistrationCreatesACustomerWithHashedPassword(): void
    {
        $crawler = $this->client->request('GET', '/fr/inscription');
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration_form[firstName]' => 'Salma',
            'registration_form[email]' => 'Salma@Example.com',
            'registration_form[plainPassword]' => 'a-very-long-password',
            'registration_form[companyName]' => 'Salon Salma',
            'registration_form[acceptTerms]' => '1',
        ]));

        self::assertResponseRedirects('/fr/compte');
        /** @var User $user */
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'salma@example.com']);
        self::assertNotNull($user->getCustomer());
        self::assertSame('Salon Salma', $user->getCustomer()->getCompanyName());
        self::assertContains('ROLE_CUSTOMER', $user->getRoles());
        self::assertNotSame('a-very-long-password', $user->getPassword());
        self::assertTrue(password_verify('a-very-long-password', $user->getPassword()));
    }

    public function testRegistrationRejectsDuplicateEmailAndWeakPassword(): void
    {
        $this->factory()->customer('taken@example.com');

        $crawler = $this->client->request('GET', '/en/register');
        $this->client->submit($crawler->selectButton('Create my account')->form([
            'registration_form[firstName]' => 'X',
            'registration_form[email]' => 'taken@example.com',
            'registration_form[plainPassword]' => 'a-very-long-password',
            'registration_form[acceptTerms]' => '1',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'already exists');

        $crawler = $this->client->request('GET', '/en/register');
        $this->client->submit($crawler->selectButton('Create my account')->form([
            'registration_form[firstName]' => 'X',
            'registration_form[email]' => 'new@example.com',
            'registration_form[plainPassword]' => 'short',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', '10 characters');
    }

    public function testLogoutRequiresAValidCsrfToken(): void
    {
        $customer = $this->factory()->customer();
        $this->loginAs($customer->getUser());

        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/fr/compte');
        self::assertResponseIsSuccessful('A logout link without CSRF token must not log the user out.');
    }
}
