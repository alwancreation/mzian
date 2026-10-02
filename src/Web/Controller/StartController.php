<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Catalog\Questionnaire\InvalidAnswerException;
use App\Catalog\Questionnaire\QuestionnaireEngine;
use App\Catalog\Service\CatalogProvider;
use App\Lead\Service\LeadService;
use App\Pricing\ProposalBuilder;
use App\Requirement\Dto\DetailsData;
use App\Requirement\Dto\LeadData;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\RequirementStatus;
use App\Requirement\Form\DetailsFormType;
use App\Requirement\Form\LeadFormType;
use App\Requirement\Service\RequirementAccess;
use App\Requirement\Service\RequirementAnalysisService;
use App\Requirement\Service\RequirementService;
use App\Security\Entity\User;
use App\Shared\Controller\CsrfGuardTrait;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guided questionnaire: sector → dynamic questions → project details → contact (lead) → summary.
 * Mobile first: one question per screen, works without JavaScript.
 */
final class StartController extends AbstractController
{
    use CsrfGuardTrait;

    public function __construct(
        private readonly CatalogProvider $catalog,
        private readonly QuestionnaireEngine $questionnaire,
        private readonly RequirementService $requirements,
        private readonly RequirementAccess $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(['fr' => '/fr/demarrer', 'en' => '/en/start', 'ar' => '/ar/start'], name: 'start', methods: ['GET', 'POST'])]
    public function start(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid('start', $request);
            $sector = $this->catalog->sector($request->getPayload()->getString('sector'));
            if (null !== $sector) {
                $user = $this->getUser();
                $requirement = $this->requirements->start($sector, $request->getLocale(), $user instanceof User ? $user->getCustomer() : null);
                $this->access->grant($requirement);

                return $this->redirectToRoute('start_question', ['token' => $requirement->getToken()]);
            }
            $this->addFlash('error', $this->translator->trans('start.sector.required'));
        }

