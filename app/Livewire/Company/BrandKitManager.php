<?php

namespace App\Livewire\Company;

use App\Audit\AuditLogger;
use App\Brand\Brand;
use App\Models\BrandKit;
use App\Models\Team;
use App\Models\User;
use App\Styles\StyleEngine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The company's brand kit (R2-3c / F-13): what its documents look like when they use it (App\Brand\Brand).
 * Everyone in the company may see it; its owner and admins may change it.
 */
#[Title('Brand')]
class BrandKitManager extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $headingColour = '#1f2023';

    public string $accentColour = '#8a6d05';

    public string $headingFont = '';

    public string $bodyFont = '';

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public string $website = '';

    public string $footerText = '';

    public string $disclaimer = '';

    public string $defaultStyle = '';

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public bool $hasLogo = false;

    public string $notice = '';

    /** The company this page was opened for: a save never lands on another one (a team switched in another tab). */
    #[Locked]
    public int $teamId = 0;

    public function mount(): void
    {
        $team = $this->team();
        $this->teamId = $team->id;
        $kit = Brand::forTeam($team);
        $this->name = $kit->name ?? $team->name;

        if ($kit !== null) {
            $this->headingColour = $kit->colours['heading'] ?? $this->headingColour;
            $this->accentColour = $kit->colours['accent'] ?? $this->accentColour;
            $this->headingFont = $kit->fonts['heading'] ?? '';
            $this->bodyFont = $kit->fonts['body'] ?? '';
            foreach (['address', 'phone', 'email', 'website'] as $field) {
                $this->{$field} = (string) ($kit->contact[$field] ?? '');
            }
            $this->footerText = (string) ($kit->footer['text'] ?? '');
            $this->disclaimer = (string) $kit->disclaimer;
            $this->defaultStyle = (string) $kit->default_style_key;
            $this->hasLogo = Brand::logoPath($kit) !== null;
        }
    }

    public function canEdit(): bool
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $this->team();

        return $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin');
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'headingColour' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accentColour' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'headingFont' => ['nullable', Rule::in(Brand::FONTS)],
            'bodyFont' => ['nullable', Rule::in(Brand::FONTS)],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'website' => ['nullable', 'url:https,http', 'max:200'],
            'footerText' => ['nullable', 'string', 'max:200'],
            'disclaimer' => ['nullable', 'string', 'max:1000'],
            'defaultStyle' => ['nullable', Rule::in(StyleEngine::systemKeys())],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ], [
            'headingColour.regex' => 'Use a colour like #1f2023.',
            'accentColour.regex' => 'Use a colour like #8a6d05.',
        ]);

        // Read on white paper by everyone: a pale brand colour is refused, not printed unreadable.
        foreach (['headingColour' => 'Headings', 'accentColour' => 'The accent'] as $field => $what) {
            $ratio = Brand::contrastOnWhite($this->{$field});
            if ($ratio < Brand::MIN_CONTRAST) {
                throw ValidationException::withMessages([$field => "{$what} would be hard to read on white paper ({$ratio}:1; it needs ".Brand::MIN_CONTRAST.':1). Choose a darker shade.']);
            }
        }

        $team = $this->team();
        $kit = BrandKit::firstOrNew(['team_id' => $team->id]);

        $replacedLogo = null;
        if ($this->logo instanceof TemporaryUploadedFile) {
            $replacedLogo = $kit->logo_path;
            $path = 'brand/'.$team->id.'/logo-'.Str::random(16).'.'.$this->logo->extension();
            Storage::disk(Brand::LOGO_DISK)->put($path, (string) file_get_contents($this->logo->getRealPath()));
            $kit->logo_path = $path;
        }

        $kit->fill([
            'name' => trim($this->name),
            'colours' => ['heading' => strtolower($this->headingColour), 'accent' => strtolower($this->accentColour)],
            'fonts' => array_filter(['heading' => $this->headingFont, 'body' => $this->bodyFont]),
            'contact' => array_filter([
                'address' => trim($this->address), 'phone' => trim($this->phone),
                'email' => trim($this->email), 'website' => trim($this->website),
            ]),
            'footer' => ['text' => trim($this->footerText)],
            'disclaimer' => trim($this->disclaimer) ?: null,
            'default_style_key' => $this->defaultStyle ?: null,
            'letterhead' => ['show' => true],
        ])->save();

        // Only once the kit points at the new logo: the old file goes, and so does Livewire's temporary copy.
        if ($this->logo instanceof TemporaryUploadedFile) {
            if ($replacedLogo && $replacedLogo !== $kit->logo_path) {
                Storage::disk(Brand::LOGO_DISK)->delete($replacedLogo);
            }
            $this->logo->delete();
            $this->logo = null;
            $this->hasLogo = true;
        }

        app(AuditLogger::class)->record('brand.updated', $kit);
        $this->notice = 'Saved. Documents that use the company brand show it now; new documents use it from the start.';
    }

    public function removeLogo(): void
    {
        abort_unless($this->canEdit(), 403);

        $kit = Brand::forTeam($this->team());
        if ($kit !== null && $kit->logo_path) {
            Storage::disk(Brand::LOGO_DISK)->delete($kit->logo_path);
            $kit->forceFill(['logo_path' => null])->save();
            app(AuditLogger::class)->record('brand.updated', $kit, ['logo' => 'removed']);
        }
        $this->hasLogo = false;
        $this->notice = 'Logo removed.';
    }

    private function team(): Team
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;
        abort_unless($team instanceof Team, 404);
        // Opened for one company, now standing in another: reload rather than write across.
        abort_if($this->teamId !== 0 && $this->teamId !== $team->id, 409, 'You switched company. Reload the page.');

        return $team;
    }

    /** The upload is checked the moment it arrives (R2-3c review): a wrong file never reaches the preview. */
    public function updatedLogo(): void
    {
        if (! $this->canEdit()) {
            $this->reset('logo');
            abort(403);
        }

        try {
            $this->validateOnly('logo', ['logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024']]);
        } catch (ValidationException $e) {
            $this->reset('logo');
            throw $e;
        }
    }

    public function render(): View
    {
        return view('livewire.company.brand-kit-manager', [
            'fonts' => Brand::FONTS,
            'styles' => StyleEngine::systemKeys(),
            'canEdit' => $this->canEdit(),
            'teamName' => $this->team()->name,
            'contactLine' => implode(' · ', array_values(array_filter([trim($this->address), trim($this->phone), trim($this->email), trim($this->website)]))),
            // Only validated values reach the preview's inline styles: what is typed is not trusted until saved.
            'previewHeading' => Brand::isColour($this->headingColour) ? $this->headingColour : '#1f2023',
            'previewAccent' => Brand::isColour($this->accentColour) ? $this->accentColour : '#8a6d05',
            'previewHeadingFont' => in_array($this->headingFont, Brand::FONTS, true) ? $this->headingFont : null,
            'previewBodyFont' => in_array($this->bodyFont, Brand::FONTS, true) ? $this->bodyFont : null,
            'previewFonts' => array_values(array_filter([$this->headingFont, $this->bodyFont], fn ($f) => in_array($f, Brand::FONTS, true))),
        ])->layout('layouts.app');
    }
}
