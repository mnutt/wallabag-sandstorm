<?php
/** Keep untrusted uploads outside the web root and isolate concurrent imports. */
final class SandstormImportUpload
{
    public static function run($file, $import, bool $markAsRead, string $directory): bool
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('Import staging directory is unavailable');
        }
        $path = tempnam($directory, 'import-');
        if ($path === false) throw new \RuntimeException('Unable to stage import');
        try {
            $file->move($directory, basename($path));
            return $import->setFilepath($path)->setMarkAsRead($markAsRead)->import();
        } finally {
            if (is_file($path)) unlink($path);
        }
    }
}
