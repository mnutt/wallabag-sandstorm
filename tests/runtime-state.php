<?php
// Run inside the build VM as a user able to read both disposable grain sandboxes:
// php tests/runtime-state.php /path/to/fresh/sandbox /path/to/upgraded/sandbox
require '/opt/app/build/wallabag/vendor/autoload.php';
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
[$script, $fresh, $upgraded] = $argv;
$secrets = [];
$passwords = [];
foreach ([$fresh, $upgraded] as $sandbox) {
    $root = $sandbox . '/lib/wallabag';
    $secret = file_get_contents($root . '/secret');
    check(strlen($secret) >= 32 && $secret !== 'build-time-placeholder-not-a-grain-secret', 'Invalid runtime secret');
    check((fileperms($root . '/secret') & 0077) === 0, 'Secret permissions are too broad');
    $secrets[] = $secret;
    $db = new PDO('sqlite:' . $root . '/data/library.sqlite');
    $passwords[] = $db->query("SELECT password FROM wallabag_user WHERE username='library'")->fetchColumn();
    check($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Database integrity failure');
    check(file_get_contents($root . '/schema-version') === file_get_contents('/opt/app/build/schema-version'), 'Schema version mismatch');
    check(!is_dir($root . '/runtime/cache/prod'), 'Compiled cache still stored in the grain');
}
check($secrets[0] !== $secrets[1], 'Grains share a secret');
check($passwords[0] !== $passwords[1], 'Grains share an internal user password');
$old = Symfony\Component\Yaml\Yaml::parseFile($upgraded . '/lib/wallabag/parameters.yml');
check(hash_equals($old['parameters']['secret'], $secrets[1]), 'Upgrade changed the signing secret or RSS GUID namespace');
$seed = new PDO('sqlite:/opt/app/build/library-seed.sqlite');
check((int) $seed->query('SELECT count(*) FROM wallabag_entry')->fetchColumn() === 0, 'Seed contains article data');
check(!in_array($seed->query("SELECT password FROM wallabag_user WHERE username='library'")->fetchColumn(), $passwords, true), 'Seed password was not replaced');
echo "PASS: isolated secrets/passwords, preserved upgrade secret, intact databases, seed without articles, schema markers, and no per-grain compiled cache\n";
