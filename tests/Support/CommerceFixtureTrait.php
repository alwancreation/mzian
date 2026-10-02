<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Analysis\RequirementAnalysis;
use App\Customer\Entity\Customer;
use App\Order\Entity\Order;
use App\Order\Entity\Quote;
use App\Order\Service\OrderService;
use App\Order\Service\QuoteService;
use App\Requirement\Entity\Requirement;
use App\Security\Entity\User;
use App\Shared\Security\Actor;
use App\Shared\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quotes and orders built through the real services (requires PlatformFixtureTrait::setUpPlatform()).
 */
trait CommerceFixtureTrait
{
    protected function issueQuote(?Customer $customer = null, string $businessName = 'Atlas Cars'): Quote
    {
        $requirement = new Requirement('fr');
        $requirement->setSector('car_rental');
        $requirement->setBusinessName($businessName);
        $requirement->setCity('Marrakech');
        $requirement->setAnswer('has_domain', 'no');
        $requirement->setCustomer($customer);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($requirement);
        $em->flush();

        $analysis = new RequirementAnalysis(
            'web_application',
            'car_rental_management',
            ['website', 'vehicle_management', 'customer_management', 'online_reservations', 'contracts', 'pdf_contracts'],
            'medium',
            8,
            ['storage_gb' => 20, 'database' => true, 'ssl' => true, 'email_accounts' => 3],
            true,
            'Recommendation',
            0.9,
        );

        return static::getContainer()->get(QuoteService::class)->issue($requirement, $analysis);
    }

    protected function placeOrder(Customer $customer, ?string $subscription = null, ?Quote $quote = null): Order
    {
        $quote ??= $this->issueQuote($customer);

        return $this->as(self::customerActor($customer), fn () => static::getContainer()->get(OrderService::class)->place($quote, $customer, $subscription));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    protected function as(Actor $actor, callable $callback): mixed
    {
        return static::getContainer()->get(CurrentActor::class)->runAs($actor, $callback);
    }

    protected static function customerActor(Customer $customer): Actor
    {
        return Actor::customer((int) $customer->getUser()->getId(), $customer->getUser()->getFullName());
    }

    protected static function adminActor(User $admin): Actor
    {
        return Actor::admin((int) $admin->getId(), $admin->getFullName());
    }
}
