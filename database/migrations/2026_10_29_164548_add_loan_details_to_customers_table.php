<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The loan the customer is applying for. Customers created before this have none.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('loan_amount', 12, 2)->nullable()->after('living_expenses');
            $table->unsignedSmallInteger('loan_term_months')->nullable()->after('loan_amount');
            $table->string('loan_purpose', 50)->nullable()->after('loan_term_months');
            $table->string('loan_purpose_details', 500)->nullable()->after('loan_purpose');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['loan_amount', 'loan_term_months', 'loan_purpose', 'loan_purpose_details']);
        });
    }
};
