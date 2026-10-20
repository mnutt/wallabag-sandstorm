<?php
// No kernel boot: initialize only the small, private state of this grain.
$root = '/var/lib/wallabag';
if (!is_file($root . '/secret')) {
    $secret = null;
    // Preserve existing signing keys (and RSS GUIDs) when upgrading older grains.
    if (is_file($root . '/parameters.yml')) {
        require '/opt/app/build/wallabag/vendor/autoload.php';
        $old = \Symfony\Component\Yaml\Yaml::parseFile($root . '/parameters.yml');
        $secret = $old['parameters']['secret'] ?? null;
    }
    if (!is_string($secret) || $secret === '' || strpos($secret, '%env(') === 0) {
        $secret = bin2hex(random_bytes(32));
    }
    if (file_put_contents($root . '/secret.tmp', $secret) !== strlen($secret) ||
        !rename($root . '/secret.tmp', $root . '/secret')) {
        throw new \RuntimeException('Cannot initialize the private grain secret.');
    }
}
$database = $root . '/data/library.sqlite';
if (!is_file($database)) {
    $staging = $root . '/data/initializing.sqlite';
    if (!copy('/opt/app/build/library-seed.sqlite', $staging)) {
        throw new \RuntimeException('Cannot copy the library database seed.');
    }
    $db = new \PDO('sqlite:' . $staging);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    // Native password login is disabled; do not share seed credentials between grains.
    $stmt = $db->prepare('UPDATE wallabag_user SET password = ?, salt = ?, confirmation_token = NULL');
    $stmt->execute([bin2hex(random_bytes(64)), bin2hex(random_bytes(32))]);
    $stmt = null;
    $db = null;
    if (!rename($staging, $database) ||
        !copy('/opt/app/build/schema-version', $root . '/schema-version')) {
        throw new \RuntimeException('Cannot finish initializing the library database.');
    }
}
