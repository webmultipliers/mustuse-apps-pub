<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Marker event the shell passes to `Microphone::record()->event()->start()`.
 *
 * NativePHP Mobile fires this class on the device after a recording
 * completes; `NativeEdge::onAudioRecorded()` listens for it via
 * `#[OnNative(AudioRecorded::class)]` and parks the resulting path on
 * `$lastCallback['microphone']` for any native-action block on-screen
 * to render.
 *
 * It's a publisher-owned class (not a NativePHP built-in) because the
 * Microphone facade requires callers to supply their own event class —
 * the kitchen-sink demo app does the same.
 */
final class AudioRecorded
{
}
