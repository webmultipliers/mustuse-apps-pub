<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for WP core classes used by unit tests.
 *
 * Only loaded when WordPress is not present (i.e. unit tests running
 * against Brain\Monkey). Integration tests that boot a real WP must
 * skip this file.
 */

if (! class_exists('WP_Error')) {
    final class WP_Error
    {
        /** @var array<string, string[]> */
        private array $errors = [];
        /** @var array<string, mixed> */
        private array $errorData = [];

        public function __construct(string $code = '', string $message = '', mixed $data = null)
        {
            if ($code !== '') {
                $this->errors[$code] = [$message];
                if ($data !== null) {
                    $this->errorData[$code] = $data;
                }
            }
        }

        public function get_error_code(): string
        {
            return (string) array_key_first($this->errors) ?: '';
        }

        public function get_error_message(string $code = ''): string
        {
            $code = $code !== '' ? $code : $this->get_error_code();
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data(string $code = ''): mixed
        {
            $code = $code !== '' ? $code : $this->get_error_code();
            return $this->errorData[$code] ?? null;
        }
    }
}

if (! class_exists('WP_Post')) {
    final class WP_Post
    {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_name = '';
        public string $post_content = '';
        public string $post_status = 'publish';
        public string $post_type = 'post';
        public int $menu_order = 0;
        public int $post_parent = 0;

        /** @param array<string, mixed> $fields */
        public static function make(array $fields): self
        {
            $p = new self();
            foreach ($fields as $k => $v) {
                if (property_exists($p, $k)) {
                    $p->{$k} = $v;
                }
            }
            return $p;
        }
    }
}

if (! class_exists('WP_CLI')) {
    /**
     * Minimal WP_CLI shim — log/warning/success/error are captured on the
     * class so tests can assert without depending on real WP-CLI plumbing.
     */
    final class WP_CLI
    {
        /** @var string[] */
        public static array $log = [];
        /** @var string[] */
        public static array $errors = [];
        /** @var string[] */
        public static array $warnings = [];
        /** @var string[] */
        public static array $successes = [];
        /** @var array<string, mixed> */
        public static array $commands = [];

        public static function reset(): void
        {
            self::$log = [];
            self::$errors = [];
            self::$warnings = [];
            self::$successes = [];
            self::$commands = [];
        }

        public static function add_command(string $name, mixed $callable): void
        {
            self::$commands[$name] = $callable;
        }

        public static function log(string $message): void
        {
            self::$log[] = $message;
        }

        public static function error(string $message): void
        {
            self::$errors[] = $message;
            // Real WP_CLI::error halts via exit(1); throw to mirror that
            // for tests without exiting the PHPUnit process.
            throw new \RuntimeException('WP_CLI::error: ' . $message);
        }

        public static function warning(string $message): void
        {
            self::$warnings[] = $message;
        }

        public static function success(string $message): void
        {
            self::$successes[] = $message;
        }
    }

    if (! defined('WP_CLI')) {
        define('WP_CLI', true);
    }
}

if (! class_exists('wpdb')) {
    /**
     * Minimal wpdb stub so classes that type-hint `\wpdb` can be
     * unit-tested without booting WordPress. Constructor args are
     * optional (real wpdb requires 4) — tests need to instantiate
     * without real DB credentials. Tests may override $GLOBALS['wpdb']
     * with their own subclass to assert query shapes.
     */
    class wpdb
    {
        public string $prefix = 'wp_';

        public function __construct(string $dbuser = '', string $dbpassword = '', string $dbname = '', string $dbhost = '')
        {
        }

        /** @return mixed */
        public function get_var(string $query, int $x = 0, int $y = 0)
        {
            return null;
        }

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        public function query(string $query): int
        {
            return 0;
        }

        /** @return array<int, mixed> */
        public function get_results(string $query, string $output = 'OBJECT'): array
        {
            return [];
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    final class WP_REST_Request
    {
        /** @var array<string, string> */
        private array $headers = [];
        private string $body = '';

        /** @param array<string, string> $headers */
        public function __construct(array $headers = [], string $body = '')
        {
            foreach ($headers as $k => $v) {
                $this->headers[strtolower($k)] = $v;
            }
            $this->body = $body;
        }

        public function get_header(string $name): ?string
        {
            return $this->headers[strtolower($name)] ?? null;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        /**
         * Decodes the request body as JSON so routes that call
         * `$request->get_json_params()` can be unit-tested without booting
         * WP. Returns an empty array on malformed bodies — matching the real
         * WP fallback behavior of `null → $default ?: []` at call sites.
         *
         * @return array<string, mixed>
         */
        public function get_json_params(): array
        {
            if ($this->body === '') {
                return [];
            }
            $decoded = json_decode($this->body, true);
            return is_array($decoded) ? $decoded : [];
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    /**
     * Minimal WP_REST_Response stub for unit tests that construct responses
     * and read them back. Matches the narrow slice of the real class our
     * routes use (constructor + get_data + get_status).
     */
    class WP_REST_Response
    {
        private mixed $data;
        private int $status;

        public function __construct(mixed $data = null, int $status = 200)
        {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function header(string $key, string $value): void
        {
            // no-op — shape hook so production routes can call ->header()
        }
    }
}
