<?php

declare(strict_types=1);

namespace App\Customer\Form;

use App\Customer\Dto\ProfileData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProfileData>
 */
final class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'form.first_name'])
            ->add('lastName', TextType::class, ['label' => 'form.last_name', 'required' => false])
            ->add('companyName', TextType::class, ['label' => 'form.company', 'required' => false])
            ->add('phone', TelType::class, ['label' => 'form.phone', 'required' => false])
            ->add('city', TextType::class, ['label' => 'form.city', 'required' => false])
            ->add('locale', ChoiceType::class, [
                'label' => 'form.language',
                'choices' => ['Français' => 'fr', 'English' => 'en', 'العربية' => 'ar'],
                'choice_translation_domain' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfileData::class, 'csrf_token_id' => 'profile']);
    }
}
