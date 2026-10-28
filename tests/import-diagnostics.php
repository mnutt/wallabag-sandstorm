<?php
require '/opt/app/build/wallabag/vendor/autoload.php';
require '/opt/app/integration/import-common.php';
if (getenv('DIAGNOSTIC_IMAGE_FILE')) require getenv('DIAGNOSTIC_IMAGE_FILE');
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); }
foreach (['javascript:alert(1)' => 'javascript', ' FILE:///Users/a.html ' => 'file', 'about:blank' => 'about', 'data:text/html,hi' => 'data', 'https://example.org/a' => null, 'HTTP://example.org/' => null, '//example.org/a' => null] as $url => $scheme) {
 verify(importUnsupportedScheme($url) === $scheme, 'Wrong URL classification: '.$url);
}
$stream=fopen('php://temp','w+');
$logger=new Monolog\Logger('fetch',[importDiagnosticHandler('type=firefox url="https://example.org"',$stream)]);
$logger->warning('Request failed: {error_message}', ['error_message'=>'Too Many Requests']);
$logger->error('Save failed', ['exception'=>new RuntimeException('Invalid image URL')]);
rewind($stream);$text=stream_get_contents($stream);
verify(str_contains($text,'Request failed: Too Many Requests') && !str_contains($text,'{error_message}'),'Missing interpolation');
verify(str_contains($text,'Save failed Invalid image URL'),'Missing exception reason');
$client=new class implements Psr\Http\Client\ClientInterface {
 public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface {throw new RuntimeException('Unexpected network request');}
};
$download=new Wallabag\CoreBundle\Helper\DownloadImages($client,sys_get_temp_dir(),'',new Psr\Log\NullLogger());
verify($download->processSingleImage(1,'//:0','https://example.org/', 'diagnostics') === false, 'Malformed optional image must be skipped');
echo "PASS: unsupported schemes, interpolated diagnostics, exception reasons, malformed image skipped without network\n";
