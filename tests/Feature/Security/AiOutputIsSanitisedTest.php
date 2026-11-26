<?php

namespace Tests\Feature\Security;

use App\Documents\DocumentStore;
use App\Livewire\Documents\AiAssistant;
use App\Livewire\Documents\AiChat;
use App\Models\AiSuggestion;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * EA-1 / S-04: the assistant's answer was printed with {!! !!}. A model's
 * output is steered by the document's own text, so whatever markup it
 * returns must pass the document HTML allowlist before it reaches the page,
 * and a failed call must not print the exception's text.
 */
class AiOutputIsSanitisedTest extends TestCase
{
    use RefreshDatabase;

    public function test_markup_in_a_model_answer_is_cleaned_before_it_is_shown(): void
    {
        $this->partialMock(AiService::class, function ($mock) {
            $mock->shouldReceive('checkRateLimit')->andReturn(true);
            $mock->shouldReceive('saveSuggestion')->andReturn(new AiSuggestion);
            $mock->shouldReceive('summarize')->andReturn(
                '<p>Fine summary</p><img src="x" onerror="alert(1)"><script>alert(2)</script><a href="javascript:alert(3)">x</a>'
            );
        });

        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        $html = Livewire::actingAs($user)
            ->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'summarize')
            ->assertSet('showResult', true)
            ->html();

        $this->assertStringContainsString('Fine summary', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertStringNotContainsString('javascript:alert(3)', $html);
    }

    public function test_a_failed_call_shows_a_plain_message_not_the_exception(): void
    {
        $this->partialMock(AiService::class, function ($mock) {
            $mock->shouldReceive('checkRateLimit')->andReturn(true);
            $mock->shouldReceive('summarize')->andThrow(new RuntimeException('<img src=x onerror=alert(1)> provider key sk-secret'));
        });

        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        $html = Livewire::actingAs($user)
            ->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'summarize')
            ->html();

        $this->assertStringContainsString('could not answer just now', $html);
        $this->assertStringNotContainsString('sk-secret', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_the_chat_history_cannot_be_written_by_the_browser(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($user)
            ->test(AiChat::class, ['document' => $doc])
            ->set('history', [['role' => 'user', 'content' => str_repeat('x', 500000)]]);
    }
}
