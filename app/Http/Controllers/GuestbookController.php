<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyOwnerOfNewEntry;
use App\Models\Entry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestbookController extends Controller
{
    /**
     * Show the latest guestbook entries.
     */
    public function index(): Response
    {
        return Inertia::render('Guestbook', [
            'entries' => Entry::latest()->latest('id')->limit(50)->get(),
        ]);
    }

    /**
     * Sign the guestbook.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'message' => trim((string) $request->input('message')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:1000'],
        ], [
            'required' => 'Both fields are required.',
            'max' => 'That’s a bit long. Please shorten it.',
        ]);

        $entry = Entry::create($validated);

        NotifyOwnerOfNewEntry::dispatchIf(filled(config('guestbook.owner_email')), $entry);

        return to_route('guestbook.index');
    }
}
