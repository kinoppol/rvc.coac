<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the SPA shell (public/app.html) for the asset mode set by the admin:
 *
 * - development: JS/CSS keep their plain URLs; .htaccess makes browsers ask the
 *   server on every load (no-cache), so an edited file shows up at once.
 * - production: every JS/CSS URL carries ?v=<a version of that file> (an import map
 *   covers the modules' own imports), and .htaccess lets those URLs be cached for a
 *   year. A deploy changes the version of each changed file, so nothing goes stale,
 *   and unchanged files never need a round trip to the server.
 *
 * Both modes preload the core modules (and the view of the current route) so the
 * browser fetches them in parallel instead of one import level at a time.
 */
class AppShell
{
    public const MODES = ['development', 'production'];

    public static function render(string $html, string $mode): string
    {
        $prod = $mode === 'production';
        $assets = __DIR__ . '/../../public/assets';
        $url = fn (string $rel) => './assets/' . $rel . ($prod ? '?v=' . self::version($assets . '/' . $rel) : '');

        $core = array_map(fn ($f) => 'js/' . basename($f), glob($assets . '/js/*.js') ?: []);
        $views = array_map(fn ($f) => 'js/views/' . basename($f), glob($assets . '/js/views/*.js') ?: []);

        // Lets the app notice a deploy while it is open (X-App-Build on API responses).
        $head = '<meta name="app-build" content="' . self::build() . "\">\n";
        if ($prod) {
            $imports = [];
            foreach (array_merge($core, $views) as $rel) {
                $imports['./assets/' . $rel] = $url($rel);
            }
            $head .= '<script type="importmap">' . json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES) . "</script>\n";
        }
        foreach ($core as $rel) {
            if ($rel !== 'js/print-slip.js') {
                $head .= '<link rel="modulepreload" href="' . htmlspecialchars($url($rel)) . "\">\n";
            }
        }
        // The view of the route being opened (hash routing: the server can't see it).
        $viewUrls = [];
        foreach ($views as $rel) {
            $viewUrls[basename($rel, '.js')] = $url($rel);
        }
        $head .= '<script>(function(v){var k=(location.hash.match(/^#\/([\w-]+)/)||[])[1]||"dashboard";if(!v[k])return;'
            . 'var l=document.createElement("link");l.rel="modulepreload";l.href=v[k];document.head.appendChild(l);})('
            . json_encode($viewUrls, JSON_UNESCAPED_SLASHES) . ");</script>\n";

        $html = str_replace('href="assets/css/app.css"', 'href="' . htmlspecialchars($url('css/app.css')) . '"', $html);
        $html = str_replace('src="assets/js/app.js"', 'src="' . htmlspecialchars($url('js/app.js')) . '"', $html);
        // Before the stylesheet and scripts: an import map must come before any module loads.
        return preg_replace('#<link rel="preconnect"#', $head . '<link rel="preconnect"', $html, 1) ?? $html;
    }

    /** One id for the current set of JS/CSS files: changes when any of them does. */
    public static function build(): string
    {
        static $build = null;
        if ($build === null) {
            $assets = __DIR__ . '/../../public/assets';
            $files = array_merge(glob($assets . '/js/*.js') ?: [], glob($assets . '/js/views/*.js') ?: [], glob($assets . '/css/*.css') ?: []);
            $build = base_convert((string) crc32(implode(',', array_map([self::class, 'version'], $files))), 10, 36);
        }
        return $build;
    }

    /** Changes whenever the file does (a deploy rewrites it). */
    private static function version(string $path): string
    {
        $stat = @stat($path);
        return $stat ? base_convert((string) crc32($stat['mtime'] . ':' . $stat['size']), 10, 36) : '0';
    }
}
