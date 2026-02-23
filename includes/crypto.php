<?php
/**
 * SpecLab - Encriptação de valores sensíveis
 * Usa AES-256-CBC via openssl. Chave definida em APP_ENCRYPTION_KEY (.env).
 */

if (!defined('OPENSSL_RAW_OUTPUT')) define('OPENSSL_RAW_OUTPUT', 1);

/**
 * Encripta um valor com AES-256-CBC.
 * Se não houver chave configurada, devolve o valor original.
 */
function encryptValue(string $value): string {
    $key = getenv('APP_ENCRYPTION_KEY');
    if (!$key || $value === '') return $value;
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_OUTPUT, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Desencripta um valor encriptado com encryptValue().
 * Compatível com valores antigos em texto simples e formato anterior (IV::base64).
 */
function decryptValue(string $encrypted): string {
    if ($encrypted === '') return $encrypted;
    $key = getenv('APP_ENCRYPTION_KEY');
    if (!$key) return $encrypted;

    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) < 17) return $encrypted;

    // Formato novo: IV (16 bytes) + raw ciphertext
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_OUTPUT, $iv);
    if ($decrypted !== false) return $decrypted;

    // Formato antigo: IV (16 bytes) + '::' + base64 ciphertext
    if (strpos($data, '::') !== false) {
        [$iv, $ciphertext] = explode('::', $data, 2);
        if (strlen($iv) === 16) {
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, 0, $iv);
            if ($decrypted !== false) return $decrypted;
        }
    }

    return $encrypted;
}
