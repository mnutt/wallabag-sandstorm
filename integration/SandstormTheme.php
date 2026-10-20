<?php

namespace Wallabag\CoreBundle\Helper;

use Symfony\Component\HttpFoundation\Request;

/** Preferences belong to Sandstorm identities, not the shared "library" user. */
final class SandstormTheme
{
    private const DIRECTORY = '/var/lib/wallabag/preferences';
    public const CHOICES = ['light', 'dark', 'auto'];

    public function identity(Request $request): ?string
    {
        $id = $request->headers->get('X-Sandstorm-User-Id', '');
        return $id === '' ? null : hash('sha256', $id);
    }

    public function read(string $identity): string
    {
        $path = self::DIRECTORY . '/' . $identity;
        $theme = is_file($path) ? file_get_contents($path) : 'auto';
        return in_array($theme, self::CHOICES, true) ? $theme : 'auto';
    }

    public function write(string $identity, string $theme): void
    {
        if (!in_array($theme, self::CHOICES, true)) {
            throw new \InvalidArgumentException('Invalid theme.');
        }
        if (!is_dir(self::DIRECTORY) && !mkdir(self::DIRECTORY, 0700, true) && !is_dir(self::DIRECTORY)) {
            throw new \RuntimeException('Cannot create preferences directory.');
        }
        $temporary = tempnam(self::DIRECTORY, '.theme-');
        if ($temporary === false) throw new \RuntimeException('Cannot create preference file.');
        try {
            if (file_put_contents($temporary, $theme) !== strlen($theme) ||
                !rename($temporary, self::DIRECTORY . '/' . $identity)) {
                throw new \RuntimeException('Cannot save theme preference.');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
