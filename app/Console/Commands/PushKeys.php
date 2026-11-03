<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Prints a fresh VAPID key pair for web push (to put in .env once). */
class PushKeys extends Command
{
    protected $signature = 'serverscope:push:keys';

    protected $description = 'Generate the VAPID keys for push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Add these to .env (then php artisan config:cache). Keep the private key secret:');
        $this->line('PUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('PUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('PUSH_VAPID_SUBJECT=mailto:'.config('mail.from.address', 'hello@example.com'));
        $this->warn('Changing the keys later means every device has to turn push on again.');

        return self::SUCCESS;
    }
}
