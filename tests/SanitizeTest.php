<?php

use PHPUnit\Framework\TestCase;

class SanitizeTest extends TestCase
{
    // =========================================================
    // sanitize() / san() — HTML special character escaping
    // =========================================================

    public function testSanitizeEscapesHtml(): void
    {
        $this->assertEquals(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
            sanitize('<script>alert("xss")</script>')
        );
    }

    public function testSanitizeEscapesSingleQuotes(): void
    {
        $this->assertEquals("it&#039;s", sanitize("it's"));
    }

    public function testSanitizeTrimsWhitespace(): void
    {
        $this->assertEquals('hello', sanitize('  hello  '));
    }

    public function testSanitizeEmptyString(): void
    {
        $this->assertEquals('', sanitize(''));
    }

    public function testSanIsSanitizeAlias(): void
    {
        $input = '<b>bold</b> & "quoted"';
        $this->assertEquals(sanitize($input), san($input));
    }

    // =========================================================
    // sanitizeColor() — hex color validation
    // =========================================================

    public function testSanitizeColorValidHex(): void
    {
        $this->assertEquals('#fff', sanitizeColor('#fff'));
        $this->assertEquals('#FF0000', sanitizeColor('#FF0000'));
        $this->assertEquals('#2596be', sanitizeColor('#2596be'));
        $this->assertEquals('#aabbccdd', sanitizeColor('#aabbccdd')); // 8-char hex (with alpha)
    }

    public function testSanitizeColorInvalidReturnsDefault(): void
    {
        $this->assertEquals('#2596be', sanitizeColor('red'));
        $this->assertEquals('#2596be', sanitizeColor('not-a-color'));
        $this->assertEquals('#2596be', sanitizeColor(''));
        $this->assertEquals('#2596be', sanitizeColor('#xyz'));
    }

    public function testSanitizeColorCssInjection(): void
    {
        // CSS injection attempts must return default
        $this->assertEquals('#2596be', sanitizeColor('#000; background: url(evil)'));
        $this->assertEquals('#2596be', sanitizeColor('expression(alert(1))'));
        $this->assertEquals('#2596be', sanitizeColor('#000}</style><script>'));
    }

    public function testSanitizeColorCustomDefault(): void
    {
        $this->assertEquals('#000', sanitizeColor('invalid', '#000'));
    }

    // =========================================================
    // sanitizeRichText() — XSS prevention in rich text
    // =========================================================

    public function testSanitizeRichTextStripsScriptTags(): void
    {
        $input = '<b>Hello</b><script>alert("xss")</script>';
        $result = sanitizeRichText($input);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('<b>Hello</b>', $result);
    }

    public function testSanitizeRichTextAllowsSafeTags(): void
    {
        $input = '<b>bold</b> <em>italic</em> <u>underline</u>';
        $result = sanitizeRichText($input);
        $this->assertStringContainsString('<b>bold</b>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
        $this->assertStringContainsString('<u>underline</u>', $result);
    }

    public function testSanitizeRichTextStripsAttributesFromNonTableTags(): void
    {
        // Non-table tags must have ALL attributes stripped
        $input = '<b style="color:red" onclick="alert(1)">text</b>';
        $result = sanitizeRichText($input);
        $this->assertEquals('<b>text</b>', $result);
    }

    public function testSanitizeRichTextAllowsSafeStyleOnTableTags(): void
    {
        $input = '<td style="width:100px; text-align:center">cell</td>';
        $result = sanitizeRichText($input);
        $this->assertStringContainsString('style="width:100px; text-align:center"', $result);
    }

    public function testSanitizeRichTextBlocksDangerousCssInTableTags(): void
    {
        // expression(), url(), javascript: must be blocked even in table tags
        $input = '<td style="background:url(javascript:alert(1))">cell</td>';
        $result = sanitizeRichText($input);
        $this->assertStringNotContainsString('javascript', $result);
        $this->assertStringNotContainsString('url(', $result);
    }

    public function testSanitizeRichTextAllowsColspanRowspan(): void
    {
        $input = '<td colspan="2" rowspan="3">cell</td>';
        $result = sanitizeRichText($input);
        $this->assertStringContainsString('colspan="2"', $result);
        $this->assertStringContainsString('rowspan="3"', $result);
    }

    public function testSanitizeRichTextConvertsDivsAndParagraphsToBr(): void
    {
        $input = '<div>line1</div><div>line2</div>';
        $result = sanitizeRichText($input);
        $this->assertStringNotContainsString('<div>', $result);
        $this->assertStringContainsString('<br>', $result);
    }

    public function testSanitizeRichTextLimitsConsecutiveBrTags(): void
    {
        $input = 'text<br><br><br><br><br>more';
        $result = sanitizeRichText($input);
        // Should collapse 3+ <br> into max 2
        $this->assertDoesNotMatchRegularExpression('/(<br\s*\/?>){3,}/', $result);
    }

    public function testSanitizeRichTextStripsLeadingBrTags(): void
    {
        $input = '<br><br>Hello';
        $result = sanitizeRichText($input);
        $this->assertStringStartsWith('Hello', $result);
    }

    public function testSanitizeRichTextStripsDangerousTags(): void
    {
        $dangerous = [
            '<img src=x onerror=alert(1)>',
            '<iframe src="evil.html"></iframe>',
            '<link rel="stylesheet" href="evil.css">',
            '<object data="evil.swf"></object>',
            '<embed src="evil.swf">',
            '<form action="evil"><input></form>',
        ];

        foreach ($dangerous as $input) {
            $result = sanitizeRichText($input);
            $this->assertStringNotContainsString('<img', $result, "Failed to strip: $input");
            $this->assertStringNotContainsString('<iframe', $result, "Failed to strip: $input");
            $this->assertStringNotContainsString('<link', $result, "Failed to strip: $input");
            $this->assertStringNotContainsString('<object', $result, "Failed to strip: $input");
            $this->assertStringNotContainsString('<embed', $result, "Failed to strip: $input");
            $this->assertStringNotContainsString('<form', $result, "Failed to strip: $input");
        }
    }

    // =========================================================
    // formatDate() — date formatting
    // =========================================================

    public function testFormatDateValid(): void
    {
        $this->assertEquals('22/02/2026', formatDate('2026-02-22'));
    }

    public function testFormatDateNull(): void
    {
        $this->assertEquals('-', formatDate(null));
    }

    public function testFormatDateEmpty(): void
    {
        $this->assertEquals('-', formatDate(''));
    }

    // =========================================================
    // formatFileSize() — human-readable file sizes
    // =========================================================

    public function testFormatFileSizeBytes(): void
    {
        $this->assertEquals('500 B', formatFileSize(500));
    }

    public function testFormatFileSizeKilobytes(): void
    {
        $this->assertEquals('1.5 KB', formatFileSize(1536));
    }

    public function testFormatFileSizeMegabytes(): void
    {
        $this->assertEquals('2.5 MB', formatFileSize(2621440));
    }
}
