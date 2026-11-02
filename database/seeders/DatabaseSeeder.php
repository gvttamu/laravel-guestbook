<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database: a user who captures customers, with a draft of
     * each workflow scenario waiting in their inbox, and a compliance officer to review
     * the flagged ones. Both sign in with the password "password".
     */
    public function run(): void
    {
        $capturer = User::factory()->create([
            'name' => 'Casey Capturer',
            'email' => 'capturer@example.com',
        ]);

        User::factory()->complianceOfficer()->create([
            'name' => 'Olive Officer',
            'email' => 'officer@example.com',
        ]);

        $this->call(FormDraftSeeder::class, parameters: ['user' => $capturer]);
    }
}
