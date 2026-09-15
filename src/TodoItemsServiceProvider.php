<?php

namespace Timot\TodoItems;

use Illuminate\Support\ServiceProvider;
use Timot\TodoItems\Commands\ClaimCommand;
use Timot\TodoItems\Commands\DoneCommand;
use Timot\TodoItems\Commands\IndexCommand;
use Timot\TodoItems\Commands\ListCommand;
use Timot\TodoItems\Commands\NewCommand;

/**
 * Registers the five `todo:*` commands and resolves where the list lives.
 *
 * The paths are bound here rather than read inside the classes so that a
 * consuming project configures the package once, and so that a test can hand
 * any of them a temp tree without touching config at all — every constructor
 * still takes its own path and falls back to the shared default.
 */
class TodoItemsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/todo-items.php', 'todo-items');

        $this->app->singleton(TodoIds::class, fn () => new TodoIds(
            $this->path('todo-items.ids'),
        ));

        $this->app->singleton(TodoClaims::class, fn () => new TodoClaims(
            $this->path('todo-items.claims'),
        ));

        $this->app->singleton(TodoRepository::class, fn ($app) => new TodoRepository(
            $this->path('todo-items.directory'),
            $this->path('todo-items.index'),
            $app->make(TodoIds::class),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ClaimCommand::class,
            DoneCommand::class,
            IndexCommand::class,
            ListCommand::class,
            NewCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/todo-items.php' => config_path('todo-items.php'),
        ], 'todo-items-config');
    }

    /**
     * A configured path, or null to let the class use its own default.
     *
     * An empty string is treated as unset: a published config whose value came
     * from an unset environment variable should fall back to the default rather
     * than resolve the list to the filesystem root.
     */
    private function path(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
