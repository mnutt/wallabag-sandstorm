<?php
// Run in the build VM: php tests/csrf-scheme.php
// Exercise Symfony's actual inline-fragment handler: it temporarily changes
// trusted proxies, affecting the master request used by CsrfTokenManager.
require __DIR__.'/../build/wallabag/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpCache\SubRequestHandler;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

foreach ([['http', false, true], ['https', false, false], ['http', true, true], ['https', true, true]] as [$scheme, $fixed, $expected]) {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_ALL);
    $request = Request::create('http://grain.example/', 'GET', [], [], [], [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => $scheme,
    ]);
    // Equivalent to nginx's map + fastcgi_param HTTPS in the fixed package.
    $request->server->set('HTTPS', $fixed && $scheme === 'https' ? 'on' : 'off');
    $stack = new RequestStack();
    $stack->push($request);
    $manager = new CsrfTokenManager(null, new SessionTokenStorage(new Session(new MockArraySessionStorage())), $stack);
    $kernel = new class($manager) implements HttpKernelInterface {
        private $manager;
        public function __construct($manager) { $this->manager = $manager; }
        public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true) {
            return new Response($this->manager->getToken('entry')->getValue());
        }
    };
    $response = SubRequestHandler::handle($kernel, $request->duplicate(), HttpKernelInterface::SUB_REQUEST, false);
    $valid = $manager->isTokenValid(new CsrfToken('entry', $response->getContent()));
    if ($valid !== $expected || $manager->isTokenValid(new CsrfToken('entry', 'invalid'))) {
        throw new RuntimeException("Unexpected CSRF validation: scheme=$scheme fixed=$fixed");
    }
    echo "$scheme ".($fixed ? 'fixed' : 'original').': '.($valid ? 'valid' : 'invalid (reproduced)')."\n";
}
