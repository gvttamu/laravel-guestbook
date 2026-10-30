<?php

namespace App\Workflows;

use App\Models\WorkflowInstance;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Keeps a state machine's place in a WorkflowInstance's `status` attribute. Symfony's own
 * MethodMarkingStore can't see Eloquent attributes, which are neither methods nor
 * declared properties. Saving the run is left to the caller.
 */
class StatusMarkingStore implements MarkingStoreInterface
{
    /**
     * @param  WorkflowInstance  $subject
     */
    public function getMarking(object $subject): Marking
    {
        return new Marking($subject->status === null ? [] : [$subject->status => 1]);
    }

    /**
     * @param  WorkflowInstance  $subject
     * @param  array<string, mixed>  $context
     */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $subject->status = array_key_first($marking->getPlaces());
    }
}
