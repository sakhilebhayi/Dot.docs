<?php

namespace App\Livewire;

use App\Models\Document;
use App\Models\Team;
use App\Search\CompanyDocuments;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The header search: live results over the current company's documents (title and body text).
 *
 * Enter with nothing highlighted still submits the pill's GET form to the documents page (`documents.index?q=`), which keeps
 * its own search; this component only offers the quick rows. The component takes no id from the browser: the company, the
 * person and every row come from the session user, through App\Search\CompanyDocuments.
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

        if (! $user || mb_strlen(trim($this->q)) < CompanyDocuments::MIN_LENGTH) {
            return collect();
        }

        return app(CompanyDocuments::class)->search($user, $this->q);
    }

    public function render()
    {
        $team = auth()->user()?->currentTeam;

        return view('livewire.header-search', [
            'searching' => mb_strlen(trim($this->q)) >= CompanyDocuments::MIN_LENGTH,
            'company' => $team instanceof Team ? $team->name : null,
        ]);
    }
}
