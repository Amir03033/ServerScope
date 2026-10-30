<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\ServerDiagnosis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * config/serverscope.php used to define the 'agent' key twice; the second
 * silently replaced the first, so latest_version and known_checksums
 * were always empty.
 */
class AgentConfigTest extends TestCase
{
    use RefreshDatabase;

    /** The config file as loaded from disk, with the given environment. */
    private function loadConfig(array $env): array
    {
        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            return require config_path('serverscope.php');
        } finally {
            foreach (array_keys($env) as $name) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
    }

    public function test_latest_version_known_checksums_and_package_settings_all_survive(): void
    {
        $config = $this->loadConfig([
            'SERVERSCOPE_AGENT_LATEST_VERSION' => '0.9.1',
            'SERVERSCOPE_AGENT_CHECKSUMS' => 'aaa, bbb',
        ]);

        $this->assertSame('0.9.1', $config['agent']['latest_version']);
        $this->assertSame(['aaa', 'bbb'], $config['agent']['known_checksums']);
        $this->assertArrayHasKey('source_path', $config['agent']);
    }

    public function test_the_loaded_configuration_flags_outdated_agents_and_unknown_builds(): void
    {
        config(['serverscope.agent' => $this->loadConfig([
            'SERVERSCOPE_AGENT_LATEST_VERSION' => '0.2.0',
            'SERVERSCOPE_AGENT_CHECKSUMS' => hash('sha256', 'release'),
        ])['agent']]);

        $server = Server::forceCreate(['name' => 'web-1', 'hostname' => 'web-1.test', 'agent_version' => '0.1.0', 'agent_checksum' => hash('sha256', 'other')]);
        $quality = app(ServerDiagnosis::class)->dataQuality($server);

        $this->assertTrue($quality['outdated']);
        $this->assertSame('unknown_build', $quality['integrity']);
    }
}
