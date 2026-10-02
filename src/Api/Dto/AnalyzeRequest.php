<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final class AnalyzeRequest
{
    #[SerializedName('business_type')]
    #[Assert\Length(max: 60)]
    #[Assert\Regex('/^[a-z_]+$/')]
    public ?string $businessType = null;

    /** @var array<string, mixed> */
    #[Assert\Count(max: 60)]
    public array $answers = [];

    #[Assert\Length(max: 5000)]
    public string $description = '';

    #[Assert\Choice(choices: ['fr', 'en', 'ar'])]
    public string $locale = 'fr';

    #[SerializedName('business_name')]
    #[Assert\Length(max: 160)]
    public ?string $businessName = null;

    #[Assert\Length(max: 120)]
    public ?string $city = null;

    #[Assert\Callback]
    public function validateNotEmpty(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (null === $this->businessType && '' === trim($this->description)) {
            $context->buildViolation('Provide at least a business_type or a description.')->atPath('description')->addViolation();
        }
    }
}
