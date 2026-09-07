<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Config;

/**
 * The URL generator that makes `APP_URL` the single source of every absolute
 * link (BR-36, CONSTITUTION art. 12).
 *
 * Laravel's generator derives the host and the scheme of an absolute link from
 * the incoming request, which means the `Host` header a caller sends decides
 * what a password-reset button, a certificate verification address or a QR
 * target points at. On shared hosting behind a proxy that is a live injection
 * route, and it also makes the same link come out differently depending on
 * whether it was built inside a web request, a queued job or an artisan
 * command - which BR-36 forbids.
 *
 * So the configured base wins over the request, in every environment. An
 * explicit `URL::forceRootUrl()` or `URL::forceScheme()` still wins over the
 * configuration, because those are a deliberate override by the caller; the
 * request is what stops being consulted.
 *
 * @see BR-36 · PRD §12.2 · CONSTITUTION art. 6, art. 12
 */
final class ConfiguredUrlGenerator extends UrlGenerator
{
    /**
     * @param  bool|null  $secure
     * @return string
     */
    public function formatScheme($secure = null)
    {
        if ($secure === null && $this->forceScheme === null) {
            $configured = $this->configuredRoot();

            if ($configured !== null) {
                return str_starts_with($configured, 'http://') ? 'http://' : 'https://';
            }
        }

        return parent::formatScheme($secure);
    }

    /**
     * @param  string  $scheme
     * @param  string|null  $root
     * @return string
     */
    public function formatRoot($scheme, $root = null)
    {
        if ($root === null && $this->forcedRoot === null) {
            $root = $this->configuredRoot();
        }

        return parent::formatRoot($scheme, $root);
    }

    /** The configured base, or null when the platform has not been given one. */
    private function configuredRoot(): ?string
    {
        $configured = trim((string) Config::get('app.url', ''));

        return $configured === '' ? null : rtrim($configured, '/');
    }
}
