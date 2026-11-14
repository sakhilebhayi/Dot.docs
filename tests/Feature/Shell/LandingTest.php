<?php

namespace Tests\Feature\Shell;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Index;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The arrival page (/dashboard): where a person lands after signing in through InfoDot.
 *
 * One dominant action that really starts a document, the original logo, a welcome and context
 * read from one company only, and an honest empty state. See resources/views/dashboard.blade.php
 * and App\Http\Controllers\LandingController.
 */
class LandingTest extends TestCase
{
    use RefreshDatabase;

    /** SHA-256 of the artwork as committed. The landing may present it; it may never change it. */
    private const LOGO_HASHES = [
        'images/logo.png' => '7642c96e6e82b872dcf679ca8551fa54a757948899595e1deadd61535b3cb73a',
        'images/logo-light.png' => '5357fa6e01aa4741d3bf97b6a41830897595d9c86710d8ad6a017cc2bab543c7',
    ];

    private function company(User $owner, string $name): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $owner->switchTeam($team);

        return $team;
    }

    private function member(Team $team, string $role, string $name = 'Naledi Khumalo'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user;
    }

    private function write(User $author, string $title, string $text = 'Body text.'): Document
    {
        return app(DocumentStore::class)->create($author, $title, [
            'type' => 'doc',
            'attrs' => ['schema' => 1, 'style' => 'report', 'vars' => []],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]],
        ]);
    }

    /** @return array<string, array{string}> */
    public static function arrivingRoles(): array
    {
        return ['the owner' => ['owner'], 'an administrator' => ['admin'], 'an editor' => ['editor'], 'a viewer' => ['viewer']];
    }

    private function arrive(string $role): User
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $team = $this->company($owner, 'Karoo Minerals');

        return $role === 'owner' ? $owner : $this->member($team, $role);
    }

    #[DataProvider('arrivingRoles')]
    public function test_every_arriving_role_gets_one_primary_action_that_works(string $role): void
    {
        $user = $this->arrive($role);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-primary-action'), 'exactly one element is the primary action');
        $this->assertSame(1, substr_count($html, 'btn-primary'), 'nothing else on the page is dressed as a primary button');
        $this->assertSame(1, substr_count($html, '<h1'));

        // The element carrying the marker is the link to the real workflow...
        $this->assertSame(1, preg_match('/<a\s[^>]*href="([^"]+)"[^>]*data-primary-action/s', $html, $match));
        $target = html_entity_decode($match[1]);
        $this->assertSame(route('documents.index', ['new' => 1]), $target);

        // ...and that workflow answers for this role with the new-document sheet already open.
        $this->actingAs($user)->get($target)
            ->assertOk()
            ->assertSee('id="new-doc-title"', false)
            ->assertSee('Create the document');
    }

    #[DataProvider('arrivingRoles')]
    public function test_the_primary_workflow_really_creates_a_document_for_each_role(string $role): void
    {
        $user = $this->arrive($role);

        Livewire::actingAs($user)
            ->withQueryParams(['new' => 1])
            ->test(Index::class)
            ->assertSet('showSmartSave', true)
            ->set('newDocumentName', 'First page')
            ->call('createDocumentAtLocation')
            ->assertHasNoErrors();

        $document = Document::where('title', 'First page')->firstOrFail();
        $this->assertSame($user->id, $document->owner_id);
        $this->assertSame($user->currentTeam->id, $document->team_id);
        $this->actingAs($user)->get(route('documents.edit', $document->uuid))->assertOk();
    }

    public function test_the_list_page_does_not_open_the_sheet_unasked(): void
    {
        $user = $this->arrive('owner');

        Livewire::actingAs($user)->test(Index::class)->assertSet('showSmartSave', false);
    }

    public function test_it_shows_the_original_logo_files_unaltered(): void
    {
        foreach (self::LOGO_HASHES as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), "{$file} is not the original artwork");
        }

        $html = $this->actingAs($this->arrive('owner'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('src="'.asset('images/logo.png').'" alt="Dot.docs"', $html);
        $this->assertStringContainsString('src="'.asset('images/logo-light.png').'" alt="Dot.docs"', $html);
        $this->assertStringNotContainsString('<svg', $this->landing($html), 'the landing draws no mark of its own');
    }

    public function test_it_welcomes_the_person_by_first_name_and_names_their_company(): void
    {
        $this->actingAs($this->arrive('owner'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Welcome, Thandi.')
            ->assertSee('This is where Karoo Minerals drafts', false)
            ->assertSee('Put it in writing, together.');
    }

    public function test_a_personal_space_is_not_greeted_as_a_company(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Sipho Dlamini']);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Welcome, Sipho. This is where you draft', $this->squash($html));
        $this->assertStringNotContainsString("Sipho's Team", $this->landing($html));
    }

    public function test_the_way_back_to_infodot_comes_from_the_ecosystem_configuration(): void
    {
        $user = $this->arrive('viewer');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<a class="link" href="https://infodot.co.za/home">InfoDot</a>', false)
            ->assertSee('<a class="link" href="https://infodot.co.za/platforms">Platforms</a>', false);

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test/']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('href="https://infodot.test/home"', false)
            ->assertSee('href="https://infodot.test/platforms"', false);
    }

    public function test_a_new_member_with_nothing_sees_an_honest_empty_state(): void
    {
        $html = $this->actingAs($this->arrive('viewer'))->get(route('dashboard'))->assertOk()->getContent();
        $landing = $this->landing($html);

        $this->assertStringContainsString('data-landing-empty', $landing);
        $this->assertStringContainsString('Nothing has been written in Karoo Minerals yet.', $this->squash($landing));
        $this->assertStringContainsString('No documents yet, and nobody has shared one with you by name.', $this->squash($landing));
        $this->assertStringNotContainsString('Recently edited', $landing);
        $this->assertStringNotContainsString('Shared with you', $landing);

        // Nothing was seeded, so the page states no figure at all.
        $this->assertSame([], $this->figures($landing));
        $this->assertSame(1, substr_count($html, 'data-primary-action'));
    }

    public function test_every_number_on_the_page_comes_from_a_record(): void
    {
        $owner = $this->arrive('owner');
        $team = $owner->currentTeam;
        $colleague = $this->member($team, 'editor', 'Gina Naidoo');

        $this->write($owner, 'Board minutes');
        $this->write($owner, 'Supplier policy');
        $published = $this->write($owner, 'Tender response');
        $published->forceFill(['is_public' => true, 'version' => 7])->save();
        $theirs = $this->write($colleague, 'Water use licence', 'The licence covers two boreholes.');
        $theirs->forceFill(['updated_at' => now()->addMinute()])->save();
        DocumentCollaborator::create(['document_id' => $theirs->id, 'user_id' => $owner->id, 'role' => 'editor']);
        foreach ([1, 2] as $n) {
            AiSuggestion::forceCreate(['document_id' => $published->id, 'user_id' => $owner->id, 'suggestion_text' => 'Tighten paragraph '.$n.'.']);
        }

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $landing = $this->landing($html);

        // The tally: 4 documents in the company, 3 written by this person, 1 shared by name,
        // 1 published, 2 suggestions waiting. Each is a count of the rows created above.
        $this->assertStringContainsString(
            '4 documents in Karoo Minerals, 3 written by you. 1 shared with you by name. 1 of yours published to anyone holding the link. 2 suggestions from the assistant still wait for you.',
            $this->squash(strip_tags(str_replace('<span class="sr-only">', '<span> ', $landing))),
        );

        // The newest document is the sheet, quoted from its own stored text.
        $this->assertStringContainsString('The licence covers two boreholes.', $landing);
        $this->assertStringContainsString('by Gina Naidoo', $this->squash($landing));

        // Every figure rendered as a figure is one of: the five counts, the one person sharing
        // the colleague's document, or a version number that exists (v1 three times, v7 once).
        $figures = $this->figures($landing);
        sort($figures);
        $this->assertSame(['1', '1', '2', '3', '4', 'v1', 'v1', 'v7'], $figures);
    }

    public function test_it_never_shows_a_title_from_another_company(): void
    {
        $ann = $this->arrive('owner');
        $acme = $ann->currentTeam;

        $gina = User::factory()->withPersonalTeam()->create(['name' => 'Gina Naidoo']);
        $globex = $this->company($gina, 'Globex Freight');
        $rateCard = $this->write($gina, 'Globex rate card');

        // Ann belongs to both, wrote inside Globex, and is a named collaborator there too.
        $globex->users()->attach($ann, ['role' => 'editor']);
        DocumentCollaborator::create(['document_id' => $rateCard->id, 'user_id' => $ann->id, 'role' => 'viewer']);
        $ann->switchTeam($globex);
        $this->write($ann, 'Ann at Globex');
        $ann->switchTeam($acme);
        $this->write($ann, 'Acme handbook');

        $atAcme = $this->landing($this->actingAs($ann)->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('Acme handbook', $atAcme);
        $this->assertStringNotContainsString('Globex rate card', $atAcme);
        $this->assertStringNotContainsString('Ann at Globex', $atAcme);
        $this->assertStringNotContainsString('Globex Freight', $atAcme);
        $this->assertSame(['1', '1'], $this->figures($atAcme, versions: false));

        // The same person, standing in the other company, sees that company's and only that.
        $ann->switchTeam($globex);
        $atGlobex = $this->landing($this->actingAs($ann->fresh())->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('Globex rate card', $atGlobex);
        $this->assertStringContainsString('Ann at Globex', $atGlobex);
        $this->assertStringNotContainsString('Acme handbook', $atGlobex);

        // And somebody in neither company sees neither, in the empty state.
        $stranger = User::factory()->withPersonalTeam()->create();
        $elsewhere = $this->landing($this->actingAs($stranger)->get(route('dashboard'))->assertOk()->getContent());

        foreach (['Globex rate card', 'Ann at Globex', 'Acme handbook'] as $title) {
            $this->assertStringNotContainsString($title, $elsewhere);
        }
    }

    public function test_everything_the_old_page_reached_is_still_reachable(): void
    {
        $owner = $this->arrive('owner');
        $this->write($owner, 'First');
        $second = $this->write($owner, 'Second');

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $landing = html_entity_decode($this->landing($html));

        foreach ([
            route('documents.index'),
            route('documents.index', ['gallery' => 1]),
            route('documents.edit', $second->uuid),
        ] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $landing);
        }
    }

    public function test_the_tools_start_put_away_on_the_arrival_page_only(): void
    {
        $user = $this->arrive('owner');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('id="shell-dock" class="dock" data-panel-state="collapsed"', false)
            ->assertSee('id="shell-rail" class="rail" data-panel-state="expanded"', false);

        $this->actingAs($user)->get(route('documents.index'))
            ->assertSee('id="shell-dock" class="dock" data-panel-state="expanded"', false);
    }

    public function test_the_three_discontinued_platforms_are_gone_from_the_registry(): void
    {
        $current = [
            'agents', 'analytics', 'auction', 'central', 'charts', 'design', 'dopemine', 'ehail', 'emall', 'engage',
            'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet',
            'tasks', 'tutor', 'infodot', 'docs',
        ];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
        $this->assertSame('Dot.docs', config('ecosystem.platforms.docs.name'));
    }

    /** The landing's own markup, without the shell around it. */
    private function landing(string $html): string
    {
        $start = strpos($html, '<div class="page landing">');
        $end = strpos($html, '</main>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function squash(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $html));
    }

    /**
     * Every figure the page renders through <x-shell.figure>, as text ("4", "v7").
     *
     * @return list<string>
     */
    private function figures(string $html, bool $versions = true): array
    {
        preg_match_all('/<span class="numeral">([^<]*)/', $html, $matches);

        return array_values(array_filter(
            array_map(trim(...), $matches[1]),
            fn (string $figure) => $versions || ! str_starts_with($figure, 'v'),
        ));
    }
}
