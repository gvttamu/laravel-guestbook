<?php

namespace Tests\Feature;

use App\Jobs\NotifyOwnerOfNewEntry;
use App\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GuestbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_an_empty_state(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Guestbook')
                ->has('entries', 0)
            );
    }

    public function test_it_lists_entries_newest_first(): void
    {
        Entry::create(['name' => 'Ada', 'message' => 'First']);
        $this->travel(1)->minutes();
        Entry::create(['name' => 'Grace', 'message' => 'Second']);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Guestbook')
                ->has('entries', 2)
                ->where('entries.0.message', 'Second')
                ->where('entries.1.message', 'First')
            );
    }

    public function test_it_signs_the_guestbook(): void
    {
        $this->post('/', ['name' => '  John ', 'message' => ' Saying something nice '])
            ->assertRedirect('/');

        $this->assertDatabaseHas('entries', ['name' => 'John', 'message' => 'Saying something nice']);
    }

    public function test_signing_queues_an_owner_notification_for_the_new_entry(): void
    {
        config(['guestbook.owner_email' => 'owner@example.com']);
        Queue::fake([NotifyOwnerOfNewEntry::class]);

        $this->post('/', ['name' => 'John', 'message' => 'Hi']);

        Queue::assertPushed(NotifyOwnerOfNewEntry::class, fn (NotifyOwnerOfNewEntry $job) => $job->entry->name === 'John');
    }

    public function test_signing_does_not_queue_an_owner_notification_without_an_owner_email(): void
    {
        config(['guestbook.owner_email' => null]);
        Queue::fake([NotifyOwnerOfNewEntry::class]);

        $this->post('/', ['name' => 'John', 'message' => 'Hi']);

        Queue::assertNotPushed(NotifyOwnerOfNewEntry::class);
    }

    public function test_both_fields_are_required(): void
    {
        $this->from('/')
            ->post('/', ['name' => 'John', 'message' => '   '])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['message' => 'Both fields are required.']);

        $this->assertDatabaseCount('entries', 0);
    }

    public function test_long_input_is_rejected(): void
    {
        $this->from('/')
            ->post('/', ['name' => str_repeat('a', 81), 'message' => 'Hi'])
            ->assertSessionHasErrors(['name' => 'That’s a bit long. Please shorten it.']);

        $this->assertDatabaseCount('entries', 0);
    }
}
