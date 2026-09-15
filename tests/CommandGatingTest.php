<?php

namespace Timot\TodoItems\Tests;

use Illuminate\Console\Command;
use ReflectionClass;
use Symfony\Component\Console\Application as SymfonyApplication;
use Timot\TodoItems\Commands\ListCommand;
use Timot\TodoItems\Commands\TodoCommand;

/**
 * These commands write to the consuming repository's working tree, so they must
 * not exist in an environment where that tree isn't the real one.
 *
 * The membership assertion is the load-bearing half. Gating today's five
 * commands is a one-off anybody can do; what keeps it true is a test that fails
 * when a *sixth* lands without extending the base, because nothing about adding
 * a file to a directory announces that the directory has a contract.
 */
class CommandGatingTest extends TestCase
{
    public function test_every_command_in_the_package_extends_the_gated_base(): void
    {
        $commands = $this->commandClasses();

        // Without this the loop below can pass by iterating nothing, which would
        // make a broken glob look like a green guarantee.
        $this->assertNotEmpty($commands, 'No command classes discovered — the glob is wrong.');

        foreach ($commands as $class) {
            $this->assertTrue(
                is_subclass_of($class, TodoCommand::class),
                "{$class} does not extend TodoCommand, so it would register in production.",
            );
        }
    }

    public function test_it_is_enabled_in_the_default_environments(): void
    {
        foreach (TodoCommand::DEFAULT_ENVIRONMENTS as $environment) {
            $this->app['env'] = $environment;

            $this->assertTrue($this->command()->isEnabled(), "should be enabled in {$environment}");
        }
    }

    public function test_it_is_disabled_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse($this->command()->isEnabled());
    }

    public function test_a_project_can_widen_the_environments(): void
    {
        $this->app['env'] = 'staging';
        $this->assertFalse($this->command()->isEnabled());

        $this->app['config']->set('todo-items.environments', ['local', 'testing', 'staging']);
        $this->assertTrue($this->command()->isEnabled());
    }

    public function test_a_missing_config_key_falls_back_to_the_conservative_default(): void
    {
        $this->app['config']->set('todo-items.environments', null);
        $this->app['env'] = 'production';

        $this->assertFalse($this->command()->isEnabled(), 'an unset key must not open production');
    }

    /**
     * The point of isEnabled() over hiding: Symfony drops a disabled command
     * during registration, so it cannot be run by anyone who types its name.
     */
    public function test_a_disabled_command_is_absent_rather_than_hidden(): void
    {
        $this->app['env'] = 'production';

        $console = new SymfonyApplication;
        $this->register($console, $this->command());

        $this->assertFalse($console->has('todo:list'), 'a disabled command must not be registered at all');
    }

    public function test_an_enabled_command_is_registered(): void
    {
        $this->app['env'] = 'local';

        $console = new SymfonyApplication;
        $this->register($console, $this->command());

        $this->assertTrue($console->has('todo:list'));
    }

    /**
     * Symfony 8 renamed `add()` to `addCommand()`, and this package supports
     * illuminate ^11-^13, which spans both. Pick whichever the installed
     * version exposes so the assertion means the same thing either way.
     */
    private function register(SymfonyApplication $console, Command $command): void
    {
        method_exists($console, 'addCommand')
            ? $console->addCommand($command)
            : $console->add($command);
    }

    private function command(): Command
    {
        $command = new ListCommand;
        $command->setLaravel($this->app);

        return $command;
    }

    /**
     * Every concrete command class shipped in src/Commands.
     *
     * Read off the filesystem rather than a hand-kept list, so a new file is
     * covered the moment it lands.
     *
     * @return list<class-string>
     */
    private function commandClasses(): array
    {
        $classes = [];

        foreach (glob(__DIR__.'/../src/Commands/*.php') ?: [] as $file) {
            $class = 'Timot\\TodoItems\\Commands\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
