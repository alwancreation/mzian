<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Edits an array as pretty-printed JSON (invalid JSON is reported as a form error).
 *
 * @extends AbstractType<array<mixed>>
 */
final class JsonTextareaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?array $value) => json_encode($value ?? [], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            static function (?string $json): array {
                try {
                    $decoded = json_decode($json ?: '[]', true, 32, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new TransformationFailedException('Invalid JSON: '.$e->getMessage(), 0, $e, 'Invalid JSON: {{ error }}', ['{{ error }}' => $e->getMessage()]);
                }
                if (!\is_array($decoded)) {
                    throw new TransformationFailedException('JSON must be an array or object.');
                }

                return $decoded;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['attr' => ['rows' => 8, 'class' => 'font-mono text-xs', 'dir' => 'ltr'], 'invalid_message' => 'Invalid JSON.']);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}
