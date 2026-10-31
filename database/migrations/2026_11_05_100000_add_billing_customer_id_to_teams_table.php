<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The customer id at the payment provider (one customer per team). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'billing_customer_id')) {
                $table->string('billing_customer_id')->nullable()->after('plan_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'billing_customer_id')) {
                $table->dropColumn('billing_customer_id');
            }
        });
    }
};
