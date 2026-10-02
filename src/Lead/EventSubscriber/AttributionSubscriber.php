<?php

declare(strict_types=1);

namespace App\Lead\EventSubscriber;

use App\Lead\Service\Attribution;
use App\Lead\Service\LeadSourceDetector;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stores first-touch attribution (utm_*, gclid, fbclid, external referrer) in the
 * session, only when the visitor arrives with such information (no session otherwise).
 */
final readonly class AttributionSubscriber
{
    public const SESSION_KEY = 'mzian_attribution';
    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];

    public function __construct(private LeadSourceDetector $detector)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: -20)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !$request->hasSession()) {
            return;
        }
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api') || str_starts_with($path, '/_')) {
            return;
        }

        $utm = [];
        foreach (self::UTM_KEYS as $key) {
            $value = $request->query->get($key);
            if (\is_string($value) && '' !== $value) {
                $utm[$key] = mb_substr($value, 0, 120);
            }
        }
        $referrer = $request->headers->get('referer');
        $refHost = null !== $referrer ? parse_url($referrer, \PHP_URL_HOST) : null;
        $external = \is_string($refHost) && $refHost !== $request->getHost();

        if ([] === $utm && !$external) {
            return;
        }
        $session = $request->getSession();
        if ($session->has(self::SESSION_KEY)) {
            return; // first touch wins
        }
        $source = $this->detector->detect($utm, $referrer, $request->getHost());
        $session->set(self::SESSION_KEY, (new Attribution($source, $utm, $referrer, $request->getRequestUri()))->toArray());
    }
}
