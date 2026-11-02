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
     * each workflow scenario waiting in their inbox, two compliance officers to review the
     * flagged ones and reassign them to each other, and a credit officer for each credit risk
     * tier to decide referred loan applications. All sign in with the password "password".
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

        User::factory()->complianceOfficer()->create([
            'name' => 'Oscar Overseer',
            'email' => 'officer2@example.com',
        ]);

        // One credit officer for each credit risk tier, from the least risky up.
        User::factory()->creditOfficer('low')->create(['name' => 'Carla Credit', 'email' => 'credit@example.com']);
        User::factory()->creditOfficer('medium')->create(['name' => 'Mia Medium', 'email' => 'credit2@example.com']);
        User::factory()->creditOfficer('high')->create(['name' => 'Hank High', 'email' => 'credit3@example.com']);
        User::factory()->creditOfficer('highest')->create(['name' => 'Hilda Highest', 'email' => 'credit4@example.com']);

        $this->call(FormDraftSeeder::class, parameters: ['user' => $capturer]);
    }
}
