<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Lead\Enum\LeadStatus;
use App\Lead\Service\LeadService;
use App\Notification\Enum\NotificationType;
use App\Notification\NotificationService;
use App\Web\Dto\ContactData;
use App\Web\Form\ContactFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Static marketing pages (how it works, FAQ, contact, legal).
 */
final class PageController extends AbstractController
{
    private const FAQ_KEYS = ['what', 'how_long', 'price', 'ai', 'domain', 'hosting', 'changes', 'own', 'payment', 'languages'];

    #[Route(['fr' => '/fr/comment-ca-marche', 'en' => '/en/how-it-works', 'ar' => '/ar/how-it-works'], name: 'how_it_works', methods: ['GET'])]
    public function howItWorks(): Response
    {
        return $this->cached($this->render('web/how_it_works.html.twig'));
    }

    #[Route(['fr' => '/fr/faq', 'en' => '/en/faq', 'ar' => '/ar/faq'], name: 'faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->cached($this->render('web/faq.html.twig', ['faq_keys' => self::FAQ_KEYS]));
    }

    #[Route(['fr' => '/fr/mentions-legales', 'en' => '/en/terms', 'ar' => '/ar/terms'], name: 'legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->cached($this->render('web/legal/terms.html.twig'));
    }

    #[Route(['fr' => '/fr/confidentialite', 'en' => '/en/privacy', 'ar' => '/ar/privacy'], name: 'legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->cached($this->render('web/legal/privacy.html.twig'));
    }

    #[Route(['fr' => '/fr/contact', 'en' => '/en/contact', 'ar' => '/ar/contact'], name: 'contact', methods: ['GET', 'POST'])]
    public function contact(
        Request $request,
        LeadService $leadService,
        NotificationService $notifications,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        RateLimiterFactoryInterface $leadSubmissionLimiter,
    ): Response {
        $data = new ContactData();
        $form = $this->createForm(ContactFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$leadSubmissionLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError($translator->trans('error.too_many_requests')));
            } else {
                $lead = $leadService->capture((string) $data->email, (string) $data->name, $data->phone, locale: $request->getLocale(), request: $request);
                $leadService->track($lead, 'contact_message', 'Contact form message', ['message' => mb_substr((string) $data->message, 0, 3000)], LeadStatus::Engaged);
                $em->flush();
                $notifications->notifyAdmins(NotificationType::ContactReceived, [
                    'name' => $data->name,
                    'email' => $data->email,
                    'message' => mb_substr((string) $data->message, 0, 500),
                ]);
                $this->addFlash('success', $translator->trans('contact.sent'));

                return $this->redirectToRoute('contact');
            }
        }

        return $this->render('web/contact.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Public static pages can be cached by browsers/proxies for a few minutes.
     */
    private function cached(Response $response): Response
    {
        if (null === $this->getUser()) {
            $response->setPublic();
            $response->setMaxAge(300);
        }

        return $response;
    }
}
