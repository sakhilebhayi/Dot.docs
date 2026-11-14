<?php

declare(strict_types=1);

namespace Dot\Ui;

/**
 * The original platform logos, shipped with the package in `img/platforms/` and described by `manifest.json`
 * (file name and sha256 per platform key). The files are byte copies of InfoDot's own set: never redraw, recolour
 * or replace them. A key with no file has no logo and the tile shows the name only, never a generated icon.
 */
final class PlatformLogos
{
    /** The box InfoDot fits a logo into (its `.ix-bay__logo img` max sizes). */
    public const MAX_WIDTH = 112;

    public const MAX_HEIGHT = 68;

    /** @var array<string, array{file: string, sha256: string, width: int, height: int}>|null */
    private static ?array $platforms = null;

    /** An explicit directory set by a host or a test (see `useDirectory()`); null means "resolve it". */
    private static ?string $override = null;

    /** Test seam: replaces the Laravel `config()` / `public_path()` lookups. `fn (string $key): mixed`. */
    private static ?\Closure $resolver = null;

    /** @return array<string, array{file: string, sha256: string, width: int, height: int}> */
    public static function all(): array
    {
        if (self::$platforms === null) {
            $json = @file_get_contents(self::directory().'/manifest.json');
            $data = is_string($json) ? json_decode($json, true) : null;
            self::$platforms = is_array($data) && is_array($data['platforms'] ?? null) ? $data['platforms'] : [];
        }

        return self::$platforms;
    }

    /** The file name for a key (`sheet` is `sheet.png`), or null when the package has no logo for it. */
    public static function file(string $key): ?string
    {
        return self::all()[$key]['file'] ?? null;
    }

    /**
     * The size to print: the PNG scaled to fit InfoDot's 112 x 68 box. Explicit width and height keep the layout
     * from jumping and need no inline style.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function size(string $key): ?array
    {
        $row = self::all()[$key] ?? null;

        if ($row === null || $row['width'] < 1 || $row['height'] < 1) {
            return null;
        }

        $scale = min(self::MAX_WIDTH / $row['width'], self::MAX_HEIGHT / $row['height']);

        return [(int) round($row['width'] * $scale), (int) round($row['height'] * $scale)];
    }

    /**
     * Where `manifest.json` is read from. First match wins:
     *   1. `useDirectory()` (a host or a test setting it explicitly);
     *   2. config key `dot-ui.logo_manifest` (a directory, or the path of the manifest file itself);
     *   3. `<package>/img/platforms` (dot-ui's own checkout, and a synced host's `dot-ui/img/platforms`);
     *   4. `public/dot-ui/img/platforms` (where the PNGs are served from, so a host that only copied `public/` works).
     * A candidate counts only if it holds a `manifest.json`. When none does, the package-relative path is returned so
     * the failure is the same quiet "no logos" as before, never an exception.
     */
    public static function directory(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }

        foreach (self::candidates() as $candidate) {
            if (is_file($candidate.'/manifest.json')) {
                return $candidate;
            }
        }

        return dirname(__DIR__).'/img/platforms';
    }

    /**
     * Pins the directory (null clears it). Also forgets the cached manifest so the next read uses the new place.
     */
    public static function useDirectory(?string $directory): void
    {
        self::$override = $directory !== null ? rtrim($directory, '/') : null;
        self::$platforms = null;
    }

    /** Replaces the framework lookups (`config()`, `public_path()`) for tests: the closure gets 'public_path' or a config key. */
    public static function useResolver(?callable $resolver): void
    {
        self::$resolver = $resolver !== null ? \Closure::fromCallable($resolver) : null;
        self::$platforms = null;
    }

    /** Forgets the cached manifest (tests, and a host that swaps config at runtime). */
    public static function flush(): void
    {
        self::$platforms = null;
    }

    /** @return list<string> */
    private static function candidates(): array
    {
        $found = [];

        $configured = self::config('dot-ui.logo_manifest');
        if (is_string($configured) && $configured !== '') {
            $found[] = str_ends_with($configured, '.json') ? dirname($configured) : rtrim($configured, '/');
        }

        $found[] = dirname(__DIR__).'/img/platforms';

        $public = self::config('dot-ui.public_path');
        $found[] = (is_string($public) && $public !== '' ? rtrim($public, '/') : self::publicPath()).'/dot-ui/img/platforms';

        return array_values(array_filter($found, static fn (string $p): bool => $p !== '/dot-ui/img/platforms'));
    }

    private static function config(string $key): mixed
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($key);
        }

        try {
            return function_exists('config') && function_exists('app') && app()->bound('config') ? config($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function publicPath(): string
    {
        if (self::$resolver !== null) {
            $path = (self::$resolver)('public_path');

            return is_string($path) ? $path : '';
        }

        try {
            return function_exists('public_path') ? public_path() : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
