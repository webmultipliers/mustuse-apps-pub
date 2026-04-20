@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

<livewire:push-enroll
    :title="$block['title']       ?? 'Stay in the loop'"
    :description="$block['description'] ?? 'Enable notifications to get alerts for breaking stories.'"
    :enable-label="$block['enableLabel'] ?? 'Enable notifications'"
    wire:key="push-enroll" />
