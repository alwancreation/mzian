<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait WebTestCaseTrait
{
    protected KernelBrowser $client;

    protected function factory(): Factory
    {
        $container = static::getContainer();

        return new Factory($container->get(EntityManagerInterface::class), $container->get(UserPasswordHasherInterface::class));
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function loginAs(User $user): void
    {
        $this->client->loginUser($user, 'main');
    }
}
