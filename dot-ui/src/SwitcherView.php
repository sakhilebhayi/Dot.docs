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
        // <x-dot.*> tags inside the components (the header prints <x-dot.nav-menu>, <x-dot.drawer>, <x-dot.account-menu>) resolve as they do
        // in a Laravel host, from components/dot/: a symlink in a temp view root points at the blade folder
        $root = sys_get_temp_dir().'/dot-ui-views-'.md5(__DIR__);
        $files->ensureDirectoryExists($root.'/components');
        if (! is_link($root.'/components/dot')) {
            @symlink(dirname(__DIR__).'/blade', $root.'/components/dot');
        }
        $factory = new Factory($resolver, new FileViewFinder($files, [dirname(__DIR__).'/blade', $root]), new Dispatcher($container));
        $container->instance(\Illuminate\Contracts\View\Factory::class, $factory);
        $container->instance('view', $factory);
        // the tag compiler asks the application for its namespace when it guesses a component class; there is none here
        $container->instance(\Illuminate\Contracts\Foundation\Application::class, new class () {
            public function getNamespace(): string
            {
                return 'App\\';
            }
        });
        Container::setInstance($container);

        $attributes = $props['attributes'] ?? [];
        $props['attributes'] = $attributes instanceof ComponentAttributeBag ? $attributes : new ComponentAttributeBag($attributes);

        return $factory->make($view, $props)->render();
    }
}
