<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Provider\ProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

/**
 * Runs the queued pipeline jobs (in-memory "async" transport in tests) like a
 * worker would, and serves the deployed applications with the real apps router.
 */
trait PipelineRunnerTrait
{
    private ?Process $appsServer = null;

    /**
     * @return list<string> operations handled, in order
     */
    protected function runPipeline(int $maxJobs = 60): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $handled = [];
        for ($i = 0; $i < $maxJobs; ++$i) {
            $envelopes = [...$transport->get()];
            if ([] === $envelopes) {
                break;
            }
            $envelope = $envelopes[0];
            $transport->ack($envelope);
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            $handled[] = $envelope->getMessage()::operation();
        }

        return $handled;
    }

    /**
     * Starts the apps server on a free port and points the local deployment provider to it.
     */
    protected function startAppsServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $deployments = (string) static::getContainer()->getParameter('mzian.deployments_dir');
        $this->appsServer = new Process([\PHP_BINARY, '-S', $address, \dirname(__DIR__, 2).'/docker/apps/router.php'], null, ['MZIAN_DEPLOYMENTS_DIR' => $deployments]);
        $this->appsServer->start();
        usleep(300_000);

        $provider = static::getContainer()->get(ProviderRegistry::class)->findByCode('local_apps');
        $provider?->setSettings(['public_base_url' => 'http://'.$address, 'internal_base_url' => 'http://'.$address]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return 'http://'.$address;
    }

    protected function stopAppsServer(): void
    {
        $this->appsServer?->stop(1);
        $this->appsServer = null;
    }
}
