<div class="page">
    {{-- The preview's fonts, the way the editor loads a document's: an @import, which does not hold the page's
         load the way a <link> does when the font host is slow. Only names from Brand::FONTS reach the URL. --}}
    @if ($previewFonts !== [])
        <style>@import url('{{ \App\Brand\Brand::fontImport($previewFonts) }}');</style>
    @endif

    <div class="page-head">
        <div>
            <h1 class="page-title">Brand</h1>
            <p class="page-lede">How {{ $teamName }}’s documents look when they use the company brand: colours and fonts in every view, and the letterhead, footer and disclaimer on PDF, print and Word. New documents use it from the start; any document can switch it off in its Settings.</p>
        </div>
        @if ($canEdit)
            <div class="toolbar">
                <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save,logo">Save the brand</button>
            </div>
        @endif
    </div>

    @if ($notice !== '')
        <p class="note" role="status">
            <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
            {{ $notice }}
        </p>
    @endif

    @unless ($canEdit)
        <p class="note">Only {{ $teamName }}’s owner and admins can change the brand.</p>
    @endunless

    {{-- The letterhead and a heading as a page would show them. --}}
    <section class="panel" aria-labelledby="brand-preview">
        <div class="panel-head">
            <h2 class="section-title" id="brand-preview">Preview</h2>
        </div>
        <div class="panel-body">
            <div class="brand-sheet" @if ($previewBodyFont) style="font-family:'{{ $previewBodyFont }}', serif" @endif>
                <div class="brand-letterhead">
                    @if ($logo && $logo->isPreviewable())
                        <img src="{{ $logo->temporaryUrl() }}" alt="" class="brand-logo">
                    @elseif ($hasLogo)
                        <img src="{{ route('brand.logo') }}?v={{ crc32((string) $notice) }}" alt="" class="brand-logo">
                    @endif
                    <div>
                        <p class="brand-name" style="color:{{ $previewHeading }}@if ($previewHeadingFont);font-family:'{{ $previewHeadingFont }}', sans-serif @endif">{{ $name !== '' ? $name : $teamName }}</p>
                        @if ($contactLine !== '')
                            <p class="brand-contact">{{ $contactLine }}</p>
                        @endif
                    </div>
                </div>
                <p class="brand-sample-heading" style="color:{{ $previewHeading }}@if ($previewHeadingFont);font-family:'{{ $previewHeadingFont }}', sans-serif @endif"><span style="color:{{ $previewAccent }}">1</span> Quarterly report</p>
                <p class="brand-sample-body">Body text in the brand’s font. <span style="color:{{ $previewAccent }};text-decoration:underline">A cross-reference</span> and numbers take the accent colour.</p>
                @if (trim($disclaimer) !== '')
                    <p class="brand-disclaimer">{{ $disclaimer }}</p>
                @endif
                @if (trim($footerText) !== '')
                    <p class="brand-footer">{{ $footerText }}</p>
                @endif
            </div>
        </div>
    </section>

    <section class="panel" aria-labelledby="brand-identity">
        <div class="panel-head"><h2 class="section-title" id="brand-identity">Identity</h2></div>
        <fieldset class="brand-fields panel-body stack" @disabled(! $canEdit)>
            <div class="field-row">
                <label class="field-label" for="brand-name">Name on the letterhead</label>
                <input id="brand-name" type="text" class="field" wire:model.blur="name" maxlength="120" @error('name') aria-invalid="true" aria-describedby="brand-name-error" @enderror>
                @error('name') <p class="field-error" id="brand-name-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field-row">
                <label class="field-label" for="brand-logo">Logo</label>
                <div class="toolbar">
                    <input id="brand-logo" type="file" class="field" wire:model="logo" accept="image/png,image/jpeg,image/webp" style="flex:1 1 260px;min-width:0" @error('logo') aria-invalid="true" aria-describedby="brand-logo-error" @enderror>
                    @if ($hasLogo && $canEdit)
                        <button type="button" class="btn btn-quiet" wire:click="removeLogo" wire:confirm="Remove the logo from the letterhead?">Remove the logo</button>
                    @endif
                </div>
                <p class="field-hint">PNG, JPEG or WebP, up to 1 MB. A wide logo on a transparent background prints best.</p>
                @error('logo') <p class="field-error" id="brand-logo-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </fieldset>
    </section>

    <section class="panel" aria-labelledby="brand-colours-and-fonts">
        <div class="panel-head"><h2 class="section-title" id="brand-colours-and-fonts">Colours and fonts</h2></div>
        <fieldset class="brand-fields panel-body stack" @disabled(! $canEdit)>
            <div class="brand-grid">
                @foreach (['headingColour' => 'Headings', 'accentColour' => 'Accent (numbers, links)'] as $field => $label)
                    <div class="field-row">
                        <label class="field-label" for="brand-{{ $field }}">{{ $label }}</label>
                        <div class="toolbar">
                            <input type="color" class="brand-swatch" wire:model.live="{{ $field }}" aria-label="{{ $label }}: pick a colour">
                            <input id="brand-{{ $field }}" type="text" class="field" wire:model.blur="{{ $field }}" maxlength="7" style="flex:1 1 6rem;font-family:var(--font-mono)" @error($field) aria-invalid="true" aria-describedby="brand-{{ $field }}-error" @enderror>
                        </div>
                        @error($field) <p class="field-error" id="brand-{{ $field }}-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <div class="brand-grid">
                @foreach (['headingFont' => 'Heading font', 'bodyFont' => 'Body font'] as $field => $label)
                    <div class="field-row">
                        <label class="field-label" for="brand-{{ $field }}">{{ $label }}</label>
                        <select id="brand-{{ $field }}" class="field" wire:model.live="{{ $field }}">
                            <option value="">The document style’s own</option>
                            @foreach ($fonts as $font)
                                <option value="{{ $font }}">{{ $font }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            <p class="field-hint">Colours must read clearly on white paper (4.5:1); a paler shade is refused.</p>
        </fieldset>
    </section>

    <section class="panel" aria-labelledby="brand-contact-line">
        <div class="panel-head"><h2 class="section-title" id="brand-contact-line">Contact line</h2></div>
        <fieldset class="brand-fields panel-body" @disabled(! $canEdit)>
            <div class="brand-grid">
                @foreach (['address' => ['Address', 'text', 200], 'phone' => ['Phone', 'tel', 40], 'email' => ['Email', 'email', 120], 'website' => ['Website', 'url', 200]] as $field => [$label, $type, $max])
                    <div class="field-row">
                        <label class="field-label" for="brand-{{ $field }}">{{ $label }}</label>
                        <input id="brand-{{ $field }}" type="{{ $type }}" class="field" wire:model.blur="{{ $field }}" maxlength="{{ $max }}" @error($field) aria-invalid="true" aria-describedby="brand-{{ $field }}-error" @enderror>
                        @error($field) <p class="field-error" id="brand-{{ $field }}-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </fieldset>
    </section>

    <section class="panel" aria-labelledby="brand-documents">
        <div class="panel-head"><h2 class="section-title" id="brand-documents">Documents</h2></div>
        <fieldset class="brand-fields panel-body stack" @disabled(! $canEdit)>
            <div class="field-row">
                <label class="field-label" for="brand-style">New documents start in</label>
                <select id="brand-style" class="field" wire:model="defaultStyle">
                    <option value="">The usual style (Report)</option>
                    @foreach ($styles as $key)
                        <option value="{{ $key }}">{{ ucfirst($key) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field-row">
                <label class="field-label" for="brand-footer">Page footer</label>
                <input id="brand-footer" type="text" class="field" wire:model.blur="footerText" maxlength="200" placeholder="Page @{{ page }} of @{{ pages }}" @error('footerText') aria-invalid="true" aria-describedby="brand-footer-error" @enderror>
                <p class="field-hint">On every page of PDF, print and Word. @{{ page }} and @{{ pages }} become page numbers. A document’s own footer still wins.</p>
                @error('footerText') <p class="field-error" id="brand-footer-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field-row">
                <label class="field-label" for="brand-disclaimer">Disclaimer</label>
                <textarea id="brand-disclaimer" class="field" rows="3" wire:model.blur="disclaimer" maxlength="1000" @error('disclaimer') aria-invalid="true" aria-describedby="brand-disclaimer-error" @enderror></textarea>
                <p class="field-hint">At the end of every PDF, print and Word export.</p>
                @error('disclaimer') <p class="field-error" id="brand-disclaimer-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </fieldset>
    </section>

    @if ($canEdit)
        <div class="toolbar">
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save,logo">Save the brand</button>
        </div>
    @endif
</div>
