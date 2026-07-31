<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Minimal module loader.
 *
 * A module is a folder under modules/ with a module.php manifest that returns
 * an array. The core discovers modules on boot and lets them contribute:
 *
 *   'name'     => 'downloads',                     // must equal the folder name
 *   'nav'      => ['label','route','icon','order'] // optional sidebar entry
 *   'routes'   => ['downloads' => Controller::class]
 *   'autoload' => ['Vendor\\Ns\\' => 'src/']       // PSR-4, relative to module
 *   'api'      => 'api/downloads.php'              // /api/module.php?m=<name>
 *   'styles'   => ['downloads.css']                // loaded globally (shell)
 *   'scripts'  => ['indicator.js']                 // loaded globally (shell)
 *   'templates'=> 'templates'                      // Twig namespace @<name>
 *
 * With modules/ absent or empty the app runs core-only. This is a directory
 * convention, not a plugin platform: no sandboxing, no versioned API; module
 * code is trusted exactly like the core (it runs in-process either way).
 */
final class Modules
{
    /** @var array<string, array<string, mixed>>|null name => manifest */
    private static ?array $manifests = null;

    /**
     * Discover manifests and register module autoloaders. Idempotent; called
     * once from the shared bootstrap.
     */
    public static function boot(): void
    {
        foreach (self::all() as $name => $manifest) {
            $autoload = $manifest['autoload'] ?? [];
            if (!is_array($autoload)) {
                continue;
            }

            foreach ($autoload as $prefix => $dir) {
                $base = self::dir($name) . '/' . trim((string) $dir, '/');
                spl_autoload_register(static function (string $class) use ($prefix, $base): void {
                    if (str_starts_with($class, (string) $prefix)) {
                        $file = $base . '/' . str_replace('\\', '/', substr($class, strlen((string) $prefix))) . '.php';
                        if (is_file($file)) {
                            require $file;
                        }
                    }
                });
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$manifests !== null) {
            return self::$manifests;
        }

        self::$manifests = [];

        if (!defined('MODULES_DIR') || !is_dir(MODULES_DIR)) {
            return self::$manifests;
        }

        foreach (glob(MODULES_DIR . '/*/module.php') ?: [] as $manifestFile) {
            $folder = basename(dirname($manifestFile));

            // Folder names are used in URLs and paths, so keep them strict.
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $folder)) {
                continue;
            }

            try {
                $manifest = require $manifestFile;
            } catch (\Throwable $e) {
                Log::logException($e);
                continue;
            }

            if (!is_array($manifest) || (string) ($manifest['name'] ?? '') !== $folder) {
                Log::logDebugMessage("Module manifest rejected (name must match folder): {$manifestFile}", self::class);
                continue;
            }

            self::$manifests[$folder] = $manifest;
        }

        ksort(self::$manifests);

        return self::$manifests;
    }

    /**
     * Route key => controller class, across all modules.
     *
     * @return array<string, class-string<Controller>>
     */
    public static function routes(): array
    {
        $routes = [];

        foreach (self::all() as $manifest) {
            foreach ((array) ($manifest['routes'] ?? []) as $key => $class) {
                if (is_string($key) && is_string($class)) {
                    /** @var class-string<Controller> $class */
                    $routes[$key] = $class;
                }
            }
        }

        return $routes;
    }

    /**
     * Sidebar entries, sorted by 'order'.
     *
     * @return array<int, array{label: string, route: string, icon: string}>
     */
    public static function navItems(): array
    {
        $items = [];

        foreach (self::all() as $manifest) {
            $nav = $manifest['nav'] ?? null;
            if (!is_array($nav)) {
                continue;
            }

            $items[] = [
                'label' => (string) ($nav['label'] ?? ($manifest['label'] ?? $manifest['name'])),
                'route' => (string) ($nav['route'] ?? ''),
                'icon' => (string) ($nav['icon'] ?? ''),
                'order' => (int) ($nav['order'] ?? 100),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static fn (array $i): array => [
            'label' => $i['label'],
            'route' => $i['route'],
            'icon' => $i['icon'],
        ], $items);
    }

    /**
     * Twig namespace => absolute template dir, for modules that ship templates.
     *
     * @return array<string, string>
     */
    public static function templatePaths(): array
    {
        $paths = [];

        foreach (self::all() as $name => $manifest) {
            $dir = self::dir($name) . '/' . trim((string) ($manifest['templates'] ?? 'templates'), '/');
            if (is_dir($dir)) {
                $paths[$name] = $dir;
            }
        }

        return $paths;
    }

    /**
     * Globally-loaded asset URLs (cache-busted by the module folder's mtime).
     *
     * @return array{styles: array<int, string>, scripts: array<int, string>}
     */
    public static function globalAssets(): array
    {
        $styles = [];
        $scripts = [];

        foreach (self::all() as $name => $manifest) {
            $version = (string) @filemtime(self::dir($name) . '/module.php');
            foreach ((array) ($manifest['styles'] ?? []) as $file) {
                $styles[] = self::assetUrl($name, (string) $file, $version);
            }
            foreach ((array) ($manifest['scripts'] ?? []) as $file) {
                $scripts[] = self::assetUrl($name, (string) $file, $version);
            }
        }

        return ['styles' => $styles, 'scripts' => $scripts];
    }

    public static function assetUrl(string $module, string $file, string $version = ''): string
    {
        return '/api/module-asset.php?m=' . rawurlencode($module) . '&f=' . rawurlencode($file)
            . ($version !== '' ? '&v=' . rawurlencode($version) : '');
    }

    /**
     * Validated absolute path of a module asset, or null when it doesn't exist
     * or tries to escape the module's assets directory.
     */
    public static function assetPath(string $module, string $file): ?string
    {
        if (!isset(self::all()[$module]) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $file) || str_contains($file, '..')) {
            return null;
        }

        $path = self::dir($module) . '/assets/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * Absolute path of the module's API handler, or null when it has none.
     */
    public static function apiHandler(string $module): ?string
    {
        $manifest = self::all()[$module] ?? null;
        if ($manifest === null) {
            return null;
        }

        $relative = trim((string) ($manifest['api'] ?? ''), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $path = self::dir($module) . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    private static function dir(string $name): string
    {
        return MODULES_DIR . '/' . $name;
    }
}
