<?php

use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase
{
    protected function setUp(): void
    {
        // Set a known key for deterministic tests
        putenv('APP_ENCRYPTION_KEY=fb74a9fcf6800e82a83df3afae908f04');

        // Ensure crypto functions are loaded
        require_once __DIR__ . '/../includes/crypto.php';
    }

    public function testEncryptAndDecrypt(): void
    {
        $original = 'MySecretPassword123!';
        $encrypted = encryptValue($original);

        // Encrypted value must differ from original
        $this->assertNotEquals($original, $encrypted);

        // Decrypting must return the original
        $this->assertEquals($original, decryptValue($encrypted));
    }

    public function testDecryptPlaintextReturnsAsIs(): void
    {
        // Backward compatibility: plaintext strings should be returned unchanged
        $plaintext = 'not-encrypted-password';
        $this->assertEquals($plaintext, decryptValue($plaintext));
    }

    public function testEmptyStringEncryption(): void
    {
        // Empty string should pass through without encryption
        $encrypted = encryptValue('');
        $this->assertEquals('', $encrypted);
        $this->assertEquals('', decryptValue($encrypted));
    }

    public function testNoKeyFallback(): void
    {
        // Without a key, values should pass through unchanged
        putenv('APP_ENCRYPTION_KEY=');

        $value = 'test_password';
        $this->assertEquals($value, encryptValue($value));
        $this->assertEquals($value, decryptValue($value));

        // Restore key for other tests
        putenv('APP_ENCRYPTION_KEY=fb74a9fcf6800e82a83df3afae908f04');
    }

    public function testDifferentEncryptionsProduceDifferentCiphertext(): void
    {
        // Each encryption should use a random IV, producing different ciphertext
        $value = 'same-value';
        $enc1 = encryptValue($value);
        $enc2 = encryptValue($value);

        $this->assertNotEquals($enc1, $enc2);

        // But both should decrypt to the same value
        $this->assertEquals($value, decryptValue($enc1));
        $this->assertEquals($value, decryptValue($enc2));
    }

    public function testSpecialCharacters(): void
    {
        $values = [
            'password with spaces',
            'açéîõü',
            '日本語テスト',
            '<script>alert("xss")</script>',
            "line1\nline2\ttab",
            str_repeat('a', 1000),
        ];

        foreach ($values as $original) {
            $encrypted = encryptValue($original);
            $this->assertEquals($original, decryptValue($encrypted), "Failed for: $original");
        }
    }

    public function testCorruptedCiphertextReturnsSelf(): void
    {
        // A corrupted/invalid base64 string should be returned as-is
        $corrupted = 'not-valid-base64!!!';
        $this->assertEquals($corrupted, decryptValue($corrupted));
    }
}
