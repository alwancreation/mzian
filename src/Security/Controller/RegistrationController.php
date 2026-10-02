<?php

declare(strict_types=1);

namespace App\Security\Controller;

use App\Security\Dto\RegistrationData;
use App\Security\Form\RegistrationFormType;
use App\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserManager $userManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $registrationLimiter,
    ) {
    }

    #[Route(['fr' => '/fr/inscription', 'en' => '/en/register', 'ar' => '/ar/register'], name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('account_dashboard');
        }

        $data = new RegistrationData();
        $data->email = $request->query->getString('email') ?: null;
        $data->firstName = $request->query->getString('name') ?: null;
        $form = $this->createForm(RegistrationFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError($this->translator->trans('error.too_many_requests')));
            } else {
                try {
                    $customer = $this->userManager->createCustomer(
                        (string) $data->email,
                        (string) $data->plainPassword,
                        (string) $data->firstName,
                        $data->lastName,
                        $data->companyName,
                        $data->phone,
                        $request->getLocale(),
                    );
                    $this->security->login($customer->getUser(), 'form_login', 'main');
                    $this->addFlash('success', $this->translator->trans('registration.success'));

                    $target = $request->query->getString('target');

                    return str_starts_with($target, '/') && !str_starts_with($target, '//')
                        ? $this->redirect($target)
                        : $this->redirectToRoute('account_dashboard');
                } catch (\DomainException) {
                    $form->get('email')->addError(new FormError($this->translator->trans('user.email.already_used', [], 'validators')));
                }
            }
        }

        return $this->render('security/register.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
