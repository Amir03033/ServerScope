<?php

namespace Tests\Unit\Monitoring;

use App\Services\Domains\DomainNormalizer;
use PHPUnit\Framework\TestCase;

class DomainNormalizerTest extends TestCase
{
    public function test_normalizes_valid_input(): void
    {
        $n = new DomainNormalizer;

        $this->assertSame('example.com', $n->normalize('Example.COM'));
        $this->assertSame('example.com', $n->normalize('example.com.'));
        $this->assertSame('www.example.nl', $n->normalize('https://www.example.nl/pad?x=1#top'));
        $this->assertSame('shop.example.co.uk', $n->normalize('shop.example.co.uk:8443/winkel'));
        $this->assertSame('xn--bcher-kva.de', $n->normalize('Bücher.de'));
        $this->assertSame('xn--bcher-kva.de', $n->normalize('http://bücher.de/'));
        $this->assertSame('example.xn--p1ai', $n->normalize('example.рф'));
    }

    public function test_rejects_invalid_or_internal_names(): void
    {
        $n = new DomainNormalizer;

        foreach ([
            '', 'localhost', 'intranet', 'printer.local', 'db.internal', 'router.lan', 'svc.home.arpa',
            '127.0.0.1', '[::1]', 'http://10.0.0.1/', '192.168.1.1', 'exa mple.com', 'example..com',
            '-bad.com', 'bad-.com', 'user@example.com', 'example.123', 'example.c', str_repeat('a', 64).'.com',
            'foo.test', 'foo.example', 'foo.invalid',
        ] as $input) {
            $this->assertNull($n->normalize($input), "'{$input}' should be rejected");
        }
    }
}
