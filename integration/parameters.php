<?php
require '/opt/app/build/wallabag/vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;
$config = Yaml::parseFile('/opt/app/build/wallabag/app/config/parameters.yml.dist');
$config['parameters'] = array_merge($config['parameters'], [
    'database_driver' => 'pdo_sqlite',
    'database_path' => '%env(WALLABAG_DATABASE)%',
    'database_charset' => 'utf8',
    'domain_name' => '',
    'server_name' => 'wallabag',
    'secret' => '%env(WALLABAG_SECRET)%',
    'fosuser_registration' => false,
    'fosuser_confirmation' => false,
    'mailer_dsn' => 'null://null',
]);
echo Yaml::dump($config, 4);
