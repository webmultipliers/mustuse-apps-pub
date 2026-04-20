<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Search input. On submit, navigates to /search?q={query}; the pub's
 * DeeplinkResolver returns the search-results screen + context. `#[Url]`
 * mirrors `$query` to `?q=...` so back/forward navigation reseeds the
 * input for free.
 */
class SearchBox extends Component
{
    public string $placeholder  = 'Search articles…';
    public string $submitLabel  = 'Search';
    public bool   $autoFocus    = false;

    #[Url(as: 'q', keep: false)]
    public string $query = '';

    public function mount(string $placeholder = 'Search articles…', string $submitLabel = 'Search', bool $autoFocus = false, string $seed = ''): void
    {
        $this->placeholder = $placeholder;
        $this->submitLabel = $submitLabel;
        $this->autoFocus   = $autoFocus;
        if ($this->query === '' && $seed !== '') {
            $this->query = $seed;
        }
    }

    public function submit(): mixed
    {
        $q = trim($this->query);
        if ($q === '') {
            return null;
        }
        return $this->redirectRoute('native-edge', ['any' => 'search', 'q' => $q], navigate: true);
    }

    public function render()
    {
        return view('livewire.search-box');
    }
}
