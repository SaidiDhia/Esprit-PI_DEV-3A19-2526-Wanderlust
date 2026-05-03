<?php
namespace App\Tests;

use App\Controller\MarketplaceController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Annotation\Route as AnnotationRoute;
use Symfony\Component\Routing\Attribute\Route as AttributeRoute;

class MarketplaceTest extends TestCase
{
    public function testMarketplaceSignalDetection(): void
    {
        $controller = (new \ReflectionClass(MarketplaceController::class))->newInstanceWithoutConstructor();
        $signals = (function (string $title, string $description): array {
            return $this->detectFakeProductSignals($title, $description);
        })->bindTo($controller, MarketplaceController::class)('test123', 'lorem ipsum random text');

        $this->assertIsArray($signals);
        $this->assertNotEmpty($signals);
    }

    public function testMarketplaceControllerDeclaresRoutes(): void
    {
        $this->assertClassRoutePath(MarketplaceController::class, '/marketplace');
        $this->assertRoutePath(MarketplaceController::class, 'roleSelection', ['']);
        $this->assertRoutePath(MarketplaceController::class, 'adminHome', ['/admin']);
        $this->assertRoutePath(MarketplaceController::class, 'productManagement', ['/product_management']);
        $this->assertRoutePath(MarketplaceController::class, 'adminAddProduct', ['/admin/product/add']);
        $this->assertRoutePath(MarketplaceController::class, 'adminEditProduct', ['/admin/product/edit/{id}']);
        $this->assertRoutePath(MarketplaceController::class, 'adminDeleteProduct', ['/admin/product/delete/{id}']);
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
}
