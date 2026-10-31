<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Buyer details for invoices: name, address, country, vat_id.
            $table->json('billing_details')->nullable()->after('billing_customer_id');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Null while a draft (seller details not complete yet); gapless once issued.
            $table->string('number', 20)->nullable()->unique();
            $table->string('provider', 30);
            $table->string('provider_payment_id', 100);
            $table->string('plan', 20);
            $table->string('interval', 10);
            $table->string('currency', 3)->default('EUR');
            $table->unsignedInteger('amount_incl'); // cents
            $table->unsignedInteger('amount_excl');
            $table->unsignedInteger('vat_amount');
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->timestamp('paid_at');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->json('seller')->nullable();
            $table->json('buyer')->nullable();
            $table->string('vat_note')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_payment_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('billing_details');
        });
    }
};
