<?php

namespace App\Http\Controllers;

use App\Models\WorkflowInstance;
use App\Workflows\StateMachineWorkflow;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowController extends Controller
{
    /**
     * Show a workflow as a diagram drawn from its state machine. With `?run=`, the page
     * also gets the steps that run has taken, to highlight where it is and replay how it got there.
     */
    public function show(Request $request, string $workflow): Response
    {
        /** @var StateMachineWorkflow $definition */
        $definition = app(WorkflowInstance::STATE_MACHINES[$workflow]);

        $run = $request->filled('run')
            ? WorkflowInstance::where('workflow', $workflow)->with('subject')->findOrFail($request->integer('run'))
            : null;

        return Inertia::render('workflows/Show', [
            'title' => $definition->title(),
            'description' => $definition->description(),
            'diagram' => $definition->diagram(),
            // The diagram numbers places in this order: place0, place1, ...
            'places' => array_keys($definition->placeLabels()),
            'run' => $run === null ? null : [
                'id' => $run->id,
                'status' => $run->status,
                'customer' => $run->subject->only(['id', 'first_name', 'last_name']),
                'history' => $definition->history($run),
            ],
        ]);
    }
}
