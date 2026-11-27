<?php

namespace App\Livewire;

use App\Models\Document;
use App\Models\Team;
use App\Search\DocumentSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The header search: live results over the current company's documents (title and body text).
 *
 * Enter with nothing highlighted still submits the pill's GET form to the documents page (`documents.index?q=`), which keeps
 * its own search; this component only offers the quick rows. The component takes no id from the browser: the company, the
 * person and every row come from the session user, through App\Search\DocumentSearch (the same search
 * as the documents page, R2-7).
 */
class HeaderSearch extends Component
{
    public string $q = '';

    /** The editor owns Ctrl/Cmd+K (its command palette): the pill prints no hint and binds no key there. */
    public bool $editor = false;

    /** @return Collection<int, Document> */
    public function getResultsProperty(): Collection
    {
        $user = auth()->user();

        if (! $user || mb_strlen(trim($this->q)) < DocumentSearch::MIN_LENGTH) {
            return collect();
        }

        return app(DocumentSearch::class)->search($user, $this->q, 8);
    }

    public function render(): View
    {
        $team = auth()->user()?->currentTeam;

        return view('livewire.header-search', [
            'searching' => mb_strlen(trim($this->q)) >= DocumentSearch::MIN_LENGTH,
            'company' => $team instanceof Team ? $team->name : null,
        ]);
    }
}
