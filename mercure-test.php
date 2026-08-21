<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function generateMercureJwt(string $secret, array $subscribe = [], array $publish = []): string {
    $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = json_encode([
        'mercure' => [
            'subscribe' => empty($subscribe) ? ['*'] : $subscribe,
            'publish' => empty($publish) ? ['*'] : $publish,
        ],
        'exp' => time() + 3600,
    ]);

    $base64Header = base64UrlEncode($header);
    $base64Payload = base64UrlEncode($payload);
    $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);
    $base64Signature = base64UrlEncode($signature);

    return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
}

$secret = getenv('MERCURE_PUBLISHER_JWT_KEY') ?: 'FrankenPHPMercurePublisherSecretKey2026Dev!';
$hubUrl = getenv('MERCURE_INTERNAL_URL') ?: 'http://127.0.0.1:80/.well-known/mercure';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $topic = $_POST['topic'] ?? 'https://example.com/notifications';
    $message = $_POST['message'] ?? ('Hello at ' . date('H:i:s'));

    $jwt = generateMercureJwt($secret, [], ['*']);

    $postData = http_build_query([
        'topic' => $topic,
        'data' => is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE),
    ], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($hubUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $jwt,
        'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            'success' => true,
            'topic' => $topic,
            'message' => $message,
            'id' => trim((string)$response),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'http_code' => $httpCode,
            'error' => $error ?: $response,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode([
    'status' => 'active',
    'hub_url' => $hubUrl,
    'description' => 'FrankenPHP Mercure Publishing Endpoint',
    'usage' => 'Send POST with topic & message fields',
], JSON_UNESCAPED_UNICODE);
