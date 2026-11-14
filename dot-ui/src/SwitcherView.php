<?php

declare(strict_types=1);

namespace Dot\Ui;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ComponentAttributeBag;

/**
 * Renders blade/switcher.blade.php outside a Laravel app (the static demo and the markup tests), from a ready view
 * model. Not used by hosts: they use <x-dot.switcher>.
 */
final class SwitcherView
{
    /**
     * @param  array<string, mixed>  $model  A PlatformSwitcher view model.
     * @param  array<string, mixed>  $props  platform, company, mode, companyName, logos.
     */
    public static function render(array $model, array $props = []): string
    {
        return self::component('switcher', $props + ['model' => $model]);
    }

    /**
     * Renders any anonymous component of blade/ (`search`, `filter-search`, `switcher`) with plain props. Slots are
     * passed as `Illuminate\View\ComponentSlot` values. Used by the markup tests and the static demo build.
     *
     * @param  array<string, mixed>  $props  Component props; `attributes` may be a ComponentAttributeBag or an array.
     */
    public static function component(string $view, array $props = []): string
    {
        $files = new Filesystem();
        $cache = sys_get_temp_dir().'/dot-ui-blade-'.md5(__DIR__);
        $files->ensureDirectoryExists($cache);

        $container = new Container();
        $compiler = new BladeCompiler($files, $cache);
        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));
        $factory = new Factory($resolver, new FileViewFinder($files, [dirname(__DIR__).'/blade']), new Dispatcher($container));

        $attributes = $props['attributes'] ?? [];
        $props['attributes'] = $attributes instanceof ComponentAttributeBag ? $attributes : new ComponentAttributeBag($attributes);

        return $factory->make($view, $props)->render();
    }
}
