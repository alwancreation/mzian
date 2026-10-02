<?php

declare(strict_types=1);

namespace App\Customer\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'form.current_password',
                'constraints' => [new UserPassword(message: 'profile.password.wrong')],
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'form.new_password',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 10, max: 4096, minMessage: 'registration.password.too_short'), new Assert\NotCompromisedPassword(skipOnError: true)],
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'change_password']);
    }
}