        return $this->render('start/sector.html.twig', [
            'sectors' => $this->catalog->enabledSectors(),
            'preselected' => $request->query->getString('sector'),
        ]);
    }

    #[Route(['fr' => '/fr/demarrer/{token}/questions', 'en' => '/en/start/{token}/questions', 'ar' => '/ar/start/{token}/questions'], name: 'start_question', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function question(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Requirement $requirement): Response
    {
        $this->access->denyUnlessAccessible($requirement);
        if (RequirementStatus::Draft !== $requirement->getStatus()) {
            return $this->redirectToRoute('start_summary', ['token' => $requirement->getToken()]);
        }
        $sector = $this->requirements->sector($requirement);
        $answers = $requirement->getAnswers();

        $editCode = $request->query->getString('edit');
        $question = '' !== $editCode ? $this->questionnaire->findVisible($sector, $answers, $editCode) : $this->questionnaire->nextQuestion($sector, $answers);
        $error = null;

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid('questionnaire-'.$requirement->getToken(), $request);
            $question = $this->questionnaire->findVisible($sector, $answers, $request->getPayload()->getString('question'));
            if (null !== $question) {
                $raw = $request->request->all()['answer'] ?? null;
                try {
                    $this->requirements->answer($requirement, $question, $raw);

                    return $this->redirectToRoute('start_question', ['token' => $requirement->getToken()]);
                } catch (InvalidAnswerException $e) {
                    $error = $e->translationKey;
                }
            }
        }

        if (null === $question) {
            return $this->redirectToRoute('start_details', ['token' => $requirement->getToken()]);
        }

        return $this->render('start/question.html.twig', [
            'requirement' => $requirement,
            'sector' => $sector,
            'question' => $question,
            'current' => $answers[$question->getCode()] ?? null,
            'previous' => $this->questionnaire->previousQuestion($sector, $answers, '' !== $editCode ? $question : null),
            'progress' => $this->questionnaire->progress($sector, $answers),
            'error' => $error,
        ], new Response(status: null !== $error ? 422 : 200));
    }

    #[Route(['fr' => '/fr/demarrer/{token}/projet', 'en' => '/en/start/{token}/project', 'ar' => '/ar/start/{token}/project'], name: 'start_details', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function details(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Requirement $requirement): Response
    {
        $this->access->denyUnlessAccessible($requirement);
        $data = new DetailsData();
        $data->businessName = $requirement->getBusinessName();
        $data->city = $requirement->getCity();
        $data->description = $requirement->getDescription();
        $data->desiredDomain = $requirement->getItem('desired_domain')?->getValue();

        $form = $this->createForm(DetailsFormType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->requirements->updateDetails($requirement, (string) $data->businessName, $data->city, $data->description, $data->desiredDomain);

            return $this->redirectToRoute(null !== $requirement->getLead() ? 'start_summary' : 'start_contact', ['token' => $requirement->getToken()]);
        }

        return $this->render('start/details.html.twig', [
            'requirement' => $requirement,
            'form' => $form,
            'has_domain' => 'yes' === ($requirement->getAnswers()['has_domain'] ?? null),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route(['fr' => '/fr/demarrer/{token}/contact', 'en' => '/en/start/{token}/contact', 'ar' => '/ar/start/{token}/contact'], name: 'start_contact', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function contact(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Requirement $requirement, LeadService $leads, RateLimiterFactoryInterface $leadSubmissionLimiter): Response
    {
        $this->access->denyUnlessAccessible($requirement);
        if (null === $requirement->getBusinessName()) {
            return $this->redirectToRoute('start_details', ['token' => $requirement->getToken()]);
        }

        $data = new LeadData();
        $user = $this->getUser();
        if ($user instanceof User) {
            $data->fullName = $user->getFullName();
            $data->email = $user->getEmail();
            $data->phone = $user->getCustomer()?->getPhone();
        }
        $form = $this->createForm(LeadFormType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$leadSubmissionLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError($this->translator->trans('error.too_many_requests')));
            } else {
                $lead = $leads->capture((string) $data->email, (string) $data->fullName, $data->phone, $requirement->getBusinessName(), $requirement->getSector(), $requirement->getCity(), $request->getLocale(), $request, $data->marketingConsent);
                $this->requirements->attachLead($requirement, $lead);

                return $this->redirectToRoute('start_summary', ['token' => $requirement->getToken()]);
            }
        }

        return $this->render('start/contact.html.twig', ['requirement' => $requirement, 'form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route(['fr' => '/fr/demarrer/{token}/recapitulatif', 'en' => '/en/start/{token}/summary', 'ar' => '/ar/start/{token}/summary'], name: 'start_summary', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function summary(#[MapEntity(mapping: ['token' => 'token'])] Requirement $requirement): Response
    {
        $this->access->denyUnlessAccessible($requirement);
        if (null === $requirement->getLead()) {
            return $this->redirectToRoute('start_contact', ['token' => $requirement->getToken()]);
        }
        $sector = $this->requirements->sector($requirement);
        $answered = [];
        foreach ($this->questionnaire->visibleQuestions($sector, $requirement->getAnswers()) as $question) {
            if (\array_key_exists($question->getCode(), $requirement->getAnswers())) {
                $answered[] = ['question' => $question, 'value' => $this->questionnaire->displayAnswer($question, $requirement->getAnswers()[$question->getCode()], $requirement->getLocale())];
            }
        }

        return $this->render('start/summary.html.twig', [
            'requirement' => $requirement,
            'sector' => $sector,
            'answered' => $answered,
            'features' => $this->requirements->requestedFeatures($requirement),
        ]);
    }

    #[Route(['fr' => '/fr/demarrer/{token}/analyse', 'en' => '/en/start/{token}/analyze', 'ar' => '/ar/start/{token}/analyze'], name: 'start_analyze', requirements: ['token' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function analyze(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Requirement $requirement, RequirementAnalysisService $analysis, RateLimiterFactoryInterface $aiAnalysisLimiter): Response
    {
        $this->access->denyUnlessAccessible($requirement);
        $this->denyUnlessCsrfValid('analyze-'.$requirement->getToken(), $request);
        if (null === $requirement->getLead() || null === $requirement->getSector()) {
            return $this->redirectToRoute('start_summary', ['token' => $requirement->getToken()]);
        }
        if (!$aiAnalysisLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('error.too_many_requests'));

            return $this->redirectToRoute('start_summary', ['token' => $requirement->getToken()]);
        }

        $analysis->analyze($requirement);

        return $this->redirectToRoute('start_analysis', ['token' => $requirement->getToken()]);
    }

    #[Route(['fr' => '/fr/demarrer/{token}/solution', 'en' => '/en/start/{token}/solution', 'ar' => '/ar/start/{token}/solution'], name: 'start_analysis', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function analysis(#[MapEntity(mapping: ['token' => 'token'])] Requirement $requirement, RequirementAnalysisService $analysisService, ProposalBuilder $proposals): Response
    {
        $this->access->denyUnlessAccessible($requirement);
        $analysis = $analysisService->storedAnalysis($requirement);
        if (null === $analysis) {
            return $this->redirectToRoute('start_summary', ['token' => $requirement->getToken()]);
        }
        $solution = $this->catalog->solution($analysis->solution);

        try {
            $proposal = null !== $solution ? $proposals->build($requirement, $analysis) : null;
        } catch (\DomainException) {
            $proposal = null;
        }

        return $this->render('start/analysis.html.twig', [
            'requirement' => $requirement,
            'analysis' => $analysis,
            'solution' => $solution,
            'sector' => $this->requirements->sector($requirement),
            'proposal' => $proposal,
        ]);
    }
}
