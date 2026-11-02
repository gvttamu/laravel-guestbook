<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DumpWorkflowTest extends TestCase
{
    public function test_prints_the_verification_workflow_as_a_labelled_graphviz_graph(): void
    {
        $this->assertSame(0, Artisan::call('workflows:dump', ['workflow' => 'customer-verification']));

        $dot = Artisan::output();
        $this->assertStringStartsWith('digraph workflow {', $dot);
        $this->assertStringContainsString('label="Customer verification"', $dot);
        $this->assertStringContainsString('[label=<<B>Contact match: awaiting review</B>>, shape=circle]', $dot);
        $this->assertStringContainsString('[label="Compliance approves" style="solid"]', $dot);
    }

    public function test_writes_the_graph_to_a_file(): void
    {
        $path = storage_path('framework/testing/financial-scoring.dot');
        File::ensureDirectoryExists(dirname($path));
        File::delete($path);

        $this->artisan('workflows:dump', ['workflow' => 'financial-scoring', '--output' => $path])->assertSuccessful();

        $this->assertStringContainsString('[label="Score affordability and credit" style="solid"]', File::get($path));
        File::delete($path);
    }

    public function test_an_unknown_workflow_is_refused(): void
    {
        $this->artisan('workflows:dump', ['workflow' => 'onboarding'])
            ->expectsOutputToContain('Choose one of: customer-verification, financial-scoring.')
            ->assertExitCode(2);
    }
}
