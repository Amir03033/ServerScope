<?php

namespace App\Services;

use App\Models\EnrollmentToken;
use App\Models\Server;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\Tenancy;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * One-time enrollment tokens: install.sh exchanges one for a normal agent
 * credential of a new server in the token's team.
 *
 * Tokens are "sse_" + 48 hex characters; only their SHA-256 hash is
 * stored. Neither the token nor the issued credential is ever logged.
 */
class EnrollmentService
{
    public const PATTERN = '/\Asse_[a-f0-9]{48}\z/';

    public function __construct(
        private ServerRegistrationService $registration,
    ) {}

    /**
     * Create a token for the current team. The plaintext is returned once.
     *
     * @return array{0: EnrollmentToken, 1: string}
     */
    public function create(User $by, ?string $label = null): array
    {
        $plain = 'sse_'.bin2hex(random_bytes(24));

        $token = EnrollmentToken::query()->create([
            'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
            'prefix' => substr($plain, 0, 10),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes(max(1, (int) config('serverscope.enrollment.ttl_minutes', 60))),
            'created_by' => $by->id,
        ]);

        Audit::log('enrollment.created', 'Koppeltoken '.$token->prefix.'… aangemaakt'.($token->label ? " ({$token->label})" : ''), null, [
            'enrollment_token_id' => $token->id,
            'expires_at' => $token->expires_at->toIso8601String(),
        ]);

        return [$token, $plain];
    }

    /** Pending tokens of the current team. */
    public function pendingCount(): int
    {
        return EnrollmentToken::query()->pending()->count();
    }

    public function revoke(EnrollmentToken $token, User $by): void
    {
        $revoked = EnrollmentToken::query()->withoutGlobalScopes()->whereKey($token->id)
            ->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $by->id, 'updated_at' => now()]);

        if ($revoked === 1) {
            Audit::log('enrollment.revoked', 'Koppeltoken '.$token->prefix.'… ingetrokken', null, ['enrollment_token_id' => $token->id]);
        }
    }

    /**
     * Exchange a token for a new server with an agent credential. The team
     * comes only from the token. The claim is a single conditional update,
     * so two concurrent requests can never both succeed; when creating the
     * server fails, the transaction is rolled back and the token stays
     * usable. Returns null for every unusable token (unknown, expired,
     * used, revoked) without saying which.
     *
     * @param  array{hostname: string, os: string, arch: string, agent_version: string}  $facts
     * @return array{server: Server, agent_token: string, enrollment: EnrollmentToken}|null
     */
    public function redeem(string $plain, array $facts, ?string $ip): ?array
    {
        if (preg_match(self::PATTERN, $plain) !== 1) {
            return null;
        }

        $hash = hash('sha256', $plain);

        return DB::transaction(function () use ($hash, $facts, $ip): ?array {
            $token = EnrollmentToken::query()->withoutGlobalScopes()->where('token_hash', $hash)->first();

            if ($token === null) {
                return null;
            }

            $claimed = EnrollmentToken::query()->withoutGlobalScopes()->whereKey($token->id)
                ->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                ->update(['used_at' => now(), 'used_ip' => $ip, 'reported' => json_encode($facts), 'updated_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            return Tenant::run($token->team_id, function () use ($token, $facts): array {
                $result = $this->registration->register([
                    'name' => mb_substr($facts['hostname'], 0, 100),
                    'hostname' => $facts['hostname'],
                    'operating_system' => $facts['os'],
                    'agent_version' => $facts['agent_version'],
                ], 'Installatiescript ('.$facts['arch'].')');

                $server = $result['server'];
                EnrollmentToken::query()->withoutGlobalScopes()->whereKey($token->id)->update(['server_id' => $server->id]);

                Audit::log('enrollment.used', "Server {$server->name} gekoppeld met koppeltoken {$token->prefix}…", $server, [
                    'enrollment_token_id' => $token->id,
                    'hostname' => $facts['hostname'],
                    'os' => $facts['os'],
                    'arch' => $facts['arch'],
                    'agent_version' => $facts['agent_version'],
                ]);

                return ['server' => $server, 'agent_token' => $result['token'], 'enrollment' => $token->fresh()];
            });
        });
    }

    /** The team of a token, for callers outside a tenant. */
    public static function teamOf(EnrollmentToken $token): ?int
    {
        return Tenancy::teamIdOf($token);
    }
}
