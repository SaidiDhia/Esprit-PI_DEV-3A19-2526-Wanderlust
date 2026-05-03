<?php
namespace App\Tests;

use App\Controller\BookingController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Annotation\Route as AnnotationRoute;
use Symfony\Component\Routing\Attribute\Route as AttributeRoute;

class BookingTest extends TestCase
{
    public function testBookingControllerDeclaresRoutes(): void
    {
        $this->assertClassRoutePath(BookingController::class, '/booking');
        $this->assertRoutePath(BookingController::class, 'index', ['']);
        $this->assertRoutePath(BookingController::class, 'place', ['/place/{id}']);
        $this->assertRoutePath(BookingController::class, 'book', ['/place/{id}/book']);
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

    public function testBookingPlaceholder(): void
    {
        $this->assertTrue(true);
    }
}
