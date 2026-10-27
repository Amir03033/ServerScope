<?php

namespace Tests\Unit\Websites;

use App\Services\Websites\ContentFingerprint;
use PHPUnit\Framework\TestCase;

class ContentFingerprintTest extends TestCase
{
    private function page(string $main, string $extra = ''): string
    {
        return "<html><head><title>T</title><script>var t = {$extra}1;</script></head><body><nav>Menu</nav><main><h1>Prijzen</h1><p>{$main}</p></main><aside class=\"ads\">Advertentie {$extra}</aside><form><input type=\"hidden\" name=\"_token\" value=\"{$extra}abc\"></form></body></html>";
    }

    public function test_dynamic_values_do_not_change_the_fingerprint(): void
    {
        $a = ContentFingerprint::extract($this->page('Bijgewerkt op 2026-10-26 10:15:01, bezoeker 12345678, sessie 5f2b9c1e8a7d4f3b9c1e8a7d4f3b9c1e'));
        $b = ContentFingerprint::extract($this->page('Bijgewerkt op 2026-10-27 11:16:59, bezoeker 87654321, sessie aaaabbbbccccddddeeeeffff00001111'));

        $this->assertSame($a['fingerprint'], $b['fingerprint']);
        $this->assertStringNotContainsString('var t', $a['text']);
        $this->assertStringContainsString('Prijzen', $a['text']);
    }

    public function test_meaningful_changes_and_exclusions(): void
    {
        $old = ContentFingerprint::extract($this->page('Starter €10 per maand', 'x'));
        $new = ContentFingerprint::extract($this->page('Starter €12 per maand', 'y'));
        $this->assertNotSame($old['fingerprint'], $new['fingerprint']);

        // Only the excluded block changes: no change.
        $a = ContentFingerprint::extract($this->page('Gelijk', 'banner A'), exclusions: ['.ads']);
        $b = ContentFingerprint::extract($this->page('Gelijk', 'banner B'), exclusions: ['.ads']);
        $this->assertSame($a['fingerprint'], $b['fingerprint']);
    }

    public function test_selector_limits_the_compared_content(): void
    {
        $a = ContentFingerprint::extract($this->page('Hetzelfde', 'reclame 1'), selector: 'main');
        $b = ContentFingerprint::extract($this->page('Hetzelfde', 'reclame 2'), selector: 'main');

        $this->assertSame($a['fingerprint'], $b['fingerprint']);
        $this->assertTrue($a['selector_found']);
        $this->assertStringNotContainsString('Menu', $a['text']);
        $this->assertFalse(ContentFingerprint::extract($this->page('x'), selector: '#missing')['selector_found']);
    }

    public function test_diff_reports_lines_and_ratio(): void
    {
        $diff = ContentFingerprint::diff("Kop\nRegel een\nRegel twee", "Kop\nRegel een\nRegel drie");

        $this->assertSame(['Regel drie'], $diff['added']);
        $this->assertSame(['Regel twee'], $diff['removed']);
        $this->assertGreaterThan(0.2, $diff['ratio']);
        $this->assertSame(0.0, ContentFingerprint::diff('a', 'a')['ratio']);
    }

    public function test_selector_support(): void
    {
        foreach (['main', '#content', '.pricing', 'div.card', 'section#plans', '[data-role=price]'] as $selector) {
            $this->assertTrue(ContentFingerprint::isValidSelector($selector), $selector);
        }

        foreach (['div > p', 'a:hover', 'ul li', "x' or 1=1"] as $selector) {
            $this->assertFalse(ContentFingerprint::isValidSelector($selector), $selector);
        }
    }
}
