<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin\Seeders;

/**
 * Synthesises placeholder featured images for seeded demo posts.
 *
 * No external dependencies — uses GD (same extension BuildAssembler
 * relies on). Each image is a 1200×675 solid-colour gradient keyed off
 * the post slug so the same slug always gets the same colour, and the
 * post title renders as a white glyph overlay. Ugly but deterministic
 * and legally unambiguous (no stock photos, no licensing).
 *
 * Attachments are tagged with `_mua_seeded_by=content-showcase` so the
 * reset action can sweep them up.
 */
final class DemoImageFactory
{
    private const WIDTH  = 1200;
    private const HEIGHT = 675;

    /**
     * Build + attach a featured image; returns the attachment ID.
     * Returns 0 when GD is missing or uploads aren't writable so the
     * caller can fall through to "no featured image" gracefully.
     */
    public static function create(string $slug, string $title): int
    {
        if (! \function_exists('imagecreatetruecolor')) {
            return 0;
        }
        if (! \function_exists('wp_upload_dir') || ! \function_exists('wp_insert_attachment')) {
            return 0;
        }

        $png = self::render($slug, $title);
        if ($png === null) {
            return 0;
        }

        $uploads = wp_upload_dir();
        if (! \is_array($uploads) || ! empty($uploads['error']) || empty($uploads['path'])) {
            return 0;
        }

        $filename = sanitize_file_name($slug . '.png');
        $target   = trailingslashit((string) $uploads['path']) . $filename;
        if (@\file_put_contents($target, $png) === false) {
            return 0;
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => $title,
            'post_status'    => 'inherit',
            'post_name'      => $slug,
        ], $target);
        if (is_wp_error($attachmentId) || ! $attachmentId) {
            @\unlink($target);
            return 0;
        }

        if (\function_exists('wp_generate_attachment_metadata') && \function_exists('wp_update_attachment_metadata')) {
            $meta = wp_generate_attachment_metadata((int) $attachmentId, $target);
            if (\is_array($meta)) {
                wp_update_attachment_metadata((int) $attachmentId, $meta);
            }
        }
        update_post_meta((int) $attachmentId, '_mua_seeded_by', 'content-showcase');

        return (int) $attachmentId;
    }

    /**
     * Delete every seeded attachment the factory produced for the
     * content-showcase seeder. Called by the reset action.
     *
     * @return int Number of attachments deleted.
     */
    public static function reset(): int
    {
        $ids = \function_exists('get_posts') ? get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => '_mua_seeded_by', 'value' => 'content-showcase', 'compare' => '=' ],
            ],
        ]) : [];

        $deleted = 0;
        foreach (\is_array($ids) ? $ids : [] as $id) {
            if (wp_delete_attachment((int) $id, true)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Render the PNG bytes. Returns null if GD allocation fails so
     * the caller can skip attachment creation.
     */
    private static function render(string $slug, string $title): ?string
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (! $img instanceof \GdImage) {
            return null;
        }
        imagesavealpha($img, false);

        [ $r, $g, $b ] = self::colourFor($slug);

        // Fill with the primary colour, then draw a 40% opaque dark
        // overlay band on the bottom third so the white title text
        // has enough contrast to read.
        $primary = imagecolorallocate($img, $r, $g, $b);
        if ($primary === false) {
            imagedestroy($img);
            return null;
        }
        imagefill($img, 0, 0, $primary);

        $overlay = imagecolorallocatealpha($img, 0, 0, 0, 60);
        if ($overlay !== false) {
            imagefilledrectangle($img, 0, (int) (self::HEIGHT * 0.62), self::WIDTH, self::HEIGHT, $overlay);
        }

        $fg = imagecolorallocate($img, 255, 255, 255);
        if ($fg !== false) {
            self::drawTitle($img, $title, $fg);
        }

        \ob_start();
        imagepng($img);
        $png = (string) \ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    /**
     * Deterministic colour from the slug — hash, slice, RGB. Adequate
     * contrast because we clamp each channel to 60–200 so nothing
     * comes out near-black or near-white.
     *
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    private static function colourFor(string $slug): array
    {
        $hash = \md5($slug);
        return [
            \max(60, \min(200, (int) \hexdec(\substr($hash, 0, 2)))),
            \max(60, \min(200, (int) \hexdec(\substr($hash, 2, 2)))),
            \max(60, \min(200, (int) \hexdec(\substr($hash, 4, 2)))),
        ];
    }

    /**
     * Line-wrap + draw the title into the lower third using the
     * built-in font 5 scaled via tile repetition — same cheap trick
     * BuildAssembler uses for app icons. Keeps the factory
     * zero-dependency.
     *
     */
    private static function drawTitle(\GdImage $img, string $title, int $fg): void
    {
        $tile      = 2;                    // 18x30 effective glyph.
        $glyphW    = 9  * $tile;
        $glyphH    = 15 * $tile;
        $maxChars  = (int) \floor((self::WIDTH - 80) / $glyphW);
        $lines     = self::wrap($title, $maxChars);
        $totalH    = \count($lines) * ($glyphH + 8);
        $cursorY   = (int) (self::HEIGHT - 60 - $totalH / 2);

        foreach ($lines as $line) {
            $textW  = \strlen($line) * $glyphW;
            $cursorX = (int) ((self::WIDTH - $textW) / 2);
            for ($dx = 0; $dx < $tile; $dx++) {
                for ($dy = 0; $dy < $tile; $dy++) {
                    imagestring($img, 5, $cursorX + $dx, $cursorY + $dy, $line, $fg);
                }
            }
            $cursorY += $glyphH + 8;
        }
    }

    /**
     * Greedy word wrapping — good enough for 3-6 word titles we seed.
     *
     * @return list<string>
     */
    private static function wrap(string $title, int $maxChars): array
    {
        $words = \preg_split('/\s+/', \trim($title)) ?: [];
        if ($maxChars < 8 || $words === []) {
            return [ \strtoupper(\substr($title, 0, \max(1, $maxChars))) ];
        }

        $lines   = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (\strlen($candidate) > $maxChars) {
                if ($current !== '') {
                    $lines[] = \strtoupper($current);
                }
                $current = \strtoupper(\substr($word, 0, $maxChars));
                continue;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $lines[] = \strtoupper($current);
        }
        return $lines;
    }
}
