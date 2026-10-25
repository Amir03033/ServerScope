<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 60)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('show_incident_titles')->default(false);
            $table->boolean('show_uptime')->default(true);
            $table->string('accent_color', 7)->default('#38bdf8');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('status_page_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 100);
            $table->string('description', 255)->nullable();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_components');
        Schema::dropIfExists('status_pages');
    }
};
