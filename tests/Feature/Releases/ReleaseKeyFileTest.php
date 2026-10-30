<?php

namespace Tests\Feature\Releases;

use App\Releases\ReleaseKeys;
use Tests\TestCase;

/**
 * The public release key in the repository: named after its key ID, and
 * the fingerprint published in agent/README.md is the one of that key.
 */
class ReleaseKeyFileTest extends TestCase
{
    public function test_the_committed_public_key_matches_its_name_and_the_published_fingerprint(): void
    {
        $files = glob(resource_path('agent/release-keys/*.pub'));
        $this->assertCount(1, $files);

        $public = ReleaseKeys::fromPem((string) file_get_contents($files[0]));
        $this->assertSame('511215f3eccc6640', ReleaseKeys::keyId($public));
        $this->assertSame('511215f3eccc6640.pub', basename($files[0]));
        $this->assertSame('511215f3eccc6640c9ee8d42443d13501e2f291e03aa566e21e1df5efa0a1488', ReleaseKeys::fingerprint($public));
        $this->assertStringContainsString(ReleaseKeys::fingerprint($public), (string) file_get_contents(base_path('agent/README.md')));

        // Only a public key, never anything private.
        $this->assertStringNotContainsString('PRIVATE', (string) file_get_contents($files[0]));
        $this->assertSame(['511215f3eccc6640' => $public], app(ReleaseKeys::class)->trusted());
    }
}
