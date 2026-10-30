<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drafts belong to the user who started them, instead of to a browser session.
     * Drafts saved before this can't be traced to a user, so they're left without
     * one; nobody can open them and they're pruned after the usual 30 days.
     */
    public function up(): void
    {
        Schema::table('form_drafts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('form_type')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
