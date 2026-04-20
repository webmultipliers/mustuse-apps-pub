<?php

declare(strict_types=1);

namespace MustUse\Pub\Content;

use MustUse\Pub\Data\Models\App;

/**
 * Per-device personalization (bookmarks, read history, push tokens).
 *
 * Scope is device, not user — keyed on the X-MUA-Device-Id header so
 * signed-out apps still get personalization. Default backend is
 * `wp_options`, keyed by a SHA-256 hash of the device id so a leaked
 * backup doesn't de-anonymize users. Publishers override any method
 * through the `mua_device_state_*` filters to delegate to a CRM / real
 * user account model.
 *
 * Wire contract:
 *   bookmarks: [{ post_id, post_type, bookmarked_at }]  (max 100)
 *   history:   [{ post_id, post_type, visited_at    }]  (max 500, reverse-chron)
 *   push:      { token, platform, enrolled_at }
 */
final class DeviceStateStore
{
    public const MAX_BOOKMARKS = 100;
    public const MAX_HISTORY   = 500;

    /**
     * Validate + return the X-MUA-Device-Id header value. Empty string
     * on missing/malformed input so callers short-circuit to 400.
     */
    public static function deviceIdFromHeader(?string $raw): string
    {
        $id = \is_string($raw) ? \trim($raw) : '';
        if ($id === '' || \strlen($id) > 128) {
            return '';
        }
        if (! \preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            return '';
        }
        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listBookmarks(App $app, string $deviceId): array
    {
        /** @see mua_device_state_bookmarks_list — override for custom backends */
        $custom = apply_filters('mua_device_state_bookmarks_list', null, $app, $deviceId);
        if (\is_array($custom)) {
            return $custom;
        }
        $state = self::read($app, $deviceId);
        return \is_array($state['bookmarks'] ?? null) ? $state['bookmarks'] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function addBookmark(App $app, string $deviceId, int $postId, string $postType): array
    {
        /** @see mua_device_state_bookmarks_add */
        $custom = apply_filters('mua_device_state_bookmarks_add', null, $app, $deviceId, $postId, $postType);
        if (\is_array($custom)) {
            return $custom;
        }
        if ($postId <= 0) {
            return self::listBookmarks($app, $deviceId);
        }

        // Upsert: strip any existing entry for this post before prepending,
        // so a repeat-click just bumps the timestamp without duplicating.
        $state     = self::read($app, $deviceId);
        $bookmarks = \is_array($state['bookmarks'] ?? null) ? $state['bookmarks'] : [];
        $bookmarks = \array_values(\array_filter(
            $bookmarks,
            static fn ($row): bool => \is_array($row) && (int) ($row['post_id'] ?? 0) !== $postId
        ));
        \array_unshift($bookmarks, [
            'post_id'        => $postId,
            'post_type'      => $postType ?: 'post',
            'bookmarked_at'  => \gmdate('c'),
        ]);
        if (\count($bookmarks) > self::MAX_BOOKMARKS) {
            $bookmarks = \array_slice($bookmarks, 0, self::MAX_BOOKMARKS);
        }
        $state['bookmarks'] = $bookmarks;
        self::write($app, $deviceId, $state);
        return $bookmarks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function removeBookmark(App $app, string $deviceId, int $postId): array
    {
        /** @see mua_device_state_bookmarks_remove */
        $custom = apply_filters('mua_device_state_bookmarks_remove', null, $app, $deviceId, $postId);
        if (\is_array($custom)) {
            return $custom;
        }

        $state     = self::read($app, $deviceId);
        $bookmarks = \is_array($state['bookmarks'] ?? null) ? $state['bookmarks'] : [];
        $bookmarks = \array_values(\array_filter(
            $bookmarks,
            static fn ($row): bool => \is_array($row) && (int) ($row['post_id'] ?? 0) !== $postId
        ));
        $state['bookmarks'] = $bookmarks;
        self::write($app, $deviceId, $state);
        return $bookmarks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recordVisit(App $app, string $deviceId, int $postId, string $postType): array
    {
        /** @see mua_device_state_history_record */
        $custom = apply_filters('mua_device_state_history_record', null, $app, $deviceId, $postId, $postType);
        if (\is_array($custom)) {
            return $custom;
        }
        if ($postId <= 0) {
            return [];
        }

        // De-dupe on post_id so repeat visits bump the timestamp to the
        // front rather than stacking multiple rows per post.
        $state   = self::read($app, $deviceId);
        $history = \is_array($state['history'] ?? null) ? $state['history'] : [];
        $history = \array_values(\array_filter(
            $history,
            static fn ($row): bool => \is_array($row) && (int) ($row['post_id'] ?? 0) !== $postId
        ));
        \array_unshift($history, [
            'post_id'    => $postId,
            'post_type'  => $postType ?: 'post',
            'visited_at' => \gmdate('c'),
        ]);
        if (\count($history) > self::MAX_HISTORY) {
            $history = \array_slice($history, 0, self::MAX_HISTORY);
        }
        $state['history'] = $history;
        self::write($app, $deviceId, $state);
        return $history;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listHistory(App $app, string $deviceId): array
    {
        /** @see mua_device_state_history_list */
        $custom = apply_filters('mua_device_state_history_list', null, $app, $deviceId);
        if (\is_array($custom)) {
            return $custom;
        }
        $state = self::read($app, $deviceId);
        return \is_array($state['history'] ?? null) ? $state['history'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function enrollPushToken(App $app, string $deviceId, string $token, string $platform): array
    {
        /** @see mua_device_state_push_enroll — hook this to register with FCM/APNs */
        $custom = apply_filters('mua_device_state_push_enroll', null, $app, $deviceId, $token, $platform);
        if (\is_array($custom)) {
            return $custom;
        }

        $record = [
            'token'        => $token,
            'platform'     => \in_array($platform, ['ios', 'android', 'web'], true) ? $platform : 'unknown',
            'enrolled_at'  => \gmdate('c'),
        ];
        $state = self::read($app, $deviceId);
        $state['push'] = $record;
        self::write($app, $deviceId, $state);
        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(App $app, string $deviceId): array
    {
        $value = get_option(self::optionKey($app, $deviceId), []);
        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function write(App $app, string $deviceId, array $state): void
    {
        // autoload=no: device state is read on demand, not on every
        // page load, and these blobs add up across many devices.
        update_option(self::optionKey($app, $deviceId), $state, false);
    }

    /** Hashed so the DB never sees raw device ids. */
    private static function optionKey(App $app, string $deviceId): string
    {
        return 'mua_dev_' . $app->id() . '_' . \substr(\hash('sha256', $deviceId), 0, 40);
    }
}
