<?php

declare(strict_types=1);

namespace App\Admin\ListView;

use App\Agent\Entity\AgentRun;
use App\Billing\Entity\Invoice;
use App\Billing\Entity\Payment;
use App\Billing\Enum\PaymentStatus;
use App\Customer\Entity\Customer;
use App\Deployment\Entity\Deployment;
use App\Domain\Entity\Domain;
use App\Hosting\Entity\HostingAccount;
use App\Lead\Entity\Lead;
use App\Lead\Enum\LeadStatus;
use App\Notification\Entity\Notification;
use App\Order\Entity\Order;
use App\Order\Enum\OrderStatus;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\RequirementStatus;
use App\Shared\Entity\AuditLog;
use App\Testing\Entity\TestRun;

/**
 * Declarative configuration of the read-only admin lists.
 */
final class AdminListRegistry
{
    /** @var array<string, ListDefinition>|null */
    private ?array $definitions = null;

    public function get(string $section): ?ListDefinition
    {
        return $this->all()[$section] ?? null;
    }

    /**
     * @return array<string, ListDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= [
            'customers' => new ListDefinition('Customers', Customer::class, [
                new Column('#', 'id'),
                new Column('Name', 'displayName'),
                new Column('E-mail', 'email'),
                new Column('Phone', 'phone'),
                new Column('City', 'city'),
                new Column('Sector', 'sector'),
                new Column('Since', 'createdAt', 'date'),
            ], ['e.companyName', 'e.phone', 'e.city']),
            'leads' => new ListDefinition('Leads', Lead::class, [
                new Column('#', 'id'),
                new Column('Name', 'fullName'),
                new Column('E-mail', 'email'),
                new Column('Company', 'companyName'),
                new Column('Sector', 'sector'),
                new Column('Source', 'source', 'badge'),
                new Column('Status', 'status', 'badge'),
                new Column('Created', 'createdAt', 'datetime'),
            ], ['e.email', 'e.fullName', 'e.companyName'], array_map(static fn (LeadStatus $s) => $s->value, LeadStatus::cases()), rowRoute: 'admin_lead'),
            'requirements' => new ListDefinition('Requirements', Requirement::class, [
                new Column('#', 'id'),
                new Column('Sector', 'sector'),
                new Column('Business', 'businessName'),
                new Column('Lead', 'lead.email'),
                new Column('Recommended', 'recommendedSolution.code', 'code'),
                new Column('Status', 'status', 'badge'),
                new Column('Updated', 'updatedAt', 'datetime'),
            ], ['e.sector', 'e.businessName', 'e.description'], array_map(static fn (RequirementStatus $s) => $s->value, RequirementStatus::cases())),
            'orders' => new ListDefinition('Orders', Order::class, [
                new Column('Number', 'number', 'code'),
                new Column('Customer', 'customer.displayName'),
                new Column('Project', 'project.reference', 'code'),
                new Column('Total', 'total', 'money', 'currency'),
                new Column('Cost', 'costPrice', 'money', 'currency'),
                new Column('Margin', 'margin', 'money', 'currency'),
                new Column('Status', 'status', 'badge'),
                new Column('Created', 'createdAt', 'datetime'),
            ], ['e.number'], array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases()), rowRoute: 'admin_project', rowRouteProperty: 'project.id'),
            'payments' => new ListDefinition('Payments', Payment::class, [
                new Column('#', 'id'),
                new Column('Order', 'order.number', 'code'),
                new Column('Provider', 'provider', 'badge'),
                new Column('Reference', 'providerReference', 'code'),
                new Column('Amount', 'amount', 'money', 'currency'),
                new Column('Status', 'status', 'badge'),
                new Column('Paid at', 'paidAt', 'datetime'),
            ], ['e.providerReference'], array_map(static fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()), rowRoute: 'admin_project', rowRouteProperty: 'order.project.id'),
            'invoices' => new ListDefinition('Invoices', Invoice::class, [
                new Column('Number', 'number', 'code'),
                new Column('Customer', 'customer.displayName'),
                new Column('Total', 'total', 'money', 'currency'),
                new Column('Status', 'status', 'badge'),
                new Column('Issued', 'issuedAt', 'date'),
            ], ['e.number']),
            'hosting' => new ListDefinition('Hosting accounts', HostingAccount::class, [
                new Column('#', 'id'),
                new Column('Project', 'project.reference', 'code'),
                new Column('Provider', 'provider', 'badge'),
                new Column('Plan', 'plan.name'),
                new Column('External id', 'externalId', 'code'),
                new Column('Cost', 'cost', 'money', 'currency'),
                new Column('Status', 'status', 'badge'),
                new Column('Simulated', 'simulated', 'bool'),
            ], ['e.externalId', 'e.provider']),
            'domains' => new ListDefinition('Domains', Domain::class, [
                new Column('Domain', 'name', 'code'),
                new Column('Project', 'project.reference', 'code'),
                new Column('Provider', 'provider', 'badge'),
                new Column('Cost', 'cost', 'money', 'currency'),
                new Column('Status', 'status', 'badge'),
                new Column('Expires', 'expiresAt', 'date'),
                new Column('Simulated', 'simulated', 'bool'),
            ], ['e.name']),
            'deployments' => new ListDefinition('Deployments', Deployment::class, [
                new Column('#', 'id'),
                new Column('Project', 'project.reference', 'code'),
                new Column('Provider', 'provider', 'badge'),
                new Column('Version', 'version', 'code'),
                new Column('URL', 'url'),
                new Column('Status', 'status', 'badge'),
                new Column('Created', 'createdAt', 'datetime'),
            ], ['e.url', 'e.version']),
            'tests' => new ListDefinition('Test runs', TestRun::class, [
                new Column('#', 'id'),
                new Column('Project', 'project.reference', 'code'),
                new Column('Type', 'type', 'badge'),
                new Column('Target', 'target'),
                new Column('Score', 'score'),
                new Column('Status', 'status', 'badge'),
                new Column('Started', 'startedAt', 'datetime'),
            ], ['e.target'], ['running', 'passed', 'failed'], rowRoute: 'admin_test_run'),
            'notifications' => new ListDefinition('Notifications', Notification::class, [
                new Column('#', 'id'),
                new Column('Recipient', 'recipientEmail'),
                new Column('Type', 'type', 'badge'),
                new Column('Subject', 'subject'),
                new Column('Status', 'status', 'badge'),
                new Column('Created', 'createdAt', 'datetime'),
            ], ['e.recipientEmail', 'e.subject'], ['pending', 'sent', 'failed']),
            'logs' => new ListDefinition('Audit logs', AuditLog::class, [
                new Column('When', 'createdAt', 'datetime'),
                new Column('Actor', 'actorType', 'badge'),
                new Column('Name', 'actorName'),
                new Column('Action', 'action', 'code'),
                new Column('Entity', 'entityType'),
                new Column('Id', 'entityId'),
                new Column('Message', 'message'),
                new Column('IP', 'ip'),
            ], ['e.action', 'e.actorName', 'e.entityType', 'e.entityId'], rowRoute: 'admin_log', description: 'Every important action: who, what, when, entity, old/new values, IP.'),
            'agent-runs' => new ListDefinition('Agent runs', AgentRun::class, [
                new Column('#', 'id'),
                new Column('Agent', 'agentCode', 'badge'),
                new Column('Project', 'project.reference', 'code'),
                new Column('Attempt', 'attempt'),
                new Column('Status', 'status', 'badge'),
                new Column('Duration (ms)', 'durationMs'),
                new Column('Cost', 'cost', 'money'),
                new Column('Started', 'startedAt', 'datetime'),
            ], ['e.agentCode'], ['running', 'succeeded', 'failed', 'retrying', 'waiting_admin'], rowRoute: 'admin_agent_run'),
        ];
    }
}
