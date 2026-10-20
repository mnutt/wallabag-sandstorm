<?php

namespace Sandstorm;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

/** One library per grain. The bridge is the only ingress to the HTTP server. */
final class SandstormAuthenticator extends AbstractGuardAuthenticator
{
    public function supports(Request $request) { return true; }

    public function getCredentials(Request $request)
    {
        return [
            'permissions' => array_map('trim', explode(',', $request->headers->get('X-Sandstorm-Permissions', ''))),
            'feed' => in_array($request->getMethod(), ['GET', 'HEAD'], true) &&
                preg_match('#^/feeds/(all|unread|archive|starred)\.xml$#D', $request->getPathInfo()),
        ];
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        // Do not require a user ID: anonymous capability links are supported.
        if (!in_array('access', $credentials['permissions'], true) &&
            !($credentials['feed'] && in_array('feeds', $credentials['permissions'], true))) {
            throw new AuthenticationException('Library access has not been granted.');
        }
        return $userProvider->loadUserByUsername('library');
    }

    public function checkCredentials($credentials, UserInterface $user) { return true; }
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey) { return null; }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        return new Response('Library access has not been granted.', 403);
    }
    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new Response('Open this library through Sandstorm.', 403);
    }
    public function supportsRememberMe() { return false; }
}
