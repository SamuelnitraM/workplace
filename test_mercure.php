<?php
require __DIR__.'/vendor/autoload.php';

$secret = 'highlightforge_secret_2026';

$header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
$payload = rtrim(strtr(base64_encode(json_encode(['mercure' => ['publish' => ['*']]])), '+/', '-_'), '=');
$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
$token = "$header.$payload.$signature";

echo "Token: $token\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:3000/.well-known/mercure');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'topic=test&data=hello');
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";