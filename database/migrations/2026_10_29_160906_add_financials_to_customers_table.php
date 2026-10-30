<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Amounts are per month. Customers created before this have none.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('monthly_income', 12, 2)->nullable()->after('country');
            $table->decimal('other_income', 12, 2)->nullable()->after('monthly_income');
            $table->decimal('rent_or_mortgage', 12, 2)->nullable()->after('other_income');
            $table->decimal('car_payments', 12, 2)->nullable()->after('rent_or_mortgage');
            $table->decimal('other_debt_payments', 12, 2)->nullable()->after('car_payments');
            $table->decimal('living_expenses', 12, 2)->nullable()->after('other_debt_payments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['monthly_income', 'other_income', 'rent_or_mortgage', 'car_payments', 'other_debt_payments', 'living_expenses']);
        });
    }
};
