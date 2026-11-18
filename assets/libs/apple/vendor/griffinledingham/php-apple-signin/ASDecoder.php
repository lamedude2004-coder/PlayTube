<?php

namespace AppleSignIn;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use Exception;

// Verify tokens with the maintained firebase/php-jwt (7.2.2) already vendored in
// this repository instead of the bundled 5.4.0 copy, which carries
// GHSA-8xf4-w7qw-pjjw (kid/algorithm confusion) and GHSA-2x45-7fc3-mxwq (weak
// encryption). Both are only fixed in 6.0.0+ and 7.0.0+ respectively.
if (!class_exists(JWT::class)) {
    $jwtAutoload = __DIR__ . '/../../../../google-lib/vendor/autoload.php';
    if (is_file($jwtAutoload)) {
        require_once $jwtAutoload;
    }
}

/**
 * Decode Sign In with Apple identity token, and produce an ASPayload for
 * utilizing in backend auth flows to verify validity of provided user creds.
 *
 * @package  AppleSignIn\ASDecoder
 * @author   Griffin Ledingham <gcledingham@gmail.com>
 * @license  http://opensource.org/licenses/BSD-3-Clause 3-clause BSD
 * @link     https://github.com/GriffinLedingham/php-apple-signin
 */
class ASDecoder {
    /**
     * Parse a provided Sign In with Apple identity token.
     *
     * @param string $identityToken
     * @return object|null
     */
    public static function getAppleSignInPayload(string $identityToken) : ?object
    {
        $identityPayload = self::decodeIdentityToken($identityToken);
        return new ASPayload($identityPayload);
    }

    /**
     * Decode the Apple encoded JWT using Apple's public key for the signing.
     *
     * @param string $identityToken
     * @return object
     */
    public static function decodeIdentityToken(string $identityToken) : object {
        $publicKeyKid = self::getPublicKeyKid($identityToken);

        $publicKeyData = self::fetchPublicKey($publicKeyKid);

        return self::decodeWithPublicKeyData($identityToken, $publicKeyData);
    }

    /**
     * Verify an identity token against already-resolved key data.
     *
     * Split out from decodeIdentityToken so the verification step can be
     * exercised without calling Apple's key endpoint.
     *
     * @param string $identityToken
     * @param array $publicKeyData
     * @return object
     */
    public static function decodeWithPublicKeyData(string $identityToken, array $publicKeyData) : object {
        if (!isset($publicKeyData['publicKey'], $publicKeyData['alg'])) {
            throw new Exception('Invalid public key data.');
        }

        // Key pins the expected algorithm, so a token cannot downgrade it.
        return JWT::decode($identityToken, new Key($publicKeyData['publicKey'], $publicKeyData['alg']));
    }

    /**
     * Read the "kid" from the token header so Apple's matching key can be fetched.
     *
     * @param string $identityToken
     * @return string
     */
    public static function getPublicKeyKid(string $identityToken) : string {
        $segments = explode('.', $identityToken);

        if (count($segments) !== 3) {
            throw new Exception('Invalid identity token format.');
        }

        $encodedHeader = strtr($segments[0], '-_', '+/');
        $paddedHeader = str_pad($encodedHeader, (int) (ceil(strlen($encodedHeader) / 4) * 4), '=');
        $headerJson = base64_decode($paddedHeader, true);

        if ($headerJson === false) {
            throw new Exception('Invalid identity token header.');
        }

        $header = json_decode($headerJson, true);

        if (!is_array($header) || !isset($header['kid']) || !is_string($header['kid']) || $header['kid'] === '') {
            throw new Exception('Identity token header is missing a key id.');
        }

        return $header['kid'];
    }

    /**
     * Fetch Apple's public key from the auth/keys REST API to use to decode
     * the Sign In JWT.
     *
     * @param string $publicKeyKid
     * @return array
     */
    public static function fetchPublicKey(string $publicKeyKid) : array {
        $publicKeys = file_get_contents('https://appleid.apple.com/auth/keys');
        $decodedPublicKeys = json_decode($publicKeys, true);

        if(!isset($decodedPublicKeys['keys']) || count($decodedPublicKeys['keys']) < 1) {
            throw new Exception('Invalid key format.');
        }

        $kids = array_column($decodedPublicKeys['keys'], 'kid');
        $keyIndex = array_search($publicKeyKid, $kids, true);

        if ($keyIndex === false) {
            throw new Exception('No public key matches the provided key id.');
        }

        $parsedKeyData = $decodedPublicKeys['keys'][$keyIndex];
        $parsedPublicKey = JWK::parseKey($parsedKeyData);

        if (!$parsedPublicKey instanceof Key) {
            throw new Exception('Invalid public key details.');
        }

        $publicKeyMaterial = $parsedPublicKey->getKeyMaterial();

        if (!is_string($publicKeyMaterial) || $publicKeyMaterial === '') {
            throw new Exception('Invalid public key details.');
        }

        return [
            'publicKey' => $publicKeyMaterial,
            'alg' => $parsedPublicKey->getAlgorithm()
        ];
    }
}

/**
 * A class decorator for the Sign In with Apple payload produced by
 * decoding the signed JWT from a client.
 */
class ASPayload {
    protected $_instance;

    public function __construct(?object $instance) {
        if(is_null($instance)) {
            throw new Exception('ASPayload received null instance.');
        }
        $this->_instance = $instance;
    }

    public function __call($method, $args) {
        return call_user_func_array(array($this->_instance, $method), $args);
    }

    public function __get($key) {
        return (isset($this->_instance->$key)) ? $this->_instance->$key : null;
    }

    public function __set($key, $val) {
        return $this->_instance->$key = $val;
    }

    public function getEmail() : ?string {
        return (isset($this->_instance->email)) ? $this->_instance->email : null;
    }

    public function getUser() : ?string {
        return (isset($this->_instance->sub)) ? $this->_instance->sub : null;
    }

    public function verifyUser(string $user) : bool {
        return $user === $this->getUser();
    }
}
