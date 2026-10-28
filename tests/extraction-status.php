<?php
// Run in the build VM after building. Optional override tests without rebuilding a live dev session.
require '/opt/app/build/wallabag/vendor/autoload.php';
if (getenv('EXTRACTION_CONTROLLER_FILE')) require getenv('EXTRACTION_CONTROLLER_FILE');
use Wallabag\CoreBundle\Controller\EntryController;
use Wallabag\CoreBundle\Helper\ContentProxy;
use Wallabag\CoreBundle\Entity\Entry;
use Wallabag\UserBundle\Entity\User;
$placeholder = 'Article could not be extracted.';
foreach (['entry_saved', 'entry_reloaded'] as $action) {
    foreach (['content', 'placeholder', 'exception'] as $outcome) {
        $proxy = new class($outcome, $placeholder) extends ContentProxy {
            public function __construct(private $outcome, private $placeholder) {}
            public function updateEntry(Entry $entry, $url, array $content = [], $disableContentUpdate = false) {
                if ($this->outcome === 'exception') throw new RuntimeException('Network error');
                $entry->setContent($this->outcome === 'placeholder' ? $this->placeholder : '<p>Article text</p>');
            }
        };
        $reflection = new ReflectionClass(EntryController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('contentProxy');
        $property->setAccessible(true);
        $property->setValue($controller, $proxy);
        $container = new Symfony\Component\DependencyInjection\ContainerBuilder();
        $session = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
        $container->set('session', $session);
        $container->setParameter('wallabag_core.fetching_error_message', $placeholder);
        $container->set('parameter_bag', $container->getParameterBag());
        $controller->setContainer($container);
        $entry = new Entry(new User());
        $entry->setUrl('https://example.org/article');
        $entry->setTitle('Article');
        $entry->setDomainName('example.org');
        $method = $reflection->getMethod('updateEntry');
        $method->setAccessible(true);
        $method->invoke($controller, $entry, $action);
        $expected = 'flashes.entry.notice.' . $action . ($outcome === 'content' ? '' : '_failed');
        if ($session->getFlashBag()->get('notice') !== [$expected]) throw new RuntimeException("Wrong status for $action/$outcome");
    }
}
echo "PASS: interactive save/reload reports content success, placeholder failure, and thrown failure accurately\n";
