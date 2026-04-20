<?php

declare(strict_types=1);

namespace MustUse\Pub\Data;

use MustUse\Pub\Data\Models\App;

final class SchemaManager
{
    public static function activate(): void
    {
        App::registerPostType();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        unregister_post_type(App::POST_TYPE);
        flush_rewrite_rules();
    }
}
