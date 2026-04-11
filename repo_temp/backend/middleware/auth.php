<?php
/**
 * Simple JWT Auth Middleware
 */

function generateJWT(int $adminId, string $username): string
{
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = base64_encode(json_encode([
        'sub' => $adminId,
        'username' => $username,
        'iat' => time(),
        'exp' => time() + APP_CONFIG['jwt_expiry']
    ]));

    $signature = base64_encode(
        hash_hmac('sha256', "{$header}.{$payload}", APP_CONFIG['jwt_secret'], true)
    );

    return "{$header}.{$payload}.{$signature}";
}

function verifyJWT(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;

    $expectedSig = base64_encode(
        hash_hmac('sha256', "{$header}.{$payload}", APP_CONFIG['jwt_secret'], true)
    );

    if (!hash_equals($expectedSig, $signature)) return null;

    $data = json_decode(base64_decode($payload), true);

    if (!$data || ($data['exp'] ?? 0) < time()) return null;

    return $data;
}

function withAuth(callable $handler)
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);

    $payload = verifyJWT($token);
    if (!$payload) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $GLOBALS['auth_user'] = $payload;
    return $handler();
}
