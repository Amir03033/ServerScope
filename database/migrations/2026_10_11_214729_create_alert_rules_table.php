<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('metric', 30);
            $table->decimal('threshold', 8, 2);
            $table->string('operator', 10)->default('>=');
            $table->unsignedSmallInteger('duration_minutes')->default(1);
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->index(['is_enabled', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
