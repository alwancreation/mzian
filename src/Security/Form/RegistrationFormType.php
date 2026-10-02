<?php

declare(strict_types=1);

namespace App\Security\Form;

use App\Security\Dto\RegistrationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RegistrationData>
 */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'form.first_name', 'attr' => ['autocomplete' => 'given-name']])
            ->add('lastName', TextType::class, ['label' => 'form.last_name', 'required' => false, 'attr' => ['autocomplete' => 'family-name']])
            ->add('email', EmailType::class, ['label' => 'form.email', 'attr' => ['autocomplete' => 'email']])
            ->add('plainPassword', PasswordType::class, ['label' => 'form.password', 'help' => 'registration.password.help', 'attr' => ['autocomplete' => 'new-password']])
            ->add('companyName', TextType::class, ['label' => 'form.company', 'required' => false, 'attr' => ['autocomplete' => 'organization']])
            ->add('phone', TelType::class, ['label' => 'form.phone', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('acceptTerms', CheckboxType::class, ['label' => 'registration.accept_terms', 'required' => true]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegistrationData::class, 'csrf_token_id' => 'registration']);
    }
}
