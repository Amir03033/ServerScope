<?php

namespace Tests\Unit\Websites;

use App\Services\Websites\RobotsTxt;
use PHPUnit\Framework\TestCase;

class RobotsTxtTest extends TestCase
{
    public function test_sitemaps_and_rules_for_the_wildcard_agent(): void
    {
        $robots = RobotsTxt::parse("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin/\nDisallow: /*.pdf$\nAllow: /admin/public\n# comment\nSitemap: https://example.com/sitemap.xml\nSitemap: https://example.com/news.xml\n");

        $this->assertSame(['https://example.com/sitemap.xml', 'https://example.com/news.xml'], $robots->sitemaps);
        $this->assertTrue($robots->isAllowed('/'));
        $this->assertFalse($robots->isAllowed('/admin/users'));
        $this->assertTrue($robots->isAllowed('/admin/public/page'));
        $this->assertFalse($robots->isAllowed('/files/report.pdf'));
        $this->assertTrue($robots->isAllowed('/files/report.pdf?download=1'));
    }

    public function test_specific_group_wins_and_empty_disallow_allows_all(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: ServerScope\nUser-agent: other\nDisallow:\n");
        $this->assertTrue($robots->isAllowed('/anything'));

        $this->assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: /\n")->isAllowed('/x'));
        $this->assertTrue(RobotsTxt::parse('')->isAllowed('/x'));
    }
}
