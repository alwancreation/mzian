<?php

declare(strict_types=1);

namespace App\Requirement\Form;

use App\Requirement\Dto\LeadData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LeadData>
 */
final class LeadFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, ['label' => 'form.full_name', 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => 'form.email', 'attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => 'form.phone', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('acceptPrivacy', CheckboxType::class, ['label' => 'start.contact.accept_privacy'])
            ->add('marketingConsent', CheckboxType::class, ['label' => 'start.contact.marketing', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LeadData::class, 'csrf_token_id' => 'requirement_lead']);
    }
}
