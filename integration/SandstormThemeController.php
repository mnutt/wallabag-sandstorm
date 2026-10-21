<?php

namespace Wallabag\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Wallabag\CoreBundle\Helper\SandstormTheme;

final class SandstormThemeController extends AbstractController
{
    /** @Route("/sandstorm/theme", name="sandstorm_theme", methods={"POST"}) */
    public function save(Request $request, SandstormTheme $themes): Response
    {
        $identity = $themes->identity($request);
        $token = $request->request->get('_token');
        if ($identity === null || !is_string($token) || !$this->isCsrfTokenValid('sandstorm_theme', $token)) {
            return new Response('A signed-in user and valid CSRF token are required.', 403);
        }
        $theme = $request->request->get('theme');
        if (!in_array($theme, SandstormTheme::CHOICES, true)) {
            return new Response('Choose light, dark, or auto.', 400);
        }
        $themes->write($identity, $theme);
        // The response subscriber must emit the newly saved choice.
        $request->attributes->set('_sandstorm_theme', $theme);
        return new Response('', 204, ['Cache-Control' => 'no-store']);
    }
}
