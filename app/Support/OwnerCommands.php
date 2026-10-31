<?php

namespace App\Support;

/**
 * The commands a platform owner sometimes has to run. The texts (title,
 * when to use it) are in the owner lang files under "cmd.<id>". A test makes
 * sure every serverscope:* command is listed here.
 *
 * app: "serverscope" = run in the ServerScope directory, "info" = in the
 * ServerScope-Info directory. danger: changes or removes data.
 */
class OwnerCommands
{
    /** group => command ids, in display order */
    public const GROUPS = [
        'deploy' => ['migrate', 'config_clear', 'cron', 'queue'],
        'accounts' => ['create_admin', 'create_team', 'team_plan'],
        'billing' => ['invoices_issue', 'billing_grace'],
        'monitoring' => ['check_agents', 'dispatch_domains', 'dispatch_pages', 'analyze', 'escalate'],
        'data' => ['prune_dry', 'prune'],
        'releases' => ['release_list', 'release_import', 'release_withdraw'],
        'info' => ['site_defaults', 'info_admin', 'waitlist_dry', 'waitlist_send', 'docs_import', 'docs_verify', 'docs_links'],
    ];

    /** id => [command, app, danger] */
    public const COMMANDS = [
        'migrate' => ['php artisan migrate --force', 'serverscope', false],
        'config_clear' => ['php artisan config:clear', 'serverscope', false],
        'cron' => ['* * * * * cd /pad/naar/serverscope && php artisan schedule:run >> /dev/null 2>&1', 'serverscope', false],
        'queue' => ['php artisan queue:work --stop-when-empty', 'serverscope', false],
        'create_admin' => ['php artisan serverscope:create-admin --platform', 'serverscope', false],
        'create_team' => ['php artisan serverscope:create-team "Teamnaam" --owner=iemand@example.com', 'serverscope', false],
        'team_plan' => ['php artisan serverscope:team:plan <team-id-of-slug> pro', 'serverscope', true],
        'invoices_issue' => ['php artisan serverscope:invoices:issue', 'serverscope', false],
        'billing_grace' => ['php artisan serverscope:billing:expire-grace', 'serverscope', true],
        'check_agents' => ['php artisan serverscope:check-agents', 'serverscope', false],
        'dispatch_domains' => ['php artisan serverscope:dispatch-domain-checks', 'serverscope', false],
        'dispatch_pages' => ['php artisan serverscope:dispatch-page-checks', 'serverscope', false],
        'analyze' => ['php artisan serverscope:analyze', 'serverscope', false],
        'escalate' => ['php artisan serverscope:escalate-incidents', 'serverscope', false],
        'prune_dry' => ['php artisan serverscope:prune --dry-run', 'serverscope', false],
        'prune' => ['php artisan serverscope:prune', 'serverscope', true],
        'release_list' => ['php artisan serverscope:release:list', 'serverscope', false],
        'release_import' => ['php artisan serverscope:release:import /pad/serverscope-agent-X.Y.Z.bundle.tar --admin=jij@example.com', 'serverscope', false],
        'release_withdraw' => ['php artisan serverscope:release:withdraw X.Y.Z --admin=jij@example.com --reason="reden"', 'serverscope', true],
        'site_defaults' => ['php artisan site:defaults', 'info', false],
        'info_admin' => ['php artisan info:admin jij@example.com --role=admin', 'info', false],
        'waitlist_dry' => ['php artisan waitlist:notify', 'info', false],
        'waitlist_send' => ['php artisan waitlist:notify --send', 'info', true],
        'docs_import' => ['php artisan docs:import', 'info', false],
        'docs_verify' => ['php artisan docs:verify', 'info', false],
        'docs_links' => ['php artisan docs:check-links', 'info', false],
    ];

    /** Every artisan command mentioned here (for the drift test). */
    public static function artisanNames(): array
    {
        return collect(self::COMMANDS)->map(fn ($c) => preg_match('/artisan\s+(\S+)/', $c[0], $m) ? $m[1] : null)->filter()->unique()->values()->all();
    }
}
