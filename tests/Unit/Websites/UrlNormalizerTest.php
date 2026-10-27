<?php

namespace Tests\Unit\Websites;

use App\Services\Websites\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    public function test_equivalent_urls_normalise_to_one_form(): void
    {
        $same = [
            'https://Example.COM/pricing',
            'https://example.com:443/pricing',
            'https://example.com/pricing#plans',
            'https://example.com/./docs/../pricing',
            'https://example.com/pricing?utm_source=x',
            'https://example.com.:443/pricing',
            'https://example.com/%70ricing',
        ];

        foreach ($same as $url) {
            $this->assertSame('https://example.com/pricing', UrlNormalizer::normalize($url), $url);
        }

        $this->assertSame('http://example.com:8080/', UrlNormalizer::normalize('HTTP://example.com:8080'));
        $this->assertSame('https://xn--bcher-kva.example/', UrlNormalizer::normalize('https://bücher.example'));
    }

    public function test_relative_urls_resolve_against_a_base(): void
    {
        $base = 'https://example.com/docs/intro';

        $this->assertSame('https://example.com/docs/setup', UrlNormalizer::normalize('setup', $base));
        $this->assertSame('https://example.com/contact', UrlNormalizer::normalize('/contact', $base));
        $this->assertSame('https://example.com/contact', UrlNormalizer::normalize('../contact', $base));
        $this->assertSame('https://cdn.example.com/a', UrlNormalizer::normalize('//cdn.example.com/a', $base));
        $this->assertSame('https://example.com/docs/intro', UrlNormalizer::normalize('#section', $base));
    }

    public function test_query_handling(): void
    {
        $url = 'https://example.com/search?b=2&utm_medium=mail&a=1&PHPSESSID=abc&fbclid=x';

        $this->assertSame('https://example.com/search', UrlNormalizer::normalize($url));
        $this->assertSame('https://example.com/search?a=1&b=2', UrlNormalizer::normalize($url, keepQuery: true));
        $this->assertSame('https://example.com/a', UrlNormalizer::normalize('https://example.com/a;jsessionid=123ABC'));
    }

    public function test_unsupported_or_unsafe_urls_are_rejected(): void
    {
        foreach (['javascript:alert(1)', 'mailto:a@example.com', 'ftp://example.com/', 'https://user:pw@example.com/', 'https:///nohost', "https://exa\x00mple.com/a", '', 'https://'.str_repeat('a', 2100).'.com/'] as $url) {
            $this->assertNull(UrlNormalizer::normalize($url), $url);
        }
    }

    public function test_crawler_traps_are_detected(): void
    {
        $this->assertTrue(UrlNormalizer::looksLikeTrap('https://example.com/a/b/a/b/a/b/a/b/'));
        $this->assertTrue(UrlNormalizer::looksLikeTrap('https://example.com/'.implode('/', range(1, 20))));
        $this->assertTrue(UrlNormalizer::looksLikeTrap('https://example.com/?'.http_build_query(array_fill_keys(range('a', 'l'), 1))));
        $this->assertFalse(UrlNormalizer::looksLikeTrap('https://example.com/docs/api/v2/users'));
    }
}
