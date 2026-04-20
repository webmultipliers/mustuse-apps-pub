<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Persistence;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Toggles a bookmark for the current context post. Dispatches
 * `bookmarks-changed` after each mutation so sibling blocks can
 * re-render in lockstep.
 */
class BookmarkToggle extends Component
{
    public int $postId = 0;
    public string $postType = 'post';
    public bool $bookmarked = false;
    public string $addLabel = 'Bookmark';
    public string $removeLabel = 'Bookmarked';

    public function mount(int $postId = 0, string $postType = 'post', string $addLabel = 'Bookmark', string $removeLabel = 'Bookmarked'): void
    {
        $this->postId      = $postId;
        $this->postType    = $postType;
        $this->addLabel    = $addLabel;
        $this->removeLabel = $removeLabel;
        $this->bookmarked  = $postId > 0 && app(Persistence::class)->isBookmarked($postId);
    }

    #[On('bookmarks-changed')]
    public function refreshState(): void
    {
        if ($this->postId > 0) {
            $this->bookmarked = app(Persistence::class)->isBookmarked($this->postId);
        }
    }

    public function toggle(): void
    {
        if ($this->postId <= 0) {
            return;
        }
        $persistence = app(Persistence::class);
        if ($this->bookmarked) {
            $persistence->removeBookmark($this->postId);
            $this->bookmarked = false;
        } else {
            $persistence->addBookmark($this->postId, $this->postType ?: 'post');
            $this->bookmarked = true;
        }
        $this->dispatch('bookmarks-changed');
    }

    public function render()
    {
        return view('livewire.bookmark-toggle');
    }
}
