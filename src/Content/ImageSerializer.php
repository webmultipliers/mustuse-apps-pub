<?php

declare(strict_types=1);

namespace MustUse\Pub\Content;

/**
 * WordPress image → `{id, url, alt, width, height}`. Returns null on
 * empty input so callers can render a placeholder instead of broken
 * markup. Shared by BlockAttributeNormalizer, ContentMapper, and
 * ContextBuilder so every mobile template consumes one image contract.
 */
final class ImageSerializer
{
    /**
     * @return array{id:int,url:string,alt:string,width:int,height:int}|null
     */
    public static function fromRaw(mixed $raw): ?array
    {
        if (\is_string($raw) && $raw !== '') {
            return self::shape(0, $raw, '', 0, 0);
        }
        if (! \is_array($raw)) {
            return null;
        }

        $id  = isset($raw['id'])  ? (int)    $raw['id']  : 0;
        $url = isset($raw['url']) ? (string) $raw['url'] : '';

        if ($id > 0) {
            return self::fromAttachmentId($id, $url);
        }

        return $url !== ''
            ? self::shape(
                0,
                $url,
                (string) ($raw['alt']    ?? ''),
                (int)    ($raw['width']  ?? 0),
                (int)    ($raw['height'] ?? 0),
            )
            : null;
    }

    /**
     * @return array{id:int,url:string,alt:string,width:int,height:int}|null
     */
    public static function fromAttachmentId(int $id, string $fallbackUrl = ''): ?array
    {
        if ($id <= 0) {
            return $fallbackUrl !== '' ? self::shape(0, $fallbackUrl, '', 0, 0) : null;
        }

        $resolved = wp_get_attachment_image_url($id, 'large');
        $url      = \is_string($resolved) && $resolved !== '' ? $resolved : $fallbackUrl;
        if ($url === '') {
            return null;
        }

        $meta   = wp_get_attachment_metadata($id);
        $width  = \is_array($meta) && isset($meta['width'])  ? (int) $meta['width']  : 0;
        $height = \is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0;

        return self::shape(
            $id,
            $url,
            (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            $width,
            $height,
        );
    }

    /**
     * @return array{id:int,url:string,alt:string,width:int,height:int}
     */
    private static function shape(int $id, string $url, string $alt, int $width, int $height): array
    {
        return [
            'id'     => $id,
            'url'    => $url,
            'alt'    => $alt,
            'width'  => $width,
            'height' => $height,
        ];
    }
}
