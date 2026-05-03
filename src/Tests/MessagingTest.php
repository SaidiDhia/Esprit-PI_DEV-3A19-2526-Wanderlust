<?php
namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MessagingTest extends WebTestCase
{
    public function testMessagingApiReachable(): void
    {
        $base = getenv('MESSAGING_API_URL') ?: getenv('TEST_API_BASE_URL') ?: 'http://127.0.0.1:8000';
        $url = rtrim($base, '/') . '/api/messaging/health';
        $health = @file_get_contents($url);
        if ($health === false) {
            $this->markTestSkipped('Messaging API not reachable at ' . $url);
        }

        $this->assertNotFalse($health);
    }

    public function testMessagingUnitExample(): void
    {
        $this->assertTrue(true);
    }
}
