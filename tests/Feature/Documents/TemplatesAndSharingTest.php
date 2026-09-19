<?php

namespace Tests\Feature\Documents;

use App\Documents\Schema\DocumentSchema;
use App\Livewire\Documents\SaveAsTemplate;
use App\Livewire\Documents\ShareManager;
use App\Livewire\Documents\TemplateGallery;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Database\Seeders\StarterTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TemplatesAndSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_templates_seed_with_json_style_and_variables(): void
    {
        $this->seed([DocumentStyleSeeder::class, StarterTemplateSeeder::class]);
        $t = DocumentTemplate::where('name', 'Monthly production report')->firstOrFail();
        $this->assertSame('mining', $t->style_key);
        $this->assertSame('doc', $t->content_json['type']);
        $this->assertStringContainsString('variable', json_encode($t->content_json));
    }

    public function test_every_starter_template_is_valid_json_with_the_right_shape(): void
    {
        $this->seed([DocumentStyleSeeder::class, StarterTemplateSeeder::class]);
        $schema = app(DocumentSchema::class);

        $expected = [
            'Monthly production report' => ['style' => 'mining', 'needs' => ['table', 'toc']],
            'Safety incident report' => ['style' => 'government', 'needs' => ['table']],
            'Board memorandum' => ['style' => 'executive', 'needs' => []],
        ];

        foreach ($expected as $name => $spec) {
            $template = DocumentTemplate::where('name', $name)->firstOrFail();
            $json = $template->content_json;

            $this->assertSame([], $schema->validate($json), "{$name} is not valid Dot.Doc JSON");
            $this->assertSame($spec['style'], $template->style_key);
            $this->assertTrue($template->is_global);

            $types = [];
            $schema->walk($json, function (array $node) use (&$types): void {
                $types[] = $node['type'] ?? '';
            });

            $this->assertContains('variable', $types, "{$name} has no variable node");
            foreach ($spec['needs'] as $type) {
                $this->assertContains($type, $types, "{$name} has no {$type} node");
            }
        }

        $memo = DocumentTemplate::where('name', 'Board memorandum')->firstOrFail();
        $memoText = $schema->plainText($memo->content_json);
        foreach (['Purpose', 'Background', 'Discussion', 'Financial implications', 'Recommendation', 'Decision required'] as $heading) {
            $this->assertStringContainsString($heading, $memoText);
        }
    }

    public function test_using_a_template_creates_a_styled_document(): void
    {
        $this->seed([DocumentStyleSeeder::class, StarterTemplateSeeder::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $t = DocumentTemplate::where('name', 'Board memorandum')->firstOrFail();
        Livewire::actingAs($user)->test(TemplateGallery::class)->call('useTemplate', $t->id)->assertRedirect();
        $doc = Document::where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('executive', $doc->style_key);
        $this->assertSame($t->content_json['content'][0]['type'], $doc->content_json['content'][0]['type']);
    }

    public function test_using_the_production_template_carries_its_page_setup_and_numbers_its_headings(): void
    {
        $this->seed([DocumentStyleSeeder::class, StarterTemplateSeeder::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $t = DocumentTemplate::where('name', 'Monthly production report')->firstOrFail();

        Livewire::actingAs($user)->test(TemplateGallery::class)->call('useTemplate', $t->id);

        $doc = Document::where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('landscape', $doc->page_setup['orientation']);
        $this->assertStringContainsString('doc-table', (string) $doc->content);
        $this->assertStringContainsString('class="toc"', (string) $doc->content);
        $this->assertGreaterThan(0, $doc->word_count);
    }

    public function test_saving_a_document_as_a_template_stores_json_style_and_page_setup(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'style_key' => 'executive',
            'page_setup' => ['orientation' => 'landscape'],
        ]);

        Livewire::actingAs($user)->test(SaveAsTemplate::class, ['document' => $doc])
            ->set('name', 'My house style')
            ->call('save');

        $template = DocumentTemplate::where('name', 'My house style')->firstOrFail();
        $this->assertSame('doc', $template->content_json['type']);
        $this->assertSame('executive', $template->style_key);
        $this->assertSame('landscape', $template->page_setup['orientation']);
    }

    public function test_slug_publish_and_view_counter(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['is_public' => true]);
        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $doc->uuid])->set('slug', 'august-production')->call('saveSlug');
        $this->assertSame('august-production', $doc->fresh()->slug);

        $this->get('/d/august-production')->assertOk()->assertSee($doc->title);
        $this->assertSame(1, $doc->fresh()->view_count);

        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $doc->uuid])->set('slug', 'A B')->call('saveSlug')->assertHasErrors('slug');
        $doc->update(['is_public' => false]);
        $this->get('/d/august-production')->assertNotFound();
    }

    public function test_a_slug_already_taken_is_rejected(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        Document::factory()->for($user, 'owner')->create(['slug' => 'taken-slug']);
        $mine = Document::factory()->for($user, 'owner')->create();

        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $mine->uuid])
            ->set('slug', 'taken-slug')
            ->call('saveSlug')
            ->assertHasErrors('slug');

        $this->assertNull($mine->fresh()->slug);
    }

    public function test_a_document_keeps_its_own_slug_on_a_re_save_and_can_clear_it(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['slug' => 'mine-already']);

        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->set('slug', 'mine-already')
            ->call('saveSlug')
            ->assertHasNoErrors();

        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->set('slug', '')
            ->call('saveSlug')
            ->assertHasNoErrors();

        $this->assertNull($doc->fresh()->slug);
    }

    public function test_deleting_a_document_frees_its_slug_for_reuse(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['slug' => 'reusable-slug']);

        $doc->delete();

        $this->assertNull(Document::withTrashed()->findOrFail($doc->id)->slug);

        $other = Document::factory()->for($user, 'owner')->create();
        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $other->uuid])
            ->set('slug', 'reusable-slug')
            ->call('saveSlug')
            ->assertHasNoErrors();

        $this->assertSame('reusable-slug', $other->fresh()->slug);
    }

    public function test_an_expired_published_link_is_gone_and_is_not_counted(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'is_public' => true,
            'slug' => 'expired-one',
            'share_expires_at' => now()->subDay(),
        ]);

        $this->get('/d/expired-one')->assertStatus(410);
        $this->assertSame(0, $doc->fresh()->view_count);
    }

    public function test_a_password_protected_published_link_asks_before_it_shows(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'is_public' => true,
            'slug' => 'locked-one',
            'share_password' => Hash::make('open-sesame'),
        ]);

        $this->get('/d/locked-one')->assertOk()->assertSee('Password required');
        $this->assertSame(0, $doc->fresh()->view_count);

        $this->post('/d/locked-one', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(0, $doc->fresh()->view_count);

        $this->post('/d/locked-one', ['password' => 'open-sesame'])->assertOk()->assertSee($doc->title);
        $this->assertSame(1, $doc->fresh()->view_count);
    }

    public function test_the_published_page_renders_the_paper_with_a_print_button(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'is_public' => true,
            'slug' => 'read-me-now',
            'style_key' => 'executive',
        ]);

        $response = $this->get('/d/read-me-now');

        $response->assertOk()
            ->assertSee('class="shell"', false)
            ->assertSee('class="paper"', false)
            ->assertSee('id="doc-style"', false)
            ->assertSee('window.print()', false)
            ->assertSee('Made with Dot.Doc');

        // .ai/rules/views.md: a chrome colour is never a literal in a view.
        // The Document Style CSS inside <style id="doc-style"> is the one
        // place hex colours legitimately appear, so it is cut out first.
        // Blade's HTML-escaped numeric character references (e.g. `&#039;`
        // for an apostrophe in a Faker-generated owner name like "O'Keefe")
        // are also cut out: decimal digits are valid hex digits too, so
        // `&#039;` false-matches the hex-colour pattern below - confirmed
        // flaky in CI, where a random owner name containing an apostrophe
        // failed this assertion even though the page has no literal colour.
        $chrome = preg_replace('#<style id="doc-style">.*?</style>#s', '', $response->getContent());
        $chrome = preg_replace('/&#x?[0-9a-fA-F]+;/', '', (string) $chrome);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', (string) $chrome);
    }

    public function test_the_uuid_share_link_still_works_and_now_counts_views(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['is_public' => true]);

        $this->get('/shared/'.$doc->uuid)->assertOk()->assertSee($doc->title);
        $this->assertSame(1, $doc->fresh()->view_count);
    }

    public function test_the_eleventh_password_attempt_on_a_published_link_is_throttled(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'is_public' => true,
            'slug' => 'guess-me',
            'share_password' => Hash::make('open-sesame'),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->post('/d/guess-me', ['password' => 'wrong'])->assertSessionHasErrors('password');
        }

        // The 11th guess within the minute is throttled, not answered.
        $this->post('/d/guess-me', ['password' => 'wrong'])->assertStatus(429);
        $this->assertSame(0, $doc->fresh()->view_count);
    }

    public function test_the_eleventh_password_attempt_on_a_uuid_share_link_is_throttled(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'is_public' => true,
            'share_password' => Hash::make('open-sesame'),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->post('/shared/'.$doc->uuid, ['password' => 'wrong'])->assertSessionHasErrors('password');
        }

        $this->post('/shared/'.$doc->uuid, ['password' => 'wrong'])->assertStatus(429);
        $this->assertSame(0, $doc->fresh()->view_count);
    }
}
