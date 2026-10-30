<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('form_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('form_type');
            $table->unsignedSmallInteger('schema_version');
            $table->json('payload');
            $table->string('current_step');
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_drafts');
    }
};
