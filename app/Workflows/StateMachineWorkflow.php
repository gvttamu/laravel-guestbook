<?php

namespace App\Workflows;

use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTransition;
use SplObjectStorage;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Dumper\MermaidDumper;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * A workflow run inside the app, as a Symfony state machine over a WorkflowInstance's
 * `status`. Subclasses describe their steps once, in places() and transitions(), and get
 * the state machine that enforces them, a Mermaid diagram that shows them, and a record
 * of every step each run takes.
 */
abstract class StateMachineWorkflow
{
    /**
     * The transition every in-app workflow has for a run that a newer run replaces.
     */
    public const SUPERSEDE = 'supersede';

    private ?StateMachine $stateMachine = null;

    /**
     * The workflow's name, as shown above its diagram.
     */
    abstract public function title(): string;

    /**
     * What the workflow does, in a sentence or two.
     */
    abstract public function description(): string;

    /**
     * Each place a run can be in, keyed by its status, with a label. The first is where every run starts.
     *
     * @return array<string, string>
     */
    abstract protected function places(): array;

    /**
     * Each transition, keyed by name.
     *
     * @return array<string, array{from: string|list<string>, to: string, label: string}>
     */
    abstract protected function transitions(): array;

    /**
     * The places a run hasn't finished in: those it can still be superseded from.
     *
     * @return list<string>
     */
    public function unfinishedPlaces(): array
    {
        return (array) $this->transitions()[self::SUPERSEDE]['from'];
    }

    /**
     * Each place's label, in the order the diagram numbers them (place0, place1, ...).
     *
     * @return array<string, string>
     */
    public function placeLabels(): array
    {
        return $this->places();
    }

    public function stateMachine(): StateMachine
    {
        return $this->stateMachine ??= new StateMachine($this->definition(), new StatusMarkingStore, name: static::class);
    }

    /**
     * Move the run along a transition and record the step, naming the person who made it, if
     * anyone did (automatic steps have nobody). Always use this rather than the state machine's own apply(), so no step goes
     * unrecorded. The run must already be saved; the caller saves its new status.
     */
    public function apply(WorkflowInstance $run, string $transition, ?User $by = null): void
    {
        $from = $run->status;

        $this->stateMachine()->apply($run, $transition);

        $run->transitions()->create([
            'transition' => $transition,
            'from' => $from,
            'to' => $run->status,
            'user_id' => $by?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Where a transition leads from the given place, or null when it can't be taken from there.
     */
    public function placeAfter(string $from, string $transition): ?string
    {
        $definition = $this->transitions()[$transition] ?? null;

        return $definition !== null && in_array($from, (array) $definition['from'], true) ? $definition['to'] : null;
    }

    /**
     * The places the run has been in, oldest first, starting where every run starts. Each
     * says when the run got there and how: the transition, who made it, and whether the step
     * was reconstructed from the audit log rather than recorded.
     *
     * @return list<array{place: string, label: string, at: string, transition: ?string, by: ?string, reconstructed: bool}>
     */
    public function history(WorkflowInstance $run): array
    {
        $start = [
            'place' => array_key_first($this->places()),
            'label' => $this->places()[array_key_first($this->places())],
            'at' => $run->created_at->toIso8601String(),
            'transition' => null,
            'by' => null,
            'reconstructed' => false,
        ];

        $steps = $run->transitions()->with('user:id,name')->get()->map(fn (WorkflowTransition $step): array => [
            'place' => $step->to,
            'label' => $this->places()[$step->to] ?? $step->to,
            'at' => $step->created_at->toIso8601String(),
            'transition' => $this->transitions()[$step->transition]['label'] ?? $step->transition,
            'by' => $step->user?->name,
            'reconstructed' => $step->reconstructed,
        ]);

        return [$start, ...$steps];
    }

    /**
     * The workflow as a Mermaid flowchart.
     */
    public function diagram(): string
    {
        return (new MermaidDumper(MermaidDumper::TRANSITION_TYPE_STATEMACHINE, MermaidDumper::DIRECTION_LEFT_TO_RIGHT))
            ->dump($this->definition());
    }

    private function definition(): Definition
    {
        $transitions = [];
        $transitionLabels = new SplObjectStorage;

        foreach ($this->transitions() as $name => $transition) {
            foreach ((array) $transition['from'] as $from) {
                $transitions[] = $step = new Transition($name, $from, $transition['to']);
                $transitionLabels[$step] = ['label' => $transition['label']];
            }
        }

        return (new DefinitionBuilder(array_keys($this->places()), $transitions))
            ->setInitialPlaces(array_key_first($this->places()))
            ->setMetadataStore(new InMemoryMetadataStore(
                placesMetadata: array_map(fn (string $label): array => ['label' => $label], $this->places()),
                transitionsMetadata: $transitionLabels,
            ))
            ->build();
    }
}
