<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('server_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('alert_rule_id')
                ->constrained('alert_rules')
                ->cascadeOnDelete();

            $table->string('metric', 30);
            $table->decimal('observed_value', 12, 2);
            $table->decimal('threshold', 8, 2);

            $table->string('status', 20)->default('active');
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['alert_rule_id', 'status']);
            $table->index('triggered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
