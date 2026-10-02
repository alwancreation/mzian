<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Web\Dto\ContactData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ContactData>
 */
final class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'form.full_name', 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => 'form.email', 'attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => 'form.phone', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('message', TextareaType::class, ['label' => 'form.message', 'attr' => ['rows' => 5]])
            ->add('website', TextType::class, ['required' => false, 'label' => false, 'attr' => ['class' => 'hidden', 'tabindex' => '-1', 'autocomplete' => 'off']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactData::class, 'csrf_token_id' => 'contact']);
    }
}
