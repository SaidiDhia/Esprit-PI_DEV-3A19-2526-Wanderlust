<?php
namespace App\Tests;

use App\Controller\EventSuggestionsController;
use App\Controller\EventsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Annotation\Route as AnnotationRoute;
use Symfony\Component\Routing\Attribute\Route as AttributeRoute;

class EventsTest extends TestCase
{
    public function testEventsControllerDeclaresRoutes(): void
    {
        $this->assertClassRoutePath(EventsController::class, '/events');
        $this->assertRoutePath(EventsController::class, 'index', ['']);
        $this->assertRoutePath(EventsController::class, 'new', ['/new']);
        $this->assertRoutePath(EventsController::class, 'show', ['/{id}']);
        $this->assertRoutePath(EventsController::class, 'detailsPdf', ['/{id}/details-pdf']);
        $this->assertRoutePath(EventsController::class, 'share', ['/{id}/share']);
        $this->assertRoutePath(EventsController::class, 'edit', ['/{id}/edit']);
        $this->assertRoutePath(EventsController::class, 'delete', ['/{id}']);
        $this->assertRoutePath(EventSuggestionsController::class, 'suggestions', ['/events/suggestions']);
    }

    private function assertClassRoutePath(string $className, string $expectedPath): void
    {
        $attributes = array_merge(
            (new \ReflectionClass($className))->getAttributes(AnnotationRoute::class),
            (new \ReflectionClass($className))->getAttributes(AttributeRoute::class)
        );

        $this->assertNotEmpty($attributes);
        $paths = array_map(static fn (\ReflectionAttribute $attribute): string => (string) ($attribute->newInstance()->getPath() ?? ''), $attributes);
        $this->assertContains($expectedPath, $paths);
    }

    private function assertRoutePath(string $className, string $methodName, array $expectedPaths): void
    {
        $ref = new \ReflectionMethod($className, $methodName);
        $attributes = array_merge(
            $ref->getAttributes(AnnotationRoute::class),
            $ref->getAttributes(AttributeRoute::class)
        );
        $this->assertNotEmpty($attributes);

        $paths = array_map(static fn (\ReflectionAttribute $attribute): string => (string) ($attribute->newInstance()->getPath() ?? ''), $attributes);
        foreach ($expectedPaths as $path) {
            $this->assertContains($path, $paths);
        }
    }

    public function testEventsPlaceholder(): void
    {
        $this->assertTrue(true);
    }
}
