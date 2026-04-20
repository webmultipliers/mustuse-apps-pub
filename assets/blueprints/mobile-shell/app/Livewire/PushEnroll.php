<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Persistence;
use Livewire\Component;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\PushNotifications\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;

/**
 * Push-enrollment opt-in prompt.
 *
 * State machine: idle → pending (OS permission prompt) → enrolled | declined.
 * On token receipt, syncs to the pub via Persistence::enrollPush.
 */
class PushEnroll extends Component
{
    public string $state       = 'idle'; // idle | pending | enrolled | declined
    public string $title       = 'Stay in the loop';
    public string $description = 'Enable notifications to get alerts for breaking stories.';
    public string $enableLabel = 'Enable notifications';

    public function mount(string $title = 'Stay in the loop', string $description = 'Enable notifications to get alerts for breaking stories.', string $enableLabel = 'Enable notifications'): void
    {
        $this->title       = $title;
        $this->description = $description;
        $this->enableLabel = $enableLabel;
    }

    public function enroll(): void
    {
        if ($this->state !== 'idle') {
            return;
        }
        $this->state = 'pending';
        PushNotifications::enroll();
    }

    #[OnNative(TokenGenerated::class)]
    public function onTokenGenerated(string $token, string $platform = 'unknown'): void
    {
        if ($token === '') {
            $this->state = 'declined';
            return;
        }
        app(Persistence::class)->enrollPush($token, $platform);
        $this->state = 'enrolled';
    }

    public function render()
    {
        return view('livewire.push-enroll');
    }
}
