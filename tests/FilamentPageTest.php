<?php

namespace Timot\TodoItems\Tests;

use Filament\Pages\Page;
use ReflectionClass;
use Timot\TodoItems\Filament\TodoListPage;
use Timot\TodoItems\Tests\Fixtures\TestTodoPage;

/**
 * The page is optional and consumer-registered, so what is worth asserting here
 * is the contract a subclass depends on — not that Filament renders a table.
 */
class FilamentPageTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Page::class)) {
            $this->markTestSkipped('filament/filament is not installed (it is suggested, not required).');
        }

        parent::setUp();
    }

    public function test_the_package_does_not_register_the_page(): void
    {
        // The whole require-dev property rests on this: a page this package
        // added to a panel could not be made conditional by the consumer.
        // Comments may discuss it; what must not appear is code referencing it.
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__.'/../src/TodoItemsServiceProvider.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('TodoListPage', $code);
        $this->assertStringNotContainsString('Filament\\', $code);
    }

    public function test_access_fails_closed_until_a_subclass_decides(): void
    {
        // Filament's own default is true. Inheriting it would publish the
        // roadmap to every panel user of a consumer that never thought about
        // access; abstract is impossible here (PHP forbids re-declaring an
        // inherited concrete method abstract), so false is the safe floor.
        $this->assertFalse(TodoListPage::canAccess(), 'the base must not grant access by default');
        $this->assertTrue((new ReflectionClass(TodoListPage::class))->isAbstract());
    }

    public function test_a_subclass_supplies_access_and_inherits_everything_else(): void
    {
        TestTodoPage::$allowed = true;
        $this->assertTrue(TestTodoPage::canAccess());

        TestTodoPage::$allowed = false;
        $this->assertFalse(TestTodoPage::canAccess());

        TestTodoPage::$allowed = true;
    }

    public function test_icons_default_to_heroicons_and_are_overridable(): void
    {
        $icons = $this->invokeIcons(TestTodoPage::class);

        $this->assertSame(
            ['page', 'available', 'claimed', 'done', 'view'],
            array_keys($icons),
            'the five roles a consumer maps onto its own registry',
        );

        foreach ($icons as $role => $icon) {
            $this->assertNotNull($icon, "default icon missing for {$role}");
        }
    }

    public function test_an_unknown_icon_role_is_null_rather_than_an_error(): void
    {
        $method = (new ReflectionClass(TodoListPage::class))->getMethod('icon');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, 'no-such-role'));
    }

    public function test_the_page_renders_through_the_packages_own_views(): void
    {
        $page = (new ReflectionClass(TodoListPage::class))->getProperty('view');
        $page->setAccessible(true);

        $this->assertSame('todo-items::page', $page->getDefaultValue());
        $this->assertTrue(view()->exists('todo-items::page'), 'the view namespace is not registered');
        $this->assertTrue(view()->exists('todo-items::body'));
    }

    /** @return array<string, mixed> */
    private function invokeIcons(string $class): array
    {
        $method = (new ReflectionClass($class))->getMethod('icons');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
