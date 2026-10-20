<?php
// Run in the build VM: php tests/theme-state.php
require '/opt/app/build/wallabag/vendor/autoload.php';
require '/opt/app/build/wallabag/app/AppKernel.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Wallabag\CoreBundle\Helper\SandstormTheme;
use Wallabag\CoreBundle\Event\Subscriber\SandstormThemeSubscriber;

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$themes = new SandstormTheme();
$subscriber = new SandstormThemeSubscriber($themes);
$kernel = new AppKernel('prod', false);
$alice = Request::create('/');
$alice->headers->set('X-Sandstorm-User-Id', 'theme-test-' . bin2hex(random_bytes(16)));
$alice->headers->set('X-Sandstorm-Base-Path', 'https://ui-one.example.test');
$bob = clone $alice;
$bob->headers->set('X-Sandstorm-User-Id', 'theme-test-' . bin2hex(random_bytes(16)));
$aliceId = $themes->identity($alice);
$bobId = $themes->identity($bob);
try {
    $themes->write($aliceId, 'dark');
    check($themes->read($bobId) === 'auto', 'Another user inherited the first user’s choice.');
    $themes->write($bobId, 'light');
    check($themes->read($aliceId) === 'dark', 'Another user overwrote the first user’s choice.');
    $subscriber->onRequest(new RequestEvent($kernel, $alice, HttpKernelInterface::MASTER_REQUEST));
    check($alice->cookies->get('theme') === 'dark', 'First render did not receive the saved theme.');
    $response = new Response();
    $subscriber->onResponse(new ResponseEvent($kernel, $alice, HttpKernelInterface::MASTER_REQUEST, $response));
    check($response->headers->getCookies()[0]->getValue() === 'dark', 'Upstream JS cookie was not initialized.');
    $anonymous = Request::create('/');
    $anonymous->headers->set('X-Sandstorm-Base-Path', 'https://ui-anon.example.test');
    $anonymous->cookies->set('theme', 'light');
    $subscriber->onRequest(new RequestEvent($kernel, $anonymous, HttpKernelInterface::MASTER_REQUEST));
    check($themes->identity($anonymous) === null, 'Anonymous visitors must not share a preference identity.');
    check(!$anonymous->attributes->has('_sandstorm_theme') && $anonymous->cookies->get('theme') === 'light',
        'Anonymous cookie behavior changed.');
    $api = clone $bob;
    $api->headers->remove('X-Sandstorm-Base-Path');
    $subscriber->onRequest(new RequestEvent($kernel, $api, HttpKernelInterface::MASTER_REQUEST));
    check(!$api->attributes->has('_sandstorm_theme'), 'API sessions should not initialize themes.');
    echo "PASS: per-identity isolation, first-render theme/cookie, anonymous fallback, and API exclusion\n";
} finally {
    foreach ([$aliceId, $bobId] as $id) {
        $path = '/var/lib/wallabag/preferences/' . $id;
        if (is_file($path)) unlink($path);
    }
}
