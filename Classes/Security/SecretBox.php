<?php

declare(strict_types=1);

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Oneco\AnalyticsPro\Typo3\Security;

/** Context-bound authenticated encryption; changing the TYPO3 key requires restoring the backup. */
final class SecretBox
{
    public function seal(string $plaintext, string $site): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key($site)));
    }

    public function open(string $ciphertext, string $site): string
    {
        $bytes = base64_decode($ciphertext, true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('CREDENTIAL_STORAGE_FAILED');
        }
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key($site));
        if ($plain === false) {
            throw new \RuntimeException('RECOVERY_UNAVAILABLE');
        }
        return $plain;
    }

    private function key(string $site): string
    {
        $key = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if (strlen($key) < 32) {
            throw new \RuntimeException('CREDENTIAL_STORAGE_FAILED');
        }
        return hash_hkdf('sha256', $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'prelumen/site/' . $site);
    }
}
