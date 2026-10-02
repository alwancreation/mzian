<?php

declare(strict_types=1);

namespace App\Testing\Entity;

use App\Testing\Enum\TestSeverity;
use App\Testing\Enum\TestStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_result')]
class TestResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TestRun::class, inversedBy: 'results')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TestRun $testRun;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $name;

    /** backend | frontend | application | infrastructure | qa */
    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 10, enumType: TestSeverity::class)]
    private TestSeverity $severity;

    #[ORM\Column(length: 10, enumType: TestStatus::class)]
    private TestStatus $status;

    #[ORM\Column(length: 1000)]
    private string $message;

    #[ORM\Column]
    private int $durationMs;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(TestRun $testRun, string $code, string $name, string $category, TestSeverity $severity, TestStatus $status, string $message, int $durationMs = 0, array $details = [])
    {
        $this->testRun = $testRun;
        $this->code = $code;
        $this->name = mb_substr($name, 0, 160);
        $this->category = $category;
        $this->severity = $severity;
        $this->status = $status;
        $this->message = mb_substr($message, 0, 1000);
        $this->durationMs = $durationMs;
        $this->details = $details;
        $testRun->addResult($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTestRun(): TestRun
    {
        return $this->testRun;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getSeverity(): TestSeverity
    {
        return $this->severity;
    }

    public function getStatus(): TestStatus
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
