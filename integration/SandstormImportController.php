<?php
namespace Wallabag\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class SandstormImportController extends AbstractController
{
    /** @Route("/sandstorm/imports", name="sandstorm_imports", methods={"GET", "POST"}) */
    public function status(Request $request): Response
    {
        // The nginx/authenticator access permission also protects this route.
        if (!$request->isMethod('POST')) return $this->redirectToRoute('import');
        require_once '/opt/app/integration/import-common.php';
        $redis = importRedis();
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('sandstorm_imports', $token)) return new Response('Invalid form token.', 403);
        $action = $request->request->get('action');
        if (!in_array($action, ['pause', 'resume', 'retry'], true)) return new Response('Invalid action.', 400);
        if ($action === 'pause') $redis->set('sandstorm.import.paused', '1');
        else {
            if ($action === 'retry') {
                foreach (importTypes() as $type) {
                    $queue = 'wallabag.import.' . $type;
                    foreach (['-failed', '-error'] as $suffix) {
                        // Bound the loop to the initial count; workers may
                        // fail new jobs concurrently with this request.
                        $count = $redis->llen($queue . $suffix);
                        for ($i = 0; $i < $count; ++$i) $redis->rpoplpush($queue . $suffix, $queue);
                    }
                }
            }
            importActivity($request->headers->get('X-Sandstorm-Session-Id', ''));
            $redis->del(['sandstorm.import.paused']);
        }
        return $this->redirectToRoute('import');
    }
}
