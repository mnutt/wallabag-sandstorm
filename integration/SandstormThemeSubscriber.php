<?php

namespace Wallabag\CoreBundle\Event\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Wallabag\CoreBundle\Helper\SandstormTheme;

final class SandstormThemeSubscriber implements EventSubscriberInterface
{
    private $themes;

    public function __construct(SandstormTheme $themes) { $this->themes = $themes; }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', -10], KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // Only interactive sessions. API clients and feed readers need no theme.
        if (!$event->isMasterRequest() || !$request->headers->has('X-Sandstorm-Base-Path')) return;
        $identity = $this->themes->identity($request);
        if ($identity === null) return;
        $theme = $this->themes->read($identity);
        $request->attributes->set('_sandstorm_theme', $theme);
        $request->attributes->set('_sandstorm_theme_cookie', $request->cookies->get('theme'));
        if ($theme === 'auto') $request->cookies->remove('theme');
        else $request->cookies->set('theme', $theme);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMasterRequest() || !$request->attributes->has('_sandstorm_theme')) return;
        $theme = $request->attributes->get('_sandstorm_theme');
        $old = $request->attributes->get('_sandstorm_theme_cookie');
        if ($theme === 'auto') {
            if ($old !== null) $event->getResponse()->headers->clearCookie('theme', '/');
        } elseif ($theme !== $old) {
            // Upstream JS reads this cookie when applying the OS color scheme.
            $event->getResponse()->headers->setCookie(new Cookie(
                'theme', $theme, time() + 31536000, '/', null, $request->isSecure(), false, false, 'lax'
            ));
        }
    }
}
