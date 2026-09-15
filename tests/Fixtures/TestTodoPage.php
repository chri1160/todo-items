<?php

namespace Timot\TodoItems\Tests\Fixtures;

use Timot\TodoItems\Filament\TodoListPage;

/** A consumer's subclass, which is the only way the page is ever registered. */
class TestTodoPage extends TodoListPage
{
    protected static ?string $slug = 'test-todos';

    public static bool $allowed = true;

    public static function canAccess(): bool
    {
        return static::$allowed;
    }
}
