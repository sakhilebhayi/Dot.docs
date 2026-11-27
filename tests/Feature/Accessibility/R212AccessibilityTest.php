<?php

namespace Tests\Feature\Accessibility;

use App\Documents\DocumentStore;
use App\Livewire\Documents\AiAssistant;
use App\Livewire\Documents\Editor;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R2-12: the accessibility findings of the R2-11 audit - sign-in errors tied to their fields with an announced summary,
 * live regions that are always in the page (only their words change), a quiet outline, and a welcome page that is
 * readable before any script runs. The browser half is tests/Browser/specs/j-reach-a11y.spec.ts.
 */
class R212AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private function dom(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    /** @param array<string, string> $errors */
    private function renderWithErrors(string $view, array $errors): HTMLDocument
    {
        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag(array_map(fn (string $message): array => [$message], $errors)));
        view()->share('errors', $bag);

        return $this->dom(view($view)->render());
    }

    public function test_a_refused_sign_in_ties_the_message_to_the_email_field_and_announces_a_summary(): void
    {
        $this->from(route('login'))->post(route('login'), ['email' => 'nobody@example.test', 'password' => 'wrong-password'])
            ->assertRedirect(route('login'));

        $dom = $this->dom($this->get(route('login'))->assertOk()->getContent());

        $email = $dom->querySelector('#email');
        $this->assertSame('true', $email->getAttribute('aria-invalid'));
        $this->assertSame('email-error', $email->getAttribute('aria-describedby'));
        $message = $dom->querySelector('#email-error');
        $this->assertNotSame('', trim($message->textContent));
        $this->assertNull($message->getAttribute('role'), 'the summary is the one alert on the page');

        $password = $dom->querySelector('#password');
        $this->assertNull($password->getAttribute('aria-invalid'), 'only the field with an error says so');

        $summary = $dom->querySelector('[data-error-summary]');
        $this->assertNotNull($summary);
        $this->assertSame('alert', $summary->getAttribute('role'));
        $this->assertSame('-1', $summary->getAttribute('tabindex'), 'focusable, so app.js can move the focus to it');
        $this->assertSame('error-summary-title', $summary->getAttribute('aria-labelledby'));
        $this->assertNotNull($dom->querySelector('#error-summary-title'));
        $this->assertSame('#email', $summary->querySelector('a')->getAttribute('href'), 'each message links to its field');
    }

    public function test_a_clean_sign_in_page_has_no_summary_and_no_invalid_field(): void
    {
        $dom = $this->dom($this->get(route('login'))->assertOk()->getContent());

        $this->assertNull($dom->querySelector('[data-error-summary]'));
        $this->assertNull($dom->querySelector('[aria-invalid]'));
    }

    /** @return array<string, array{string, array<string, string>, list<string>}> */
    public static function refusedAuthPages(): array
    {
        return [
            'register' => ['auth.register', ['name' => 'Required.', 'email' => 'Taken.', 'password' => 'Too short.', 'password_confirmation' => 'Mismatch.', 'terms' => 'Accept the terms.'], ['name', 'email', 'password', 'password_confirmation', 'terms']],
            'forgot password' => ['auth.forgot-password', ['email' => 'Not an address.'], ['email']],
            'confirm password' => ['auth.confirm-password', ['password' => 'Wrong.'], ['password']],
            'two-factor code' => ['auth.two-factor-challenge', ['code' => 'Invalid code.'], ['code']],
            'two-factor recovery code' => ['auth.two-factor-challenge', ['recovery_code' => 'Invalid recovery code.'], ['recovery_code']],
        ];
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $fields
     */
    #[DataProvider('refusedAuthPages')]
    public function test_every_sign_in_page_ties_each_error_to_its_field(string $view, array $errors, array $fields): void
    {
        $dom = $this->renderWithErrors($view, $errors);

        $this->assertNotNull($dom->querySelector('[data-error-summary][role="alert"]'));
        foreach ($fields as $id) {
            $field = $dom->querySelector('#'.$id);
            $this->assertNotNull($field, $id);
            $this->assertSame('true', $field->getAttribute('aria-invalid'), $id);
            $this->assertSame($id.'-error', $field->getAttribute('aria-describedby'), $id);
            $this->assertSame($errors[$id], trim($dom->querySelector('#'.$id.'-error')->textContent), $id);
        }
    }

    public function test_the_recovery_code_field_is_shown_when_it_is_the_one_refused(): void
    {
        $dom = $this->renderWithErrors('auth.two-factor-challenge', ['recovery_code' => 'Invalid recovery code.']);

        $this->assertStringContainsString('recovery: true', $dom->querySelector('[x-data]')->getAttribute('x-data'));
    }

    public function test_a_field_message_with_a_field_id_is_always_in_the_page_and_empty_without_an_error(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $dom = $this->dom($this->get(route('profile.show'))->assertOk()->getContent());

        $messages = $dom->querySelectorAll('p.field-error[role="alert"][id]');
        $this->assertGreaterThan(0, $messages->length, 'the profile forms render their field messages up front');
        foreach ($messages as $message) {
            $this->assertSame('', trim($message->textContent));
        }
    }

    public function test_the_editor_alerts_are_in_the_page_before_anything_goes_wrong(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Alerts');
        $component = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);
        $strip = $this->dom($component->html())->querySelector('.doc-status');

        $aiError = $strip->querySelector('[data-ai-error-said]');
        $this->assertSame('alert', $aiError->getAttribute('role'));
        $this->assertTrue($aiError->hasAttribute('wire:ignore'), 'the morph leaves its x-text alone');
        $this->assertSame('aiError', $aiError->getAttribute('x-text'));
        $this->assertNull($strip->querySelector('button [role="alert"]'), 'no alert inside the button that comes and goes');

        $contentError = $strip->querySelector('[data-content-error]');
        $this->assertSame('status', $contentError->getAttribute('role'), 'an autosave outcome is polite');
        $this->assertSame('', trim($contentError->textContent));

        // A refused save fills the same region, and the visible word is not read a second time.
        $component->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]], 1)->assertHasErrors('content');
        $strip = $this->dom($component->html())->querySelector('.doc-status');
        $this->assertStringStartsWith('The last save was refused: ', trim($strip->querySelector('[data-content-error]')->textContent));
        $this->assertSame('true', $strip->querySelector('span.status-word-danger')->getAttribute('aria-hidden'));
    }

    public function test_the_assistant_says_it_is_working_through_regions_that_are_always_there(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Assistant');
        $component = Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $doc]);
        $dom = $this->dom($component->html());

        $toast = $dom->querySelector('.ai-toast');
        $this->assertSame('true', $toast->getAttribute('aria-hidden'), 'the toast is what the eye sees');
        $this->assertNull($toast->getAttribute('role'));
        $status = $dom->querySelector('[data-assistant-action-status]');
        $this->assertSame('status', $status->getAttribute('role'));
        $this->assertNull($status->getAttribute('wire:loading'), 'the region itself is never hidden');
        $this->assertNotNull($status->querySelector('[wire\\:loading]'), 'only its words come and go');

        $component->set('showPalette', true);
        $palette = $this->dom($component->html())->querySelector('[data-assistant-command-status]');
        $this->assertSame('status', $palette->getAttribute('role'));
        $this->assertSame('Working', trim($palette->textContent));
    }

    public function test_the_outline_is_navigation_not_a_live_region(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Outline');
        $this->actingAs($user);

        $outline = $this->dom($this->get(route('documents.edit', $doc->uuid))->assertOk()->getContent())->querySelector('[data-shell-outline]');

        $this->assertNotNull($outline);
        $this->assertNull($outline->getAttribute('aria-live'));
        $this->assertNull($outline->getAttribute('aria-busy'));
    }

    public function test_the_welcome_page_holds_nothing_back_until_its_script_says_so(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/^\s*\.reveal\s*\{[^}]*opacity:\s*0/m', $html, 'no bare rule hides a section');
        $this->assertStringContainsString('html.reveal-on .reveal:not(.is-visible)', $html);
        $this->assertStringContainsString("document.documentElement.classList.add('reveal-on')", $html);
        $this->assertStringContainsString('@media (scripting: none)', $html, 'the menu is there without scripts');
    }
}
