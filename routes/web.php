<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerRegistrationController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerDetailController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertRuleController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AgentCredentialController;
use App\Http\Controllers\DependencyController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicStatusPageController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerMaintenanceController;
use App\Http\Controllers\ServiceCheckController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WebsiteDiscoveryController;
use App\Http\Controllers\WebsitePageController;

Route::redirect('/', '/dashboard');

// Invitation links: the token is the credential; accepting needs the matching account.
Route::get('/invitations/{token}', [\App\Http\Controllers\InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('invitations.show');
Route::get('/invitations/{token}/login', function (string $token) {
    session()->put('url.intended', route('invitations.show', $token));

    return redirect()->route('login');
})->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('invitations.login');
Route::post('/invitations/{token}/register', [\App\Http\Controllers\InvitationController::class, 'register'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('invitations.register');
Route::post('/invitations/{token}/accept', [\App\Http\Controllers\InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{48}')->middleware(['auth', 'throttle:10,1'])->name('invitations.accept');

Route::middleware('guest')->group(function () {
    Route::get('/login', [
        AuthenticatedSessionController::class,
        'create',
    ])->name('login');

    Route::post('/login', [
        AuthenticatedSessionController::class,
        'store',
    ])->middleware('throttle:10,1');

    // Public sign-up; 404 unless serverscope.tenancy.registration_enabled.
    Route::get('/register', [\App\Http\Controllers\Auth\RegisterController::class, 'create'])->name('register');
    Route::post('/register', [\App\Http\Controllers\Auth\RegisterController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/register/sent', [\App\Http\Controllers\Auth\RegisterController::class, 'sent'])->name('register.sent');

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');

    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.challenge.store');
    Route::post('/two-factor-challenge/cancel', [TwoFactorController::class, 'cancel'])->name('two-factor.challenge.cancel');
});

// Language switch for visitors and signed-in users (public; only en/nl).
Route::post('/locale', [\App\Http\Controllers\LocaleController::class, 'update'])->middleware('throttle:30,1')->name('locale.update');

Route::get('/agent/ping', function (Request $request) {
    $server = $request->attributes->get('agentServer');

    return response()->json([
        'authenticated' => true,
        'server_id' => $server->id,
        'server_name' => $server->name,
    ]);
})->middleware(AuthenticateAgent::class)->name('agent.ping');

// One-command installation: the script and the agent package (public,
// no secrets, no team data; the team comes from the enrollment token).
Route::get('/install.sh', [\App\Http\Controllers\AgentDownloadController::class, 'script'])->middleware('throttle:60,1')->name('agent.install-script');
Route::get('/agent/serverscope-agent-{version}.tar.gz', [\App\Http\Controllers\AgentDownloadController::class, 'package'])->where('version', '\d+\.\d+\.\d+')->middleware('throttle:30,1')->name('agent.package');
Route::get('/agent/serverscope-agent-{version}.tar.gz.sha256', [\App\Http\Controllers\AgentDownloadController::class, 'checksum'])->where('version', '\d+\.\d+\.\d+')->middleware('throttle:60,1')->name('agent.checksum');
// Signed releases (phase 3): only imported, not withdrawn releases.
Route::get('/agent/releases/{version}/manifest', [\App\Http\Controllers\AgentDownloadController::class, 'manifest'])->where('version', '\d+\.\d+\.\d+')->middleware('throttle:60,1')->name('agent.release.manifest');
Route::get('/agent/releases/{version}/manifest.sig', [\App\Http\Controllers\AgentDownloadController::class, 'signature'])->where('version', '\d+\.\d+\.\d+')->middleware('throttle:60,1')->name('agent.release.signature');
Route::get('/agent/releases/{version}/{file}', [\App\Http\Controllers\AgentDownloadController::class, 'tarball'])->where(['version' => '\d+\.\d+\.\d+', 'file' => 'serverscope-agent-\d+\.\d+\.\d+\.tar\.gz'])->middleware('throttle:30,1')->name('agent.release.tarball');

// Public status pages (no login unless the page is private).
Route::get('/status/{slug}.json', [PublicStatusPageController::class, 'json'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1')->name('status.json');
Route::get('/status/{slug}', [PublicStatusPageController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1')->name('status.show');

// Signed in but not necessarily verified: the verification screens and sign-out.
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verification-notification', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'resend'])->middleware('throttle:3,1')->name('verification.send');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// The link in the verification mail: signed, works without a session.
Route::get('/email/verify/{id}/{hash}', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'verify'])
    ->whereNumber('id')->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'show'])->name('billing.show');
    Route::post('/billing/checkout', [\App\Http\Controllers\BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
    Route::get('/billing/invoices', [\App\Http\Controllers\InvoiceController::class, 'index'])->name('billing.invoices');
    Route::get('/billing/invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'show'])->name('billing.invoice');
    Route::put('/billing/details', [\App\Http\Controllers\InvoiceController::class, 'details'])->name('billing.details');
    Route::post('/billing/cancel', [\App\Http\Controllers\BillingController::class, 'cancel'])->middleware('throttle:5,1')->name('billing.cancel');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');
    Route::put('/profile/notifications', [ProfileController::class, 'notifications'])->name('profile.notifications');
    Route::post('/profile/notifications/test', [ProfileController::class, 'testNotification'])->name('profile.notifications.test');

    Route::post('/profile/two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('/profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:6,1')->name('two-factor.recovery-codes');
    Route::delete('/profile/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:6,1')->name('two-factor.show');

    Route::get('/profile/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/profile/api-tokens', [ApiTokenController::class, 'store'])->middleware('throttle:10,1')->name('api-tokens.store');
    Route::delete('/profile/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/print', [ReportController::class, 'print'])->middleware('throttle:20,1')->name('reports.print');
    Route::get('/reports/export', [ReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    // Team administrators see the scheduler and their own team; the
    // platform administrator sees the whole platform, including the queue.
    Route::get('/system-health', function (\App\Services\SystemHealth $health) {
        $platform = \Illuminate\Support\Facades\Gate::allows('platform');
        abort_unless($platform || \Illuminate\Support\Facades\Gate::allows('admin'), 403);

        return view('system-health', ['report' => $health->report($platform ? null : \App\Tenancy\Tenant::id())]);
    })->name('system-health');
    Route::get('/platform/commands', \App\Http\Controllers\OwnerCommandsController::class)->name('platform.commands');
    Route::get('/platform/agent-releases', \App\Http\Controllers\AgentReleaseOverviewController::class)->name('platform.agent-releases');

    Route::post('/teams/{team}/switch', [\App\Http\Controllers\TeamController::class, 'switch'])->middleware('throttle:20,1')->name('teams.switch');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->middleware('throttle:10,1')->name('users.store');
    Route::put('/team/escalation', [UserController::class, 'updateEscalation'])->middleware('throttle:10,1')->name('team.escalation');
    Route::post('/users/invitations/{invitation}/resend', [UserController::class, 'resendInvitation'])->middleware('throttle:10,1')->name('users.invitations.resend');
    Route::delete('/users/invitations/{invitation}', [UserController::class, 'revokeInvitation'])->name('users.invitations.revoke');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/status-pages', [StatusPageController::class, 'index'])->name('status-pages.index');
    Route::get('/status-pages/create', [StatusPageController::class, 'create'])->name('status-pages.create');
    Route::post('/status-pages', [StatusPageController::class, 'store'])->name('status-pages.store');
    Route::get('/status-pages/{statusPage}/edit', [StatusPageController::class, 'edit'])->name('status-pages.edit');
    Route::put('/status-pages/{statusPage}', [StatusPageController::class, 'update'])->name('status-pages.update');
    Route::delete('/status-pages/{statusPage}', [StatusPageController::class, 'destroy'])->name('status-pages.destroy');

    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::get('/webhooks/create', [WebhookController::class, 'create'])->name('webhooks.create');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
    Route::get('/webhooks/{webhook}/edit', [WebhookController::class, 'edit'])->name('webhooks.edit');
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('/webhooks/{webhook}/secret', [WebhookController::class, 'rotateSecret'])->name('webhooks.secret');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('webhooks.test');
    Route::post('/webhooks/{webhook}/deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])->name('webhooks.redeliver');

    Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');

    Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    // One-command installation (Linux) with one-time enrollment tokens.
    Route::get('/servers/add', [\App\Http\Controllers\EnrollmentTokenController::class, 'add'])->name('servers.add');
    Route::post('/enrollment-tokens', [\App\Http\Controllers\EnrollmentTokenController::class, 'store'])->middleware('throttle:20,1')->name('enrollment-tokens.store');
    Route::delete('/enrollment-tokens/{enrollmentToken}', [\App\Http\Controllers\EnrollmentTokenController::class, 'destroy'])->name('enrollment-tokens.destroy');
    Route::get('/enrollment-tokens/{enrollmentToken}/status', [\App\Http\Controllers\EnrollmentTokenController::class, 'status'])->middleware('throttle:120,1')->name('enrollment-tokens.status');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');

    Route::get('/servers/{server}', ServerDetailController::class)
        ->withTrashed()
        ->name('servers.show');

    Route::get('/servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
    Route::put('/servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servers/{server}/restore', [ServerController::class, 'restore'])
        ->withTrashed()
        ->name('servers.restore');
    Route::delete('/servers/{server}/permanent', [ServerController::class, 'forceDestroy'])
        ->withTrashed()
        ->name('servers.force-destroy');

    Route::post('/servers/{server}/maintenance', [ServerMaintenanceController::class, 'store'])
        ->name('servers.maintenance.start');
    Route::delete('/servers/{server}/maintenance', [ServerMaintenanceController::class, 'destroy'])
        ->name('servers.maintenance.end');

    Route::put('/services/{service}', [ServiceCheckController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceCheckController::class, 'destroy'])->name('services.destroy');

    // Agent updates (signed releases only; team administrators).
    Route::post('/servers/agent-update-all', [\App\Http\Controllers\AgentUpdateRequestController::class, 'storeAll'])->middleware('throttle:10,1')->name('servers.agent-update-all');
    Route::post('/servers/{server}/agent-update', [\App\Http\Controllers\AgentUpdateRequestController::class, 'store'])->middleware('throttle:30,1')->name('servers.agent-update');
    Route::put('/servers/{server}/auto-update', [\App\Http\Controllers\AgentUpdateRequestController::class, 'autoUpdate'])->name('servers.auto-update');

    Route::post('/servers/{server}/credentials', [AgentCredentialController::class, 'store'])
        ->name('servers.credentials.store');
    Route::post('/servers/{server}/credentials/rotate', [AgentCredentialController::class, 'rotate'])
        ->name('servers.credentials.rotate');
    Route::delete('/servers/{server}/credentials/{agentCredential}', [AgentCredentialController::class, 'destroy'])
        ->scopeBindings()
        ->name('servers.credentials.destroy');

    Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('/domains/create', [DomainController::class, 'create'])->name('domains.create');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->withTrashed()->name('domains.show');
    Route::get('/domains/{domain}/edit', [DomainController::class, 'edit'])->name('domains.edit');
    Route::put('/domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('/domains/{domain}/pause', [DomainController::class, 'pause'])->name('domains.pause');
    Route::post('/domains/{domain}/resume', [DomainController::class, 'resume'])->name('domains.resume');
    Route::post('/domains/{domain}/check', [DomainController::class, 'checkNow'])->name('domains.check');
    Route::post('/domains/{domain}/restore', [DomainController::class, 'restore'])->withTrashed()->name('domains.restore');
    Route::delete('/domains/{domain}/permanent', [DomainController::class, 'forceDestroy'])->withTrashed()->name('domains.force-destroy');

    // Website (multi-page) monitoring
    Route::get('/domains/{domain}/pages', [WebsitePageController::class, 'index'])->name('websites.pages');
    Route::get('/domains/{domain}/pages/create', [WebsitePageController::class, 'create'])->name('websites.pages.create');
    Route::post('/domains/{domain}/pages', [WebsitePageController::class, 'store'])->middleware('throttle:20,1')->name('websites.pages.store');
    Route::post('/domains/{domain}/pages/bulk', [WebsitePageController::class, 'bulk'])->middleware('throttle:30,1')->name('websites.pages.bulk');
    Route::get('/domains/{domain}/discovery', [WebsiteDiscoveryController::class, 'show'])->name('websites.discovery');
    Route::put('/domains/{domain}/discovery', [WebsiteDiscoveryController::class, 'update'])->name('websites.discovery.update');
    Route::post('/domains/{domain}/discovery/run', [WebsiteDiscoveryController::class, 'run'])->middleware('throttle:10,1')->name('websites.discovery.run');
    Route::get('/pages/{page}', [WebsitePageController::class, 'show'])->name('pages.show');
    Route::get('/pages/{page}/edit', [WebsitePageController::class, 'edit'])->name('pages.edit');
    Route::put('/pages/{page}', [WebsitePageController::class, 'update'])->name('pages.update');
    Route::delete('/pages/{page}', [WebsitePageController::class, 'destroy'])->name('pages.destroy');
    Route::post('/pages/{page}/pause', [WebsitePageController::class, 'pause'])->name('pages.pause');
    Route::post('/pages/{page}/resume', [WebsitePageController::class, 'resume'])->name('pages.resume');
    Route::post('/pages/{page}/check', [WebsitePageController::class, 'check'])->name('pages.check');
    Route::post('/page-changes/{change}/acknowledge', [WebsitePageController::class, 'acknowledgeChange'])->name('page-changes.acknowledge');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('/incidents/{incident}/learning', [IncidentController::class, 'saveLearning'])->middleware('throttle:30,1')->name('incidents.learning');
    Route::get('/incidents/{incident}/time-machine', [IncidentController::class, 'timeMachine'])->middleware('throttle:30,1')->name('incidents.time-machine');
    Route::post('/incidents/{incident}/status', [IncidentController::class, 'transition'])->name('incidents.transition');
    Route::post('/incidents/{incident}/assign', [IncidentController::class, 'assign'])->name('incidents.assign');
    Route::post('/incidents/{incident}/detach', [IncidentController::class, 'detach'])->name('incidents.detach');
    Route::post('/incidents/{incident}/severity', [IncidentController::class, 'severity'])->name('incidents.severity');
    Route::post('/incidents/{incident}/comments', [IncidentController::class, 'comment'])
        ->middleware('throttle:30,1')
        ->name('incidents.comment');

    Route::get('/deployments', [\App\Http\Controllers\DeploymentController::class, 'index'])->name('deployments.index');
    Route::get('/deployments/{deployment}', [\App\Http\Controllers\DeploymentController::class, 'show'])->name('deployments.show');

    Route::get('/dependencies', [DependencyController::class, 'index'])->name('dependencies.index');
    Route::post('/dependencies', [DependencyController::class, 'store'])->name('dependencies.store');
    Route::delete('/dependencies/{dependency}', [DependencyController::class, 'destroy'])->name('dependencies.destroy');

    Route::get('/alerts', AlertController::class)->name('alerts.index');
    Route::delete('/alerts/{alert}', [AlertController::class, 'destroy'])->name('alerts.destroy');
    Route::delete('/alerts', [AlertController::class, 'clear'])->name('alerts.clear');

    Route::get('/alert-rules', [AlertRuleController::class, 'index'])
        ->name('alert-rules.index');

    Route::get('/alert-rules/create', [AlertRuleController::class, 'create'])
        ->name('alert-rules.create');

    Route::post('/alert-rules', [AlertRuleController::class, 'store'])
        ->name('alert-rules.store');

    Route::get('/alert-rules/{alertRule}/edit', [AlertRuleController::class, 'edit'])
        ->name('alert-rules.edit');

    Route::put('/alert-rules/{alertRule}', [AlertRuleController::class, 'update'])
        ->name('alert-rules.update');

    Route::delete('/alert-rules/{alertRule}', [AlertRuleController::class, 'destroy'])
        ->name('alert-rules.destroy');

    Route::post('/servers/register', [
        ServerRegistrationController::class,
        'store',
    ])->name('servers.register');
});
