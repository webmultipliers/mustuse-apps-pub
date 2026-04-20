<form class="mua-search" wire:submit.prevent="submit" role="search">
    <label class="mua-search__label" for="mua-search-input">
        <span class="visually-hidden">Search</span>
        <input
            id="mua-search-input"
            class="mua-search__input"
            type="search"
            inputmode="search"
            enterkeyhint="search"
            autocomplete="off"
            wire:model="query"
            placeholder="{{ $placeholder }}"
            @if ($autoFocus) autofocus @endif />
    </label>
    <button type="submit" class="mua-search__submit">{{ $submitLabel }}</button>
</form>
