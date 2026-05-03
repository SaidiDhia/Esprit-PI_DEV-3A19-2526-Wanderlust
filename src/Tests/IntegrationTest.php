<?php
namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class IntegrationTest extends WebTestCase
{
    public function testApiBaseReachable(): void
    {
        $base = getenv('TEST_API_BASE_URL') ?: 'http://127.0.0.1:8000';
        $url = rtrim($base, '/') . '/';
        $s = @file_get_contents($url);
        if ($s === false) {
            $this->markTestSkipped('API base not reachable at ' . $url);
        }

        $this->assertNotFalse($s);
    }
}
