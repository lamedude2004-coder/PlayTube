<?php
/**
 * Offline regression check for the Sign in with Apple identity-token path.
 *
 * Verifies AppleSignIn\ASDecoder against a locally generated RSA keypair so the
 * JWT library move (bundled firebase/php-jwt 5.4.0 -> vendored 7.2.2) is proven
 * without contacting Apple. Covers the two behaviours the advisories describe:
 * valid RS256 verification, and rejection of algorithm confusion.
 *
 * Run: php scripts/phpjwt_check.php
 */

$root = dirname(__DIR__);
require_once $root . '/assets/libs/apple/vendor/autoload.php';

use AppleSignIn\ASDecoder;
use Firebase\JWT\JWT;

$failures = 0;

function check(string $name, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo "PASS - {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL - {$name}: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** @return array{0: string, 1: string} private PEM, public PEM */
function makeKeypair(): array
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($resource === false) {
        throw new RuntimeException('openssl_pkey_new failed');
    }
    if (!openssl_pkey_export($resource, $privatePem)) {
        throw new RuntimeException('openssl_pkey_export failed');
    }
    $details = openssl_pkey_get_details($resource);
    if (!isset($details['key'])) {
        throw new RuntimeException('could not read public key');
    }
    return [$privatePem, $details['key']];
}

function signToken(string $privatePem, array $header, array $payload): string
{
    $signingInput = b64url(json_encode($header)) . '.' . b64url(json_encode($payload));
    if (!openssl_sign($signingInput, $signature, $privatePem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl_sign failed');
    }
    return $signingInput . '.' . b64url($signature);
}

[$privatePem, $publicPem] = makeKeypair();
[$otherPrivatePem] = makeKeypair();

$claims = [
    'iss' => 'https://appleid.apple.com',
    'aud' => 'com.example.app',
    'sub' => '001234.abcdef.5678',
    'email' => 'user@example.com',
    'iat' => time() - 10,
    'exp' => time() + 300,
];

$token = signToken($privatePem, ['alg' => 'RS256', 'kid' => 'test-kid-1'], $claims);
$keyData = ['publicKey' => $publicPem, 'alg' => 'RS256'];

check('ASDecoder verifies a valid RS256 identity token', function () use ($token, $keyData, $claims) {
    $decoded = ASDecoder::decodeWithPublicKeyData($token, $keyData);
    assertTrue($decoded->sub === $claims['sub'], 'sub claim mismatch');
    assertTrue($decoded->email === $claims['email'], 'email claim mismatch');
    assertTrue($decoded->iss === $claims['iss'], 'iss claim mismatch');
});

check('ASDecoder::getPublicKeyKid reads the key id from the header', function () use ($token) {
    assertTrue(ASDecoder::getPublicKeyKid($token) === 'test-kid-1', 'kid mismatch');
});

check('a token signed with a different key is rejected', function () use ($otherPrivatePem, $keyData, $claims) {
    $forged = signToken($otherPrivatePem, ['alg' => 'RS256', 'kid' => 'test-kid-1'], $claims);
    try {
        ASDecoder::decodeWithPublicKeyData($forged, $keyData);
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('a forged signature was accepted');
});

check('HS256 algorithm confusion using the public key as secret is rejected', function () use ($publicPem, $keyData, $claims) {
    $header = b64url(json_encode(['alg' => 'HS256', 'kid' => 'test-kid-1']));
    $body = b64url(json_encode($claims));
    $signature = hash_hmac('sha256', $header . '.' . $body, $publicPem, true);
    $confused = $header . '.' . $body . '.' . b64url($signature);
    try {
        ASDecoder::decodeWithPublicKeyData($confused, $keyData);
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('an algorithm-confusion token was accepted');
});

check('an expired identity token is rejected', function () use ($privatePem, $keyData) {
    $expired = signToken(
        $privatePem,
        ['alg' => 'RS256', 'kid' => 'test-kid-1'],
        ['sub' => '001234.abcdef.5678', 'exp' => time() - 60]
    );
    try {
        ASDecoder::decodeWithPublicKeyData($expired, $keyData);
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('an expired token was accepted');
});

check('a malformed identity token is rejected', function () use ($keyData) {
    try {
        ASDecoder::decodeWithPublicKeyData('not-a-jwt', $keyData);
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('a malformed token was accepted');
});

check('the bundled 5.4.0 implementation is no longer referenced', function () {
    $source = file_get_contents((new ReflectionClass(ASDecoder::class))->getFileName());
    assertTrue(strpos($source, 'AppleSignIn\\Vendor\\JWT') === false, 'ASDecoder still uses AppleSignIn\\Vendor\\JWT');
    assertTrue(class_exists(JWT::class), 'Firebase\\JWT\\JWT is not autoloadable');
    assertTrue(is_subclass_of(JWT::class, 'stdClass') === false, 'unexpected JWT class');
});

echo "\n";
if ($failures > 0) {
    echo "RESULT: {$failures} FAILED\n";
    exit(1);
}
echo "RESULT: PASSED\n";
