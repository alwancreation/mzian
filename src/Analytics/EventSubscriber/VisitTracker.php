<?php

declare(strict_types=1);

namespace App\Analytics\EventSubscriber;

use App\Analytics\Entity\Visit;
use App\Lead\Service\LeadSourceDetector;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Counts unique daily visitors of public pages without cookies nor raw IPs:
 * visitor hash = sha256(secret + day + anonymized IP + user agent).
 * Runs on kernel.terminate (after the response is sent) to cost the visitor nothing.
 */
final readonly class VisitTracker
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|curl|wget|python|headless|monitor|lighthouse|symfony/i';

    public function __construct(
        private ManagerRegistry $doctrine,
        private LeadSourceDetector $detector,
        #[Autowire(service: 'cache.analytics')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $path = $request->getPathInfo();
        if (!$request->isMethod('GET') || !$response->isSuccessful()
            || !preg_match('#^/(fr|en|ar)(/|$)#', $path)
            || str_contains($path, '/account') || str_contains($path, '/compte') || str_ends_with($path, '.json')
            || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return;
        }
        $userAgent = (string) $request->headers->get('User-Agent', '');
        if ('' === $userAgent || preg_match(self::BOT_PATTERN, $userAgent)) {
            return;
        }

        $ip = (string) $request->getClientIp();
        $anonymizedIp = str_contains($ip, ':') ? implode(':', \array_slice(explode(':', $ip), 0, 3)) : preg_replace('/\.\d+$/', '.0', $ip);
        $day = (new \DateTimeImmutable())->format('Y-m-d');
        $hash = hash('sha256', $this->secret.'|'.$day.'|'.$anonymizedIp.'|'.$userAgent);

        $item = $this->cache->getItem('visit_'.$hash);
        if ($item->isHit()) {
            return;
        }
        $item->set(true)->expiresAfter(90000);
        $this->cache->save($item);

        $utm = array_filter([
            'utm_source' => $request->query->getString('utm_source'),
            'gclid' => $request->query->getString('gclid'),
            'fbclid' => $request->query->getString('fbclid'),
        ]);
        $referrer = $request->headers->get('referer');
        $source = $this->detector->detect($utm, $referrer, $request->getHost());
        $refHost = null !== $referrer ? parse_url($referrer, \PHP_URL_HOST) : null;

        try {
            $em = $this->doctrine->getManager();
            if (!$em instanceof EntityManagerInterface || !$em->isOpen()) {
                return;
            }
            $em->persist(new Visit($hash, $path, $source, \is_string($refHost) ? $refHost : null, $request->getLocale()));
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Already counted today (cache was cleared): fine.
            $this->doctrine->resetManager();
        } catch (\Throwable $e) {
            $this->logger->warning('Visit tracking failed', ['error' => $e->getMessage()]);
        }
    }
}
