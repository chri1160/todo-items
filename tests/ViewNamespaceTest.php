<?php

namespace Timot\TodoItems\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\View;

/**
 * The package's views must resolve for a web request, not only a console one.
 *
 * The Filament page is rendered over HTTP and nowhere else, so this is the one
 * registration in the provider that must sit outside its `runningInConsole()` guard.
 * It did not, and the result was `No hint path defined for [todo-items]` in a browser
 * while the whole suite stayed green.
 *
 * That combination is the reason this test is written the way it is. PHPUnit runs in
 * console, so `runningInConsole()` is true in every ordinary test, and an ordinary test
 * therefore exercises the *opposite* branch from the one a browser takes — it cannot
 * fail no matter how wrong the provider is. `APP_RUNNING_IN_CONSOLE` is the supported
 * way to say otherwise ({@see Application::runningInConsole()}),
 * and setting it is what makes this a test rather than a restatement.
 */
class ViewNamespaceTest extends TestCase
{
    /**
     * Run the application as though it were serving a request.
     *
     * Set before `parent::setUp()`, which is where the application is created: the flag
     * is resolved once and cached on first use, so anything later — `defineEnvironment`,
     * `getEnvironmentSetUp` — is already too late and leaves the test passing by the
     * console path it was written to avoid. The first assertion below exists because
     * that is exactly what happened on the first attempt at this file.
     */
    protected function setUp(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');
        unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);

        parent::tearDown();
    }

    public function test_the_application_under_test_is_not_running_in_console(): void
    {
        // Asserted rather than assumed, because every other assertion here is
        // meaningless if the override did not take: the provider would register the
        // views by the console path and the test would pass for the wrong reason.
        $this->assertFalse(
            $this->app->runningInConsole(),
            'The override did not take, so this file proves nothing about a web request.',
        );
    }

    public function test_the_view_namespace_is_registered_for_a_web_request(): void
    {
        $this->assertArrayHasKey('todo-items', View::getFinder()->getHints());
    }

    public function test_the_page_view_resolves_for_a_web_request(): void
    {
        // The namespace existing is not the same as the file being behind it, and the
        // exception a browser saw names the namespace rather than the view.
        $this->assertTrue(View::exists('todo-items::page'));
        $this->assertTrue(View::exists('todo-items::body'));
    }
}
