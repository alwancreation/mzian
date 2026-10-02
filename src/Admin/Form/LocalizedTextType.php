<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Shared\Routing\LocalizedRoute;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Edits a {"fr": ..., "en": ..., "ar": ...} translation map.
 *
 * @extends AbstractType<array<string, string>>
 */
final class LocalizedTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (LocalizedRoute::LOCALES as $locale) {
            $builder->add($locale, $options['multiline'] ? TextareaType::class : TextType::class, [
                'label' => strtoupper($locale),
                'required' => 'fr' === $locale && $options['required'],
                'constraints' => 'fr' === $locale && $options['required'] ? [new Assert\NotBlank()] : [],
                'attr' => ['dir' => 'ar' === $locale ? 'rtl' : 'ltr', 'rows' => 3],
                'empty_data' => '',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['multiline' => false, 'label_attr' => ['class' => 'font-semibold']]);
        $resolver->setAllowedTypes('multiline', 'bool');
    }
}
