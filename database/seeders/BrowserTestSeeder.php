<?php

namespace Database\Seeders;

use App\Documents\DocumentStore;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The fixed cast of the browser suite (EA-7, tests/Browser): an owner with a
 * company team and a document, and a colleague in that team. Test values for
 * a throwaway database only; it refuses to run in production.
 *
 * Password: E2E_PASSWORD, or the default below (a test value, never a real one).
 */
class BrowserTestSeeder extends Seeder
{
    public const OWNER = 'owner@e2e.test';

    public const COLLEAGUE = 'colleague@e2e.test';

    public const OUTSIDER = 'outsider@e2e.test';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('BrowserTestSeeder never runs in production.');
        }

        $this->call(DocumentStyleSeeder::class);

        $password = Hash::make((string) (env('E2E_PASSWORD') ?: 'e2e-local-password'));

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Olive Owner', 'email' => self::OWNER, 'password' => $password]);
        $colleague = User::factory()->withPersonalTeam()->create(['name' => 'Cole Colleague', 'email' => self::COLLEAGUE, 'password' => $password]);
        User::factory()->withPersonalTeam()->create(['name' => 'Otto Outsider', 'email' => self::OUTSIDER, 'password' => $password]);

        $team = $owner->currentTeam;
        $team->forceFill(['name' => 'E2E Company', 'personal_team' => false])->save();
        $team->users()->attach($colleague, ['role' => 'editor']);

        app(DocumentStore::class)->create($owner, 'Welcome pack', [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Welcome pack']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Seeded for the browser suite.']]],
            ],
        ]);
    }
}
