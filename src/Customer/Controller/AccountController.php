<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Billing\Entity\Invoice;
use App\Billing\Repository\InvoiceRepository;
use App\Billing\Repository\SubscriptionRepository;
use App\Customer\Dto\ProfileData;
use App\Customer\Entity\Customer;
use App\Customer\Form\ChangePasswordFormType;
use App\Customer\Form\ProfileFormType;
use App\Delivery\CredentialService;
use App\Domain\Repository\DomainRepository;
use App\Hosting\Repository\HostingAccountRepository;
use App\Notification\Enum\NotificationType;
use App\Notification\NotificationService;
use App\Notification\Repository\NotificationRepository;
use App\Order\Repository\OrderRepository;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectCredential;
use App\Project\Enum\ProjectStatus;
use App\Project\Repository\ProjectRepository;
use App\Security\Entity\ApiToken;
use App\Security\Entity\User;
use App\Security\Repository\ApiTokenRepository;
use App\Security\UserManager;
use App\Security\Voter\CustomerResourceVoter;
use App\Security\Voter\ProjectVoter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Controller\CsrfGuardTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Customer area: dashboard, projects, orders, invoices, domains, hosting,
 * notifications, support and profile.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class AccountController extends AbstractController
{
    use CsrfGuardTrait;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(['fr' => '/fr/compte', 'en' => '/en/account', 'ar' => '/ar/account'], name: 'account_dashboard', methods: ['GET'])]
    public function dashboard(OrderRepository $orders, NotificationRepository $notifications): Response
    {
        $customer = $this->customer();
        $projects = $this->projects->findForCustomer($customer);

        return $this->render('account/dashboard.html.twig', [
            'projects' => $projects,
            'active_projects' => array_filter($projects, static fn (Project $p) => !$p->getStatus()->isTerminal()),
            'pending_orders' => array_filter($orders->findForCustomer($customer), static fn ($o) => !$o->isPaid() && 'pending_payment' === $o->getStatus()->value),
            'unread_notifications' => $notifications->countUnread($this->user()),
        ]);
    }

    #[Route(['fr' => '/fr/compte/projets', 'en' => '/en/account/projects', 'ar' => '/ar/account/projects'], name: 'account_projects', methods: ['GET'])]
    public function projects(): Response
    {
        return $this->render('account/projects.html.twig', ['projects' => $this->projects->findForCustomer($this->customer())]);
    }

    #[Route(['fr' => '/fr/compte/projets/{reference}', 'en' => '/en/account/projects/{reference}', 'ar' => '/ar/account/projects/{reference}'], name: 'account_project', methods: ['GET'])]
    public function project(#[MapEntity(mapping: ['reference' => 'reference'])] Project $project): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        return $this->render('account/project.html.twig', ['project' => $project]);
    }

    /**
     * Lightweight JSON used by the page to refresh itself while agents work.
     */
    #[Route(['fr' => '/fr/compte/projets/{reference}/status.json', 'en' => '/en/account/projects/{reference}/status.json', 'ar' => '/ar/account/projects/{reference}/status.json'], name: 'account_project_status', methods: ['GET'])]
    public function projectStatus(#[MapEntity(mapping: ['reference' => 'reference'])] Project $project): JsonResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        return new JsonResponse([
            'status' => $project->getStatus()->value,
            'progress' => $project->getProgress(),
            'terminal' => $project->getStatus()->isTerminal() || ProjectStatus::Failed === $project->getStatus(),
        ]);
    }

    #[Route(['fr' => '/fr/compte/projets/{reference}/acces/{id}', 'en' => '/en/account/projects/{reference}/credentials/{id}', 'ar' => '/ar/account/projects/{reference}/credentials/{id}'], name: 'account_credential_reveal', methods: ['POST'])]
    public function revealCredential(
        Request $request,
        #[MapEntity(mapping: ['reference' => 'reference'])] Project $project,
        #[MapEntity(id: 'id')] ProjectCredential $credential,
        CredentialService $credentials,
        RateLimiterFactoryInterface $credentialRevealLimiter,
    ): Response {
        $this->denyAccessUnlessGranted(ProjectVoter::REVEAL_CREDENTIALS, $project);
        if ($credential->getProject() !== $project) {
            throw $this->createNotFoundException();
        }
        $this->denyUnlessCsrfValid('reveal-credential-'.$credential->getId(), $request);
        if (!$credentialRevealLimiter->create('user-'.$this->user()->getId())->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('error.too_many_requests'));

            return $this->redirectToRoute('account_project', ['reference' => $project->getReference()]);
        }

        $response = $this->render('account/project.html.twig', [
            'project' => $project,
            'revealed' => [$credential->getId() => $credentials->reveal($credential)],
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route(['fr' => '/fr/compte/commandes', 'en' => '/en/account/orders', 'ar' => '/ar/account/orders'], name: 'account_orders', methods: ['GET'])]
    public function orders(OrderRepository $orders, SubscriptionRepository $subscriptions): Response
    {
        return $this->render('account/orders.html.twig', [
            'orders' => $orders->findForCustomer($this->customer()),
            'subscriptions' => $subscriptions->findBy(['customer' => $this->customer()], ['id' => 'DESC']),
        ]);
    }

    #[Route(['fr' => '/fr/compte/factures', 'en' => '/en/account/invoices', 'ar' => '/ar/account/invoices'], name: 'account_invoices', methods: ['GET'])]
    public function invoices(InvoiceRepository $invoices): Response
    {
        return $this->render('account/invoices.html.twig', ['invoices' => $invoices->findForCustomer($this->customer())]);
    }

    #[Route(['fr' => '/fr/compte/factures/{number}', 'en' => '/en/account/invoices/{number}', 'ar' => '/ar/account/invoices/{number}'], name: 'account_invoice', methods: ['GET'])]
    public function invoice(#[MapEntity(mapping: ['number' => 'number'])] Invoice $invoice): Response
    {
        $this->denyAccessUnlessGranted(CustomerResourceVoter::VIEW, $invoice);

        return $this->render('account/invoice.html.twig', ['invoice' => $invoice]);
    }

    #[Route(['fr' => '/fr/compte/domaines', 'en' => '/en/account/domains', 'ar' => '/ar/account/domains'], name: 'account_domains', methods: ['GET'])]
    public function domains(DomainRepository $domains): Response
    {
        return $this->render('account/domains.html.twig', ['domains' => $domains->findForCustomer($this->customer())]);
    }

    #[Route(['fr' => '/fr/compte/hebergement', 'en' => '/en/account/hosting', 'ar' => '/ar/account/hosting'], name: 'account_hosting', methods: ['GET'])]
    public function hosting(HostingAccountRepository $hosting): Response
    {
        return $this->render('account/hosting.html.twig', ['accounts' => $hosting->findForCustomer($this->customer())]);
    }

    #[Route(['fr' => '/fr/compte/notifications', 'en' => '/en/account/notifications', 'ar' => '/ar/account/notifications'], name: 'account_notifications', methods: ['GET', 'POST'])]
    public function notifications(Request $request, NotificationRepository $notifications): Response
    {
        $items = $notifications->findForUser($this->user());
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('notifications-read', $request->getPayload()->getString('_token'))) {
            foreach ($items as $notification) {
                $notification->markRead();
            }
            $this->em->flush();

            return $this->redirectToRoute('account_notifications');
        }

        return $this->render('account/notifications.html.twig', ['notifications' => $items]);
    }

    #[Route(['fr' => '/fr/compte/support', 'en' => '/en/account/support', 'ar' => '/ar/account/support'], name: 'account_support', methods: ['GET', 'POST'])]
    public function support(Request $request, NotificationService $notifications): Response
    {
        $customer = $this->customer();
        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid('support', $request);
            $message = trim($request->getPayload()->getString('message'));
            $reference = $request->getPayload()->getString('project');
            if (mb_strlen($message) < 10 || mb_strlen($message) > 3000) {
                $this->addFlash('error', $this->translator->trans('support.invalid'));
            } else {
                $notifications->notifyAdmins(NotificationType::SupportRequest, [
                    'customer' => $customer->getDisplayName(),
                    'email' => $customer->getEmail(),
                    'project' => $reference ?: '—',
                    'message' => mb_substr($message, 0, 1500),
                ]);
                $this->addFlash('success', $this->translator->trans('support.sent'));

                return $this->redirectToRoute('account_support');
            }
        }

        return $this->render('account/support.html.twig', ['projects' => $this->projects->findForCustomer($customer)]);
    }

    #[Route(['fr' => '/fr/compte/profil', 'en' => '/en/account/profile', 'ar' => '/ar/account/profile'], name: 'account_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, UserManager $userManager, ApiTokenRepository $tokens, AuditLogger $audit): Response
    {
        $user = $this->user();
        $customer = $this->customer();

        $data = new ProfileData();
        $data->firstName = $user->getFirstName();
        $data->lastName = $user->getLastName();
        $data->companyName = $customer->getCompanyName();
        $data->phone = $customer->getPhone();
        $data->city = $customer->getCity();
        $data->locale = $user->getLocale();

        $profileForm = $this->createForm(ProfileFormType::class, $data);
        $profileForm->handleRequest($request);
        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $user->setFirstName((string) $data->firstName);
            $user->setLastName($data->lastName);
            $user->setLocale($data->locale);
            $customer->setCompanyName($data->companyName);
            $customer->setPhone($data->phone);
            $customer->setCity($data->city);
            $audit->log('customer.profile_updated', $customer);
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('profile.saved'));

            return $this->redirectToRoute('account_profile', ['_locale' => $data->locale]);
        }

        $passwordForm = $this->createForm(ChangePasswordFormType::class);
        $passwordForm->handleRequest($request);
        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $userManager->changePassword($user, (string) $passwordForm->get('newPassword')->getData());
            $this->addFlash('success', $this->translator->trans('profile.password.changed'));

            return $this->redirectToRoute('account_profile');
        }

        $newToken = null;
        if ($request->isMethod('POST') && $request->request->has('create_token')) {
            $this->denyUnlessCsrfValid('api-token', $request);
            $name = mb_substr(trim($request->getPayload()->getString('token_name')) ?: 'API', 0, 100);
            [, $newToken] = $userManager->createApiToken($user, $name, new \DateTimeImmutable('+1 year'));
        }
        if ($request->isMethod('POST') && $request->request->has('revoke_token')) {
            $token = $tokens->find($request->getPayload()->getInt('revoke_token'));
            if ($token instanceof ApiToken && $token->getUser() === $user && $this->isCsrfTokenValid('api-token', $request->getPayload()->getString('_token'))) {
                $userManager->revokeApiToken($token);
            }

            return $this->redirectToRoute('account_profile');
        }

        $response = $this->render('account/profile.html.twig', [
            'profile_form' => $profileForm,
            'password_form' => $passwordForm,
            'tokens' => $tokens->findBy(['user' => $user, 'revokedAt' => null], ['id' => 'DESC']),
            'new_token' => $newToken,
        ], new Response(status: ($profileForm->isSubmitted() || $passwordForm->isSubmitted()) ? 422 : 200));
        if (null !== $newToken) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function customer(): Customer
    {
        return $this->user()->getCustomer() ?? throw $this->createAccessDeniedException('No customer profile.');
    }
}
