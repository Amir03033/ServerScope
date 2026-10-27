<?php

namespace Tests\Unit\Websites;

use App\Services\Websites\SitemapParser;
use PHPUnit\Framework\TestCase;

class SitemapParserTest extends TestCase
{
    public function test_parses_url_sets_with_and_without_namespace(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://example.com/</loc><lastmod>2026-10-01</lastmod></url>'
            .'<url><loc> https://example.com/pricing </loc></url>'
            .'<url><loc>https://example.com/pricing</loc></url></urlset>';

        $result = SitemapParser::parse($xml);

        $this->assertSame('urlset', $result['type']);
        $this->assertNull($result['error']);
        $this->assertSame(['https://example.com/', 'https://example.com/pricing'], $result['locations']);
        $this->assertSame(['https://example.com/a'], SitemapParser::parse('<urlset><url><loc>https://example.com/a</loc></url></urlset>')['locations']);
    }

    public function test_parses_sitemap_indexes(): void
    {
        $xml = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/s1.xml</loc></sitemap><sitemap><loc>https://example.com/s2.xml.gz</loc></sitemap></sitemapindex>';

        $result = SitemapParser::parse($xml);

        $this->assertSame('sitemapindex', $result['type']);
        $this->assertSame(['https://example.com/s1.xml', 'https://example.com/s2.xml.gz'], $result['locations']);
    }

    public function test_gzip_compressed_sitemaps(): void
    {
        $result = SitemapParser::parse(gzencode('<urlset><url><loc>https://example.com/gz</loc></url></urlset>'));

        $this->assertSame(['https://example.com/gz'], $result['locations']);
        $this->assertSame('invalid', SitemapParser::parse("\x1f\x8bnot-gzip")['type']);
    }

    public function test_entity_declarations_are_refused(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset><url><loc>&x;</loc></url></urlset>';
        $bomb = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]><urlset><url><loc>&lol2;</loc></url></urlset>';

        foreach ([$xxe, $bomb] as $xml) {
            $result = SitemapParser::parse($xml);
            $this->assertSame('invalid', $result['type']);
            $this->assertSame([], $result['locations']);
            $this->assertStringContainsString('DOCTYPE', $result['error']);
        }
    }

    public function test_malformed_input_is_reported_not_thrown(): void
    {
        foreach (['', '<html><body>Not found</body></html>', '<urlset><url><loc>https://example.com/', 'plain text', '<rss></rss>'] as $body) {
            $result = SitemapParser::parse($body);
            $this->assertSame('invalid', $result['type'], $body);
            $this->assertNotNull($result['error']);
        }
    }

    public function test_entry_limit(): void
    {
        $xml = '<urlset>'.implode('', array_map(fn ($i) => "<url><loc>https://example.com/{$i}</loc></url>", range(1, 30))).'</urlset>';
        $result = SitemapParser::parse($xml, 10);

        $this->assertCount(10, $result['locations']);
        $this->assertTrue($result['truncated']);
    }
}
