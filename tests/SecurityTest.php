<?php

use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    // =========================================================
    // getColumnList() — whitelist validation
    // =========================================================

    public function testGetColumnListRejectsUnknownTable(): void
    {
        require_once __DIR__ . '/../includes/versioning.php';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table not allowed');

        // Create a mock PDO that should never be called
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('query');

        getColumnList($db, 'users; DROP TABLE --', []);
    }

    public function testGetColumnListRejectsSqlInjection(): void
    {
        require_once __DIR__ . '/../includes/versioning.php';

        $this->expectException(\InvalidArgumentException::class);

        $db = $this->createMock(PDO::class);
        getColumnList($db, "especificacoes' OR '1'='1", []);
    }

    public function testGetColumnListAcceptsAllowedTables(): void
    {
        require_once __DIR__ . '/../includes/versioning.php';

        $allowed = ['especificacao_parametros', 'especificacao_seccoes', 'especificacoes'];

        foreach ($allowed as $table) {
            // Verify no exception is thrown — we mock the DB query
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);

            $db = $this->createMock(PDO::class);
            $db->expects($this->once())->method('query')->willReturn($stmt);

            $result = getColumnList($db, $table, []);
            $this->assertIsString($result);
        }
    }

    // =========================================================
    // sanitizeSvg() — SVG upload safety
    // =========================================================

    public function testSanitizeSvgRemovesScripts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svg_');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="100" height="100"/></svg>');
        $this->assertTrue(sanitizeSvg($tmp));
        $result = file_get_contents($tmp);
        $this->assertStringNotContainsString('<script>', $result);
        unlink($tmp);
    }

    public function testSanitizeSvgRemovesOnEventHandlers(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svg_');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="100" height="100" onclick="alert(1)" onload="evil()"/></svg>');
        $this->assertTrue(sanitizeSvg($tmp));
        $result = file_get_contents($tmp);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onload', $result);
        unlink($tmp);
    }

    public function testSanitizeSvgPreservesValidContent(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svg_');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="#2596be"/></svg>');
        $this->assertTrue(sanitizeSvg($tmp));
        $result = file_get_contents($tmp);
        $this->assertStringContainsString('<circle', $result);
        unlink($tmp);
    }

    // =========================================================
    // sanitizeColor() — CSS injection prevention
    // =========================================================

    public function testSanitizeColorBlocksExpressionInjection(): void
    {
        $attacks = [
            '#000; background: url(evil)',
            'expression(alert(1))',
            '#000}</style><script>alert(1)</script>',
            'rgb(0,0,0); position: fixed',
            '#000; content: "xss"',
        ];

        foreach ($attacks as $attack) {
            $result = sanitizeColor($attack);
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $result, "Failed to block: $attack");
        }
    }
}
