<?php

declare(strict_types=1);

namespace App\Project\Workflow;

use App\Project\Enum\ProjectStatus as S;
use App\Shared\Enum\ActorType as A;

/**
 * Single source of truth of the project state machine. config/packages/workflow.php
 * builds the Symfony Workflow from it and ActorGuardListener enforces who may
 * apply each transition.
 *
 * Key rules:
 *  - only a human administrator approves, rejects, resumes or retries a project;
 *  - agents can only move a project along the automation pipeline, put it on
 *    hold (WAITING_ADMIN_APPROVAL) or mark it failed;
 *  - every transition is recorded (ProjectEvent + AuditLog) by ProjectStateMachine.
 */
final class ProjectWorkflowDefinition
{
    public const NAME = 'project';

    /**
     * @return array<string, array{from: list<S>, to: S, actors: list<A>, label: string}>
     */
    public static function transitions(): array
    {
        $staff = [A::Admin];
        $automationActors = [A::Agent, A::System, A::Admin];
        $presale = [A::Customer, A::Visitor, A::System, A::Admin];

        $transitions = [
            // Pre-sale (customer side)
            'analyze' => [[S::Draft, S::Quoted], S::Analyzing, $presale, 'Requirement analysis started'],
            'quote' => [[S::Analyzing], S::Quoted, $presale, 'Quote issued'],
            'order' => [[S::Quoted], S::Ordered, [A::Customer, A::Admin], 'Order placed'],
            'pay' => [[S::Ordered], S::Paid, [A::Webhook, A::System, A::Admin], 'Payment received'],
            'submit_for_approval' => [[S::Paid], S::PendingAdminApproval, [A::Webhook, A::System, A::Admin], 'Waiting for the administrator approval'],
            'abandon' => [[S::Draft, S::Analyzing, S::Quoted, S::Ordered], S::Cancelled, [A::Customer, A::Visitor, A::System, A::Admin], 'Abandoned before payment'],

            // Mandatory human approval
            'approve' => [[S::PendingAdminApproval], S::Approved, $staff, 'Approved by an administrator'],
            'request_changes' => [[S::PendingAdminApproval], S::ChangesRequested, $staff, 'Changes requested by an administrator'],
            'resubmit' => [[S::ChangesRequested], S::PendingAdminApproval, [A::Customer, A::Admin], 'Changes submitted for approval'],
            'reject' => [[S::PendingAdminApproval, S::ChangesRequested], S::Cancelled, $staff, 'Rejected by an administrator'],

            // Automation pipeline (agents)
            'start_provisioning' => [[S::Approved], S::Provisioning, $automationActors, 'Hosting provisioning started'],
            'hosting_ready' => [[S::Provisioning], S::HostingReady, $automationActors, 'Hosting ready'],
            'domain_ready' => [[S::HostingReady], S::DomainReady, $automationActors, 'Domain ready'],
            'start_development' => [[S::DomainReady], S::Development, $automationActors, 'Development started'],
            'start_testing' => [[S::Development], S::Testing, $automationActors, 'Automated tests started'],
            'start_deployment' => [[S::Testing], S::Deploying, $automationActors, 'Deployment started'],
            'deployed' => [[S::Deploying], S::Deployed, $automationActors, 'Application deployed'],
            'start_qa' => [[S::Deployed], S::Qa, $automationActors, 'Quality checks started'],
            'start_delivery' => [[S::Qa], S::Delivery, $automationActors, 'Delivery started'],
            'complete' => [[S::Delivery], S::Completed, $automationActors, 'Project delivered'],

            // Exceptions
            'hold' => [S::automationStates(), S::WaitingAdminApproval, $automationActors, 'Waiting for an administrator'],
            'fail' => [S::automationStates(), S::Failed, [A::Agent, A::System], 'Failed'],
            'cancel' => [[S::Paid, S::WaitingAdminApproval, S::Failed, ...S::automationStates()], S::Cancelled, $staff, 'Cancelled by an administrator'],
        ];

        // An administrator resumes a held project, or retries a failed one, at a given step.
        foreach (S::automationStates() as $state) {
            $transitions[self::resumeTransition($state)] = [[S::WaitingAdminApproval], $state, $staff, 'Resumed by an administrator'];
            $transitions[self::retryTransition($state)] = [[S::Failed], $state, $staff, 'Retried by an administrator'];
        }

        return array_map(
            static fn (array $t) => ['from' => array_values($t[0]), 'to' => $t[1], 'actors' => $t[2], 'label' => $t[3]],
            $transitions,
        );
    }

    public static function resumeTransition(S $to): string
    {
        return 'resume_to_'.strtolower($to->value);
    }

    public static function retryTransition(S $to): string
    {
        return 'retry_to_'.strtolower($to->value);
    }

    /**
     * Framework configuration of the workflow (used by config/packages/workflow.php).
     *
     * @return array<string, mixed>
     */
    public static function frameworkConfig(string $subjectClass): array
    {
        $transitions = [];
        foreach (self::transitions() as $name => $transition) {
            $transitions[$name] = [
                'from' => array_map(static fn (S $s) => $s->value, $transition['from']),
                'to' => $transition['to']->value,
                'metadata' => [
                    'title' => $transition['label'],
                    'actors' => array_map(static fn (A $a) => $a->value, $transition['actors']),
                ],
            ];
        }

        return [
            'type' => 'state_machine',
            'audit_trail' => ['enabled' => true],
            'marking_store' => ['type' => 'method', 'property' => 'status'],
            'supports' => [$subjectClass],
            'initial_marking' => [S::Draft->value],
            'places' => array_map(static fn (S $s) => $s->value, S::cases()),
            'transitions' => $transitions,
        ];
    }
}
