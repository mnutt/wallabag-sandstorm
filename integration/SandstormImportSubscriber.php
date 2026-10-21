<?php
namespace Wallabag\CoreBundle\Event\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SandstormImportSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => 'onResponse']; }

    public function onResponse(ResponseEvent $event): void
    {
        $id = $event->getRequest()->attributes->get('_sandstorm_import_publisher');
        if (!$id) return;
        require_once '/opt/app/integration/import-common.php';
        importRedis()->zrem('sandstorm.import.publishing', $id);
    }
}
