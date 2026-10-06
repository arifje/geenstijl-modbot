<?php

namespace Tests\Feature;

use App\Filament\Resources\Prompts\Pages\EditPrompt;
use App\Models\Prompt;
use App\Models\User;
use App\Services\Analysis\PromptRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromptManagementTest extends TestCase
{
    use RefreshDatabase;

    private PromptRepository $prompts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->prompts = app(PromptRepository::class);
    }

    public function test_the_initial_versions_match_the_prompt_files(): void
    {
        $this->assertSame($this->prompts->default('moderation'), $this->prompts->get('moderation'));
        $this->assertSame(
            substr(sha1($this->prompts->default('moderation')), 0, 12),
            Prompt::where('key', 'moderation')->first()->activeVersion->version,
        );
    }

    public function test_saving_in_the_admin_publishes_a_new_version(): void
    {
        $prompt = Prompt::where('key', 'moderation')->first();
        $initial = $prompt->active_version_id;

        $this->get("/admin/prompts/{$prompt->id}/edit")->assertOk();

        Livewire::test(EditPrompt::class, ['record' => $prompt->id])
            ->fillForm(['content' => "Nieuwe prompt\r\nregel 2", 'note' => 'Strenger'])
            ->call('save')
            ->assertHasNoFormErrors();

        $prompt->refresh();
        $this->assertNotSame($initial, $prompt->active_version_id);
        $this->assertSame("Nieuwe prompt\nregel 2", $prompt->activeVersion->content);
        $this->assertSame('Strenger', $prompt->activeVersion->note);
        $this->assertSame(auth()->id(), $prompt->activeVersion->user_id);

        // The old version is kept.
        $this->assertSame(2, $prompt->versions()->count());

        // The analysis uses the new version.
        $this->prompts->forget();
        $this->assertSame("Nieuwe prompt\nregel 2", $this->prompts->get('moderation'));
        $this->assertSame($prompt->activeVersion->version, $this->prompts->version('moderation'));
    }

    public function test_publishing_known_content_reactivates_that_version(): void
    {
        $prompt = Prompt::where('key', 'summary')->first();
        $first = $prompt->activeVersion;

        $prompt->publish('Andere samenvatting');
        $again = $prompt->publish($first->content);

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->id, $prompt->fresh()->active_version_id);
        $this->assertSame(2, $prompt->versions()->count());
    }

    public function test_the_prompt_pages_load(): void
    {
        $this->get('/admin/prompts')->assertOk()->assertSee('Moderation')->assertSee('Sentiment summary');
    }
}
