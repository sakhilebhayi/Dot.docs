<?php

namespace Tests\Feature\Company;

use App\Brand\Brand;
use App\Documents\DocumentStore;
use App\Documents\Export\DocxExporter;
use App\Livewire\Company\BrandKitManager;
use App\Livewire\Documents\DocumentSettings;
use App\Models\BrandKit;
use App\Models\Team;
use App\Models\User;
use App\Print\PrintRenderer;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** R2-3c / F-13: the company brand kit, and what it does to the documents that use it. */
class BrandKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentStyleSeeder::class);
        Storage::fake('local');
    }

    private function owner(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function saveKit(User $owner, array $overrides = []): void
    {
        $component = Livewire::actingAs($owner)->test(BrandKitManager::class)
            ->set('name', 'Acme <b>Holdings</b> & Co')
            ->set('headingColour', '#123456')
            ->set('accentColour', '#8a2be2')
            ->set('headingFont', 'Inter')
            ->set('bodyFont', 'Lora')
            ->set('address', '1 Main Road, Durban')
            ->set('email', 'hello@acme.example')
            ->set('footerText', 'Acme confidential - page {{ page }}')
            ->set('disclaimer', 'Not financial advice.')
            ->set('defaultStyle', 'minimal')
            ->set('logo', UploadedFile::fake()->image('logo.png', 120, 40));
        foreach ($overrides as $field => $value) {
            $component->set($field, $value);
        }
        $component->call('save')->assertHasNoErrors();
    }

    public function test_an_owner_saves_the_kit_and_the_logo_is_stored_privately(): void
    {
        $owner = $this->owner();
        $this->saveKit($owner);

        $kit = BrandKit::sole();
        $this->assertSame(['heading' => '#123456', 'accent' => '#8a2be2'], $kit->colours);
        $this->assertSame('minimal', $kit->default_style_key);
        $this->assertStringStartsWith('brand/'.$owner->currentTeam->id.'/', $kit->logo_path);
        Storage::disk('local')->assertExists($kit->logo_path);

        $this->actingAs($owner)->get(route('brand.logo'))->assertOk();
        $this->actingAs($this->owner())->get(route('brand.logo'))->assertNotFound();
    }

    public function test_a_member_can_look_but_not_change_it(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create(['current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        Livewire::actingAs($member)->test(BrandKitManager::class)
            ->assertSee('owner and admins can change the brand')
            ->call('save')->assertForbidden();
        $this->assertSame(0, BrandKit::count());
    }

    public function test_a_colour_too_pale_to_read_on_white_is_refused(): void
    {
        Livewire::actingAs($this->owner())->test(BrandKitManager::class)
            ->set('headingColour', '#f5e663')
            ->call('save')
            ->assertHasErrors('headingColour');
        $this->assertSame(0, BrandKit::count());
    }

    public function test_new_documents_use_it_and_existing_ones_switch_it_on_in_settings(): void
    {
        $owner = $this->owner();
        $before = app(DocumentStore::class)->create($owner, 'Written before');
        $this->saveKit($owner);
        $after = app(DocumentStore::class)->create($owner, 'Written after');

        $this->assertNull($before->fresh()->brand_kit_id);
        $this->assertSame(BrandKit::sole()->id, $after->fresh()->brand_kit_id);
        $this->assertSame('minimal', $after->fresh()->style_key);

        Livewire::actingAs($owner)->test(DocumentSettings::class, ['uuid' => $before->uuid])
            ->assertSee('Use the company brand')
            ->call('setBrand', true)
            ->assertSee('Stop using the company brand');
        $this->assertSame(BrandKit::sole()->id, $before->fresh()->brand_kit_id);

        Livewire::actingAs($owner)->test(DocumentSettings::class, ['uuid' => $after->uuid])->call('setBrand', false);
        $this->assertNull($after->fresh()->brand_kit_id);
    }

    public function test_the_brand_overlays_the_style_and_only_for_its_own_company(): void
    {
        $owner = $this->owner();
        $this->saveKit($owner);
        $doc = app(DocumentStore::class)->create($owner, 'Branded');
        $engine = app(StyleEngine::class);

        $style = $engine->resolve($doc);
        $this->assertSame('#123456', $style->tokens['colours']['heading']);
        $this->assertSame('#8a2be2', $style->tokens['colours']['accent']);
        $this->assertSame('Inter', $style->tokens['fonts']['heading']);
        $this->assertSame('Acme confidential - page {{ page }}', $style->tokens['page']['footer']);
        $this->assertStringContainsString('#123456', $engine->css($style));

        // Moved to another company, it keeps the id but not the brand.
        $other = $this->owner();
        $doc->forceFill(['team_id' => $other->currentTeam->id])->save();
        $this->assertNull(Brand::forDocument($doc->fresh()));
        $this->assertNotSame('#123456', $engine->resolve($doc->fresh())->tokens['colours']['heading']);
    }

    public function test_pdf_and_print_carry_the_letterhead_and_the_disclaimer_as_text(): void
    {
        $owner = $this->owner();
        $this->saveKit($owner);
        $doc = app(DocumentStore::class)->create($owner, 'Branded');

        $html = app(PrintRenderer::class)->html($doc);

        $this->assertStringContainsString('Acme &lt;b&gt;Holdings&lt;/b&gt; &amp; Co', $html);
        $this->assertStringContainsString('1 Main Road, Durban · hello@acme.example', $html);
        $this->assertStringContainsString('Not financial advice.', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringNotContainsString('<b>Holdings</b>', $html);
        $this->assertNotEmpty(app(PrintRenderer::class)->pdf($doc));
    }

    public function test_word_carries_the_letterhead_and_the_disclaimer(): void
    {
        $owner = $this->owner();
        $this->saveKit($owner);
        $doc = app(DocumentStore::class)->create($owner, 'Branded');

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $media = array_filter(range(0, $zip->numFiles - 1), fn ($i) => str_starts_with((string) $zip->getNameIndex($i), 'word/media/'));
        $zip->close();
        @unlink($path);

        // Escaped like every other run: a raw "&" or "<" would make a file Word refuses to open.
        $this->assertStringContainsString('Acme &lt;b&gt;Holdings&lt;/b&gt; &amp; Co', $xml);
        $this->assertNotFalse(@simplexml_load_string($xml), 'document.xml is well-formed');
        $this->assertStringContainsString('Not financial advice.', $xml);
        $this->assertNotEmpty($media, 'the logo is embedded');
    }

    public function test_saving_the_page_setup_does_not_freeze_the_brand_footer_into_the_document(): void
    {
        $owner = $this->owner();
        $this->saveKit($owner);
        $doc = app(DocumentStore::class)->create($owner, 'Branded');

        Livewire::actingAs($owner)->test(DocumentSettings::class, ['uuid' => $doc->uuid])
            ->set('marginTop', '30mm')->call('savePageSetup');
        $this->assertArrayNotHasKey('footer', $doc->fresh()->page_setup);

        $this->saveKit($owner, ['footerText' => 'New footer']);
        $this->assertSame('New footer', app(StyleEngine::class)->resolve($doc->fresh())->tokens['page']['footer']);
    }

    public function test_a_file_that_is_not_an_image_is_refused_on_arrival_and_the_page_keeps_working(): void
    {
        Livewire::actingAs($this->owner())->test(BrandKitManager::class)
            ->set('logo', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertHasErrors('logo')
            ->assertSet('logo', null)
            ->assertOk();
    }

    public function test_a_replaced_logo_is_deleted_only_after_the_new_one_is_saved(): void
    {
        $owner = $this->owner();
        $this->saveKit($owner);
        $first = BrandKit::sole()->logo_path;

        $this->saveKit($owner, ['logo' => UploadedFile::fake()->image('second.png', 100, 40)]);

        $second = BrandKit::sole()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_a_save_after_switching_company_in_another_tab_is_refused(): void
    {
        $owner = $this->owner();
        $component = Livewire::actingAs($owner)->test(BrandKitManager::class);
        $other = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Second company', 'personal_team' => false]);
        // What a new request sees after the switch in the other tab.
        $owner->forceFill(['current_team_id' => $other->id])->save();
        $owner->unsetRelation('currentTeam');

        $component->call('save')->assertStatus(409);
        $this->assertSame(0, BrandKit::count());
    }
}
