<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drafts of changes to an existing customer record which customer they edit, and
     * the customer's version and details when editing began.
     */
    public function up(): void
    {
        Schema::table('form_drafts', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('form_type')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('customer_version')->nullable()->after('customer_id');
            $table->json('original_payload')->nullable()->after('payload');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['customer_version', 'original_payload']);
        });
    }
};
