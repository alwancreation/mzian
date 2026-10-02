<?php

declare(strict_types=1);

namespace App\Notification\Enum;

enum NotificationType: string
{
    case OrderReceived = 'order_received';
    case PaymentConfirmed = 'payment_confirmed';
    case ApprovalRequired = 'approval_required';
    case ProjectStarted = 'project_started';
    case ChangesRequested = 'changes_requested';
    case ProjectRejected = 'project_rejected';
    case DevelopmentCompleted = 'development_completed';
    case TestingCompleted = 'testing_completed';
    case ProjectDeployed = 'project_deployed';
    case ProjectDelivered = 'project_delivered';
    case AdminAttentionRequired = 'admin_attention_required';
    case Welcome = 'welcome';

    public function isForAdmins(): bool
    {
        return \in_array($this, [self::ApprovalRequired, self::AdminAttentionRequired], true);
    }
}
