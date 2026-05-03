<?php
namespace App\Tests;

use App\Entity\User;
use App\Service\ActivityLogger;
use App\Service\AnomalyDetectionService;
use App\Service\BotBehaviorService;
use App\Service\MessageToxicityService;
use App\Service\RiskScoringEngine;
use App\Service\RuleEngineApiService;
use Doctrine\DBAL\DriverManager;
use PDO;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testRiskScoringEngineComputesBands(): void
    {
        $engine = new RiskScoringEngine();
        $score = $engine->compute([
            'anomaly_score' => 80,
            'click_speed_score' => 10,
            'login_failure_score' => 0,
            'message_toxicity_score' => 20,
            'cancellation_abuse_score' => 0,
            'marketplace_fraud_score' => 0,
        ]);

        $classification = $engine->classify($score);

        $this->assertGreaterThan(0.0, $score);
        $this->assertArrayHasKey('band', $classification);
        $this->assertArrayHasKey('recommended_action', $classification);
    }

    public function testRealOrFallbackModelScoresWork(): void
    {
        $anomaly = new AnomalyDetectionService(getenv('AI_ANOMALY_API_URL') ?: null);
        $bot = new BotBehaviorService(getenv('BOT_API_URL') ?: null);
        $toxicity = new MessageToxicityService(getenv('AI_TOXICITY_API_URL') ?: null);

        $anomalyScore = $anomaly->score(['signal' => 1.0]);
        $botScore = $bot->score('login book search click refresh submit');
        $toxicityScore = $toxicity->score('this is a nasty stupid message');

        $this->assertTrue($anomalyScore === null || ($anomalyScore >= 0.0 && $anomalyScore <= 100.0));
        $this->assertGreaterThanOrEqual(0.0, $botScore);
        $this->assertGreaterThanOrEqual(0.0, $toxicityScore);
    }

    public function testActivityLoggerWritesAuditRow(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('SQLite PDO driver is not available');
        }

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, module VARCHAR(50), action VARCHAR(120), user_id VARCHAR(36), user_name VARCHAR(255), user_avatar VARCHAR(255), target_type VARCHAR(80), target_id VARCHAR(120), target_name VARCHAR(255), target_image VARCHAR(255), content TEXT, destination VARCHAR(255), metadata_json TEXT, created_at DATETIME)');

        $riskService = $this->createMock(\App\Service\UserRiskAssessmentService::class);
        $ruleEngine = $this->createMock(RuleEngineApiService::class);
        $ruleEngine->expects($this->once())->method('trackAction');

        $logger = new ActivityLogger($connection, $riskService, $ruleEngine);

        $user = new User();
        $user->setId('admin-1');
        $user->setFullName('Admin User');
        $user->setEmail('admin@example.com');

        $logger->logAction($user, 'admin', 'dashboard_viewed', ['content' => 'Viewed admin dashboard']);

        $row = $connection->fetchAssociative('SELECT * FROM activity_log WHERE user_id = ?', ['admin-1']);
        $this->assertIsArray($row);
        $this->assertSame('admin', $row['module']);
        $this->assertSame('dashboard_viewed', $row['action']);
    }
}
