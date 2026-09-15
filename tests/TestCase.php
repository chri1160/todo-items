<?php

namespace Timot\TodoItems\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Timot\TodoItems\TodoItemsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TodoItemsServiceProvider::class];
    }

    /**
     * A scratch directory outside the package, cleaned up by the test that made it.
     *
     * Every test here works on a temp tree rather than the repository's own
     * `todo/`: these classes write and delete files, and a test that reached the
     * real list would rewrite the items it was run to protect.
     */
    protected function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/todo-items-tests/'.$name;
    }
}
