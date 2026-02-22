<?php
/**
 * SpecLab - Encriptação de valores sensíveis
 * Usa AES-256-CBC via openssl. Chave definida em APP_ENCRYPTION_KEY (.env).
 */

/**
 * Encripta um valor com AES-256-CBC.
 * Se não houver chave configurada, devolve o valor original.
 */
function encryptValue(string $value): string {
    $key = getenv('APP_ENCRYPTION_KEY');
    if (!$key || $value === '') return $value;
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

/**
 * Desencripta um valor encriptado com encryptValue().
 * Compatível com valores antigos em texto simples:
 *   - Se não houver chave, devolve o valor tal como está.
 *   - Se o valor não tiver o formato encriptado (base64 com separador "::"),
 *     assume que é texto simples e devolve-o inalterado.
 */
function decryptValue(string $encrypted): string {
    if ($encrypted === '') return $encrypted;
    $key = getenv('APP_ENCRYPTION_KEY');
    if (!$key) return $encrypted;

    $data = base64_decode($encrypted, true);
    // Se base64_decode falhar ou não contiver "::", é texto simples
    if ($data === false || strpos($data, '::') === false) return $encrypted;

    [$iv, $ciphertext] = explode('::', $data, 2);
    // IV deve ter exactamente 16 bytes para AES-256-CBC
    if (strlen($iv) !== 16) return $encrypted;

    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, 0, $iv);
    return $decrypted !== false ? $decrypted : $encrypted;
}
