<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The whole schema, consolidated from the migrations up to 2026-10-30.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->text('message');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->unique();
            $table->string('phone', 30);
            $table->date('date_of_birth')->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->string('postal_code', 20);
            $table->string('country', 100);
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->decimal('other_income', 12, 2)->nullable();
            $table->decimal('rent_or_mortgage', 12, 2)->nullable();
            $table->decimal('car_payments', 12, 2)->nullable();
            $table->decimal('other_debt_payments', 12, 2)->nullable();
            $table->decimal('living_expenses', 12, 2)->nullable();
            $table->decimal('loan_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('loan_term_months')->nullable();
            $table->string('loan_purpose', 50)->nullable();
            $table->string('loan_purpose_details', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        // A draft of a new customer, or of changes to an existing one. A draft of changes
        // records which customer it edits, and their version and details when editing began.
        Schema::create('form_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('form_type');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('customer_version')->nullable();
            $table->unsignedSmallInteger('schema_version');
            $table->json('payload');
            $table->json('original_payload')->nullable();
            $table->string('current_step');
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->index('updated_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->string('workflow');
            $table->morphs('subject');
            $table->string('external_id')->nullable();
            $table->string('status');
            $table->string('current_task')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('transition');
            $table->string('from');
            $table->string('to');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('reconstructed')->default(false);
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('form_drafts');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('entries');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
