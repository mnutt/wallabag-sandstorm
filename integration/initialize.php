<?php
// Run once, against a staging database, before the grain starts serving requests.
require '/opt/app/build/wallabag/vendor/autoload.php';
$kernel = new AppKernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();
$manager = $container->get('fos_user.user_manager');
$user = $manager->findUserByUsername('wallabag');
if (!$user) { throw new RuntimeException('Installer did not create the library user'); }
$user->setUsername('library');
$user->setEmail('library@localhost.invalid');
$user->setPlainPassword(bin2hex(random_bytes(32)));
$user->setRoles(['ROLE_USER']);
$manager->updateUser($user);
$em = $container->get('doctrine')->getManager();
$setting = $em->getRepository(\Wallabag\CoreBundle\Entity\InternalSetting::class)->findOneBy(['name' => 'download_images_enabled']);
$setting->setValue('1');
$publicSharing = $em->getRepository(\Wallabag\CoreBundle\Entity\InternalSetting::class)->findOneBy(['name' => 'share_public']);
$publicSharing->setValue('0');
$em->flush();
$kernel->shutdown();
