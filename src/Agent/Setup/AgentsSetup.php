<?php

declare(strict_types=1);

namespace App\Agent\Setup;

use App\Agent\Entity\Agent;
use App\Agent\Repository\AgentRepository;
use App\Shared\Settings\SettingsService;
use App\Shared\Setup\SetupStepInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Creates the agents of config/mzian/agents.yaml (existing ones, possibly edited
 * by an administrator, are left untouched).
 */
final readonly class AgentsSetup implements SetupStepInterface
{
    public function __construct(
        private AgentRepository $agents,
        private SettingsService $settings,
        private EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/config/mzian/agents.yaml')]
        private string $file,
    ) {
    }

    public static function getPriority(): int
    {
        return 300;
    }

    public function run(SymfonyStyle $io, bool $demo): void
    {
        $io->writeln(\sprintf('  Agents: +%d', $this->import()));
    }

    public function import(): int
    {
        $created = 0;
        $maxAttempts = (int) ($this->settings->get('automation_policy')['max_attempts'] ?? 3);
        foreach (Yaml::parseFile($this->file)['agents'] as $data) {
            if (null !== $this->agents->findOneBy(['code' => $data['code']])) {
                continue;
            }
            $agent = new Agent($data['code'], $data['name'], $data['description'], array_values($data['permissions']));
            $agent->update($data['name'], $data['description'], array_values($data['permissions']), $maxAttempts);
            $this->em->persist($agent);
            ++$created;
        }
        $this->em->flush();

        return $created;
    }
}
