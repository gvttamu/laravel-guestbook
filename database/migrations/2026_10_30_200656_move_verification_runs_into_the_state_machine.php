<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Verification moved from the Flowable engine into the app's CustomerVerification state
 * machine, whose places split the engine's "flagged" and "running" statuses by step.
 * Unfinished runs keep their place: a flagged run waits for review of the step it was
 * flagged at, and a running run that has had a review is waiting for its address check.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->verificationRuns(['flagged', 'running'])->each(function (object $run): void {
            $result = json_decode($run->result ?? 'null', true) ?? [];

            $status = match ($run->status) {
                'flagged' => ($result['failed_step'] ?? null) === 'address' ? 'address_review' : 'contact_review',
                'running' => ($result['reviews'] ?? []) === [] ? 'pending' : 'address_check',
            };

            DB::table('workflow_instances')->where('id', $run->id)->update(['status' => $status]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $statuses = ['contact_review' => 'flagged', 'address_review' => 'flagged', 'address_check' => 'running'];

        $this->verificationRuns(array_keys($statuses))->each(
            fn (object $run) => DB::table('workflow_instances')->where('id', $run->id)->update(['status' => $statuses[$run->status]]),
        );
    }

    /**
     * @param  list<string>  $statuses
     */
    private function verificationRuns(array $statuses): Collection
    {
        return DB::table('workflow_instances')
            ->where('workflow', 'customer-verification')
            ->whereIn('status', $statuses)
            ->get(['id', 'status', 'result']);
    }
};
