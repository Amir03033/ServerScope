<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;
use App\Services\ServerDiagnosis;

/**
 * A server without agent data: is the host down, or only the monitoring
 * agent? Decided like ServerDiagnosis (ServerDiagnosis::classifyOffline),
 * but on the checks of the websites on that server during the incident.
 * Without such independent checks the cause stays unknown: a silent agent
 * is never reported as an unreachable host.
 */
final class AgentOrHostRule implements Rule
{
    private const BASE = [
        'agent_unhealthy' => 'medium',
        'host_unreachable' => 'medium',
        'agent_offline_mixed' => 'low',
        'agent_offline_unknown' => 'insufficient',
    ];

    public function evaluate(InvestigationContext $context): array
    {
        $servers = [];

        if ($context->incident['source'] === 'agent' && str_starts_with((string) $context->subject(), 'server:')) {
            $servers[$context->subject()] = array_key_first($context->items('onset'));
        }

        foreach ($context->items(['other_opened', 'other_ongoing'], fn ($i) => ($i['params']['source'] ?? null) === 'agent' && str_starts_with((string) $i['resource'], 'server:') && $context->isUpstreamOrSubject($i['resource'])) as $key => $item) {
            $servers[$item['resource']] ??= $key;
        }

        $candidates = [];

        foreach ($servers as $server => $signal) {
            $deps = $context->items('dependent_checks', fn ($i) => $i['params']['server'] === $server);
            $up = array_keys(array_filter($deps, fn ($i) => $i['params']['ok'] > 0 && $i['params']['failed'] === 0));
            $down = array_keys(array_filter($deps, fn ($i) => $i['params']['failed'] > 0));
            $state = ServerDiagnosis::classifyOffline($up !== [], $down !== []);
            $silent = array_keys($context->items('agent_silent', fn ($i) => $i['resource'] === $server));

            $supporting = array_values(array_filter([$signal, ...$silent, ...match ($state) {
                'agent_unhealthy' => $up,
                'host_unreachable' => $down,
                default => [...$up, ...$down],
            }]));

            $candidates[] = new Candidate($state, $server, self::BASE[$state], $state === 'agent_offline_unknown' ? ['no_independent_checks'] : ['independent_checks'],
                $supporting, $state === 'agent_unhealthy' ? $down : ($state === 'host_unreachable' ? $up : []),
                $state === 'agent_offline_unknown' ? ['independent_checks'] : [], $context->path($server), ['incidents', 'domain_checks', 'page_checks'], $server, priority: 2);
        }

        return $candidates;
    }
}
