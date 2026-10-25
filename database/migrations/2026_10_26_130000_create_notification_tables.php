<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notifications (Laravel database channel).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient', 255)->nullable();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('channel', 20);
            $table->string('status', 20)->default('queued');
            $table->string('subject', 255)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_email')->default(false);
            $table->boolean('notify_in_app')->default(false);
            $table->string('notify_min_severity', 10)->default('warning');
            $table->boolean('notify_resolved')->default(true);
            $table->boolean('notify_dns_changes')->default(true);
        });

        // Administrators received alert e-mails before; keep that and add
        // in-app notifications for them. Other users opt in themselves.
        DB::table('users')->where('is_admin', true)->update(['notify_email' => true, 'notify_in_app' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'notify_in_app', 'notify_min_severity', 'notify_resolved', 'notify_dns_changes']);
        });

        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notifications');
    }
};
