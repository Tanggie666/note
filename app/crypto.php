<?php
declare(strict_types=1);

function derive_key(string $password, string $salt): string {
    if ($password === '') {
        throw new InvalidArgumentException('密码不能为空');
    }
    return sodium_crypto_pwhash(
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        $password,
        $salt,
        SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE
    );
}

function encrypt_string(string $plain, string $key): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
}

function decrypt_string(string $blob, string $key): ?string {
    $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($blob) < $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) return null;
    $plain = sodium_crypto_secretbox_open(substr($blob, $nonceLen), substr($blob, 0, $nonceLen), $key);
    return $plain === false ? null : $plain;
}