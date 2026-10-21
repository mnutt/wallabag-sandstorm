<?php

namespace Wallabag\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

final class SandstormExtensionController extends AbstractController
{
    /** @Route("/sandstorm/extensions", name="sandstorm_extensions", methods={"GET"}) */
    public function settings()
    {
        return $this->render('@WallabagCore/Sandstorm/extensions.html.twig');
    }
}
