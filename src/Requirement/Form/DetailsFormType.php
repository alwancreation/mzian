<?php

declare(strict_types=1);

namespace App\Requirement\Form;

use App\Requirement\Dto\DetailsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<DetailsData>
 */
final class DetailsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('businessName', TextType::class, ['label' => 'start.details.business_name', 'attr' => ['autocomplete' => 'organization']])
            ->add('city', TextType::class, ['label' => 'form.city', 'required' => false])
            ->add('description', TextareaType::class, ['label' => 'start.details.description', 'required' => false, 'help' => 'start.details.description_help', 'attr' => ['rows' => 5, 'placeholder' => 'start.details.description_placeholder']])
            ->add('desiredDomain', TextType::class, ['label' => 'start.details.domain', 'required' => false, 'help' => 'start.details.domain_help', 'attr' => ['placeholder' => 'mon-entreprise.ma', 'dir' => 'ltr']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DetailsData::class, 'csrf_token_id' => 'requirement_details']);
    }
}
