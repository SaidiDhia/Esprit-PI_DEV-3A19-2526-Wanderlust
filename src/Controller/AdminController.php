<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Activities;
use App\Entity\Events;
use App\Entity\Posts;
use App\Entity\Place;
use App\Entity\PlaceImage;
use App\Entity\Reservations;
use App\Entity\User;
use App\Enum\RoleEnum;
use App\Enum\StatusActiviteEnum;
use App\Enum\StatusEventEnum;
use App\Enum\TFAMethod;
use App\Repository\UserRepository;
use App\Service\PDFExportService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class AdminController extends AbstractController
{
    #[Route('/admin/dashboard/alerts/ack', name: 'app_admin_dashboard_ack_alerts', methods: ['POST'])]
    public function acknowledgeRiskAlerts(Request $request, Connection $connection): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('admin_recent_risk_alert_ack', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid alert acknowledgement token.');
        }

        $alertIds = array_values(array_unique(array_filter(array_map('trim', (array) $request->request->all('alert_ids')), static fn (string $value): bool => $value !== '')));
        if ($alertIds === []) {
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'risk']);
        }

        $this->ensureRecentRiskAlertAckTableExists($connection);

        try {
            $connection->beginTransaction();
            foreach ($alertIds as $alertId) {
                $connection->executeStatement(
                    'INSERT INTO admin_recent_risk_alert_ack (admin_user_id, activity_log_id, acknowledged_at) VALUES (:admin_user_id, :activity_log_id, :acknowledged_at) ON DUPLICATE KEY UPDATE acknowledged_at = VALUES(acknowledged_at)',
                    [
                        'admin_user_id' => (string) $user->getId(),
                        'activity_log_id' => (int) $alertId,
                        'acknowledged_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]
                );
            }
            $connection->commit();
            $this->addFlash('success', 'High-risk alerts marked as read and hidden from the dashboard.');
        } catch (\Throwable) {
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                // Ignore rollback failures.
            }
            $this->addFlash('error', 'Unable to mark alerts as read right now.');
        }

        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'risk']);
    }

    #[Route('/admin/dashboard', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $requestedSection = (string) $request->query->get('section', 'users');
        $experienceFocus = 'all';

        if (in_array($requestedSection, ['activities', 'events', 'reservations'], true)) {
            $section = 'experiences';
            $experienceFocus = $requestedSection;
        } else {
            $section = $requestedSection;
        }

        if (!in_array($section, ['users', 'messaging', 'booking', 'experiences', 'marketplace', 'blog', 'risk', 'activity_logs', 'activity_feed'], true)) {
            $section = 'users';
        }

        if ($section === 'experiences' && $experienceFocus === 'all') {
            $experienceFocus = strtolower(trim((string) $request->query->get('experience', 'all')));
            if (!in_array($experienceFocus, ['all', 'activities', 'events', 'reservations'], true)) {
                $experienceFocus = 'all';
            }
        }

        $q = trim((string) $request->query->get('q', ''));
        $role = strtoupper(trim((string) $request->query->get('role', '')));
        $status = strtolower(trim((string) $request->query->get('status', '')));

        $qb = $userRepository->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC');

        if ($q !== '') {
            $qb->andWhere('LOWER(u.fullName) LIKE :search OR LOWER(u.email) LIKE :search OR LOWER(COALESCE(u.phoneNumber, \'\')) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($q) . '%');
        }

        if (in_array($role, array_map(static fn (RoleEnum $item) => $item->value, RoleEnum::cases()), true)) {
            $qb->andWhere('u.role = :role')->setParameter('role', RoleEnum::from($role));
        }

        if ($status === 'active') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', true);
        }

        if ($status === 'banned') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', false);
        }

        $users = $qb->getQuery()->getResult();

        $startOfMonth = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);

        $newThisMonth = (int) $userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :startMonth')
            ->setParameter('startMonth', $startOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        $stats = [
            'total' => $userRepository->count([]),
            'active' => $userRepository->count(['isActive' => true]),
            'banned' => $userRepository->count(['isActive' => false]),
            'admins' => $userRepository->count(['role' => RoleEnum::ADMIN]),
            'hosts' => $userRepository->count(['role' => RoleEnum::HOST]),
            'participants' => $userRepository->count(['role' => RoleEnum::PARTICIPANT]),
            'newThisMonth' => $newThisMonth,
        ];

        $conn = $entityManager->getConnection();

        $totalConversations = (int) $conn->executeQuery('SELECT COUNT(*) FROM conversation')->fetchOne();
        $totalMessages = (int) $conn->executeQuery('SELECT COUNT(*) FROM message')->fetchOne();
        $totalMedia = (int) $conn->executeQuery("SELECT COUNT(*) FROM message WHERE message_type != 'TEXT'")->fetchOne();

        $messagesPerDay = $conn->executeQuery("\n            SELECT DATE(created_at) as date, COUNT(*) as count\n            FROM message\n            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)\n            GROUP BY DATE(created_at)\n            ORDER BY date ASC\n        ")->fetchAllAssociative();

        $topConversations = $conn->executeQuery("\n            SELECT c.id, c.name, COUNT(m.id) as message_count\n            FROM conversation c\n            JOIN message m ON c.id = m.conversation_id\n            GROUP BY c.id, c.name\n            ORDER BY message_count DESC\n            LIMIT 5\n        ")->fetchAllAssociative();

        $topSenders = $conn->executeQuery("\n            SELECT u.full_name, u.email, COUNT(m.id) as message_count\n            FROM users u\n            JOIN message m ON u.id = m.sender_id\n            GROUP BY u.id, u.full_name, u.email\n            ORDER BY message_count DESC\n            LIMIT 5\n        ")->fetchAllAssociative();

        $adminMessagingConversations = $conn->executeQuery(
            "\n                SELECT
                    c.id,
                    c.name,
                    c.type,
                    c.last_activity,
                    u.full_name AS creator_name,
                    COUNT(DISTINCT cu.user_id) AS participant_count,
                    COUNT(DISTINCT m.id) AS message_count
                FROM conversation c
                LEFT JOIN conversation_user cu ON cu.conversation_id = c.id AND cu.is_active = 1
                LEFT JOIN conversation_user creator ON creator.conversation_id = c.id AND creator.role = 'CREATOR'
                LEFT JOIN users u ON u.id = creator.user_id
                LEFT JOIN message m ON m.conversation_id = c.id
                GROUP BY c.id, c.name, c.type, c.last_activity, u.full_name
                ORDER BY c.last_activity DESC, c.id DESC
            "
        )->fetchAllAssociative();

        $recentActivity = $conn->executeQuery("\n            SELECT m.*, u.full_name as sender_name, c.name as conversation_name\n            FROM message m\n            JOIN users u ON m.sender_id = u.id\n            JOIN conversation c ON m.conversation_id = c.id\n            ORDER BY m.created_at DESC\n            LIMIT 10\n        ")->fetchAllAssociative();

        $systemActivityFeed = [];
        $highRiskRecentAlerts = [];
        try {
            $systemActivityFeed = $conn->executeQuery("\n                SELECT *\n                FROM activity_log\n                ORDER BY created_at DESC\n                LIMIT 40\n            ")->fetchAllAssociative();

                        $highRiskRecentAlerts = $conn->executeQuery(
                                "SELECT id, user_id, user_name, module, action, content, created_at,
                                                CASE
                                                    WHEN module = 'moderation' AND action = 'high_risk_content_hidden' THEN 100
                                                    WHEN module = 'moderation' AND action = 'toxic_message_detected' THEN 96
                                                    WHEN module = 'moderation' AND action = 'marketplace_fake_product_detected' THEN 95
                                                        WHEN LOWER(COALESCE(content, '')) REGEXP 'hop(?:e|ing)?\\s+(?:you|u)\\s+(?:die|dead|death|get cancer)|wish\\s+(?:you|u)\\s+(?:die|dead|death|get cancer)' THEN 98
                                                        WHEN LOWER(COALESCE(content, '')) REGEXP 'kys|kill|suicide|death|die|get cancer|cancer' THEN 92
                                                        WHEN LOWER(COALESCE(content, '')) REGEXP 'threat|violent|abuse|harass|attack' THEN 84
                                                        ELSE 70
                                                END AS severity_score
                 FROM activity_log
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                   AND (
                        (module = 'moderation' AND action = 'high_risk_content_hidden')
                                                OR (module = 'moderation' AND action = 'toxic_message_detected')
                                           OR (module = 'moderation' AND action = 'marketplace_fake_product_detected')
                                                OR LOWER(COALESCE(content, '')) REGEXP 'die|death|kys|kill|suicide|cancer|threat|violent|abuse|harass|attack'
                   )
                                 ORDER BY severity_score DESC, created_at DESC
                 LIMIT 12"
            )->fetchAllAssociative();
        } catch (\Throwable $e) {
            // The activity_log table may not exist before migration is applied.
        }

        $recentRiskAlertAckIds = [];
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() !== null) {
            try {
                $this->ensureRecentRiskAlertAckTableExists($conn);
                $recentRiskAlertAckIds = array_map(
                    static fn (array $row): string => (string) ($row['activity_log_id'] ?? ''),
                    $conn->executeQuery(
                        'SELECT activity_log_id FROM admin_recent_risk_alert_ack WHERE admin_user_id = :admin_user_id',
                        ['admin_user_id' => (string) $currentUser->getId()]
                    )->fetchAllAssociative()
                );
            } catch (\Throwable) {
                $recentRiskAlertAckIds = [];
            }
        }

        $activityFocusUserId = trim((string) $request->query->get('activity_user', ''));
        $activityModuleFilter = trim((string) $request->query->get('activity_module', ''));
        $activityActionFilter = trim((string) $request->query->get('activity_action', ''));
        $activityFocusUser = null;
        $activityLogUsers = [];
        $activityUserInsights = [
            'summary' => [
                'total_actions' => 0,
                'actions_last_24h' => 0,
                'distinct_modules' => 0,
                'distinct_actions' => 0,
                'first_activity_at' => null,
                'last_activity_at' => null,
            ],
            'module_breakdown' => [],
            'action_breakdown' => [],
            'recent_entries' => [],
            'content_analysis' => [
                'entries_with_content' => 0,
                'toxic_like_entries' => 0,
                'threat_like_entries' => 0,
            ],
            'risk' => null,
        ];

        try {
            $activityLogUsers = $conn->executeQuery("\n                SELECT al.user_id,
                       COALESCE(MAX(NULLIF(al.user_name, '')), MAX(u.full_name), 'Unknown user') AS full_name,
                       COALESCE(MAX(NULLIF(u.email, '')), '') AS email,
                       COALESCE(MAX(NULLIF(u.profile_picture, '')), MAX(NULLIF(al.user_avatar, ''))) AS profile_picture,
                       COUNT(*) AS action_count,
                       COUNT(DISTINCT al.module) AS module_count,
                       MAX(al.created_at) AS last_activity_at
                FROM activity_log al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.user_id IS NOT NULL
                  AND al.user_id != ''
                GROUP BY al.user_id
                ORDER BY last_activity_at DESC
                LIMIT 80
            ")->fetchAllAssociative();
        } catch (\Throwable) {
            $activityLogUsers = [];
        }

        if ($activityFocusUserId !== '') {
            try {
                $activityFocusUser = $conn->executeQuery(
                    "SELECT id, full_name, email, profile_picture, role, is_active, created_at
                     FROM users
                     WHERE id = :user_id
                     LIMIT 1",
                    ['user_id' => $activityFocusUserId]
                )->fetchAssociative();
            } catch (\Throwable) {
                $activityFocusUser = null;
            }

            $activityUserInsights = $this->buildUserActivityInsights(
                $conn,
                $activityFocusUserId,
                $activityModuleFilter,
                $activityActionFilter
            );
        }

        $riskTopUsers = [];
        $riskFlaggedUsers = [];
        $marketplaceFraudRows = [];
        $riskSummary = [
            'total' => 0,
            'normal' => 0,
            'suspicious' => 0,
            'abusive' => 0,
            'critical' => 0,
        ];
        try {
            $riskTopUsers = $conn->executeQuery("\n                SELECT r.user_id, r.risk_score, r.anomaly_score, r.click_speed_score,\n                       r.login_failure_score, r.message_toxicity_score, r.bot_behavior_score, r.cancellation_abuse_score,\n                       r.marketplace_fraud_score, r.risk_band, r.recommended_action, r.updated_at,\n                       u.full_name, u.email, u.profile_picture\n                FROM risk_assessment r\n                LEFT JOIN users u ON u.id = r.user_id\n                ORDER BY r.risk_score DESC, r.updated_at DESC\n                LIMIT 40\n            ")->fetchAllAssociative();

            $riskSummaryRow = $conn->executeQuery("\n                SELECT\n                    COUNT(*) AS total,\n                    SUM(CASE WHEN risk_score < 30 THEN 1 ELSE 0 END) AS normal_count,\n                    SUM(CASE WHEN risk_score >= 30 AND risk_score < 60 THEN 1 ELSE 0 END) AS suspicious_count,\n                    SUM(CASE WHEN risk_score >= 60 AND risk_score < 80 THEN 1 ELSE 0 END) AS abusive_count,\n                    SUM(CASE WHEN risk_score >= 80 THEN 1 ELSE 0 END) AS critical_count\n                FROM risk_assessment\n            ")->fetchAssociative();

            if (is_array($riskSummaryRow)) {
                $riskSummary = [
                    'total' => (int) ($riskSummaryRow['total'] ?? 0),
                    'normal' => (int) ($riskSummaryRow['normal_count'] ?? 0),
                    'suspicious' => (int) ($riskSummaryRow['suspicious_count'] ?? 0),
                    'abusive' => (int) ($riskSummaryRow['abusive_count'] ?? 0),
                    'critical' => (int) ($riskSummaryRow['critical_count'] ?? 0),
                ];
            }

            foreach ($riskTopUsers as $row) {
                $userId = (string) ($row['user_id'] ?? '');
                $marketplaceBreakdown = $this->buildMarketplaceFraudBreakdown($conn, $userId);
                $row['marketplace_breakdown'] = $marketplaceBreakdown;

                if (
                    $marketplaceBreakdown['has_activity']
                    || (float) ($row['marketplace_fraud_score'] ?? 0.0) > 0.0
                ) {
                    $marketplaceFraudRows[] = $row;
                }

                $riskScore = (float) ($row['risk_score'] ?? 0.0);
                if ($riskScore < 30.0) {
                    continue;
                }

                $reason = $this->buildFlagReason($row);
                $source = $this->resolveFlagSourceActivity($conn, $userId, $reason['signal_key']);

                $row['flag_reason'] = $reason['label'];
                $row['flag_signal'] = $reason['signal_key'];
                $row['flag_score'] = $reason['score'];
                $row['flag_activity_module'] = $source['module'];
                $row['flag_activity_action'] = $source['action'];
                $row['flag_activity_content'] = $source['content'];
                $row['flag_activity_time'] = $source['created_at'];

                $riskFlaggedUsers[] = $row;
            }

            $highRiskRecentAlerts = array_values(array_filter(
                $highRiskRecentAlerts,
                static fn (array $alert) => !in_array((string) ($alert['id'] ?? ''), $recentRiskAlertAckIds, true)
            ));
        } catch (\Throwable $e) {
            // risk_assessment table may not exist in early rollout.
        }

        $aiServiceStatus = $this->collectAiServiceStatus();

        $conversationTypes = $conn->executeQuery('SELECT type, COUNT(*) as count FROM conversation GROUP BY type ORDER BY count DESC')->fetchAllAssociative();
        $messageTypes = $conn->executeQuery('SELECT message_type, COUNT(*) as count FROM message GROUP BY message_type ORDER BY count DESC')->fetchAllAssociative();

        $messagesPerDayChart = array_map(static fn (array $row): array => [
            'label' => (new \DateTimeImmutable((string) $row['date']))->format('M d'),
            'count' => (int) $row['count'],
        ], $messagesPerDay);

        $conversationTypeChartData = array_map(static fn (array $row): array => [
            'label' => (string) $row['type'],
            'count' => (int) $row['count'],
        ], $conversationTypes);

        $messageTypeChartData = array_map(static fn (array $row): array => [
            'label' => (string) $row['message_type'],
            'count' => (int) $row['count'],
        ], $messageTypes);

        $bookingStats = [
            'totalPlaces' => (int) $entityManager->createQueryBuilder()->select('COUNT(p.id)')->from(Place::class, 'p')->getQuery()->getSingleScalarResult(),
            'approvedPlaces' => (int) $entityManager->createQueryBuilder()->select('COUNT(p.id)')->from(Place::class, 'p')->where('p.status = :status')->setParameter('status', Place::STATUS_APPROVED)->getQuery()->getSingleScalarResult(),
            'pendingPlaces' => (int) $entityManager->createQueryBuilder()->select('COUNT(p.id)')->from(Place::class, 'p')->where('p.status = :status')->setParameter('status', Place::STATUS_PENDING)->getQuery()->getSingleScalarResult(),
            'totalBookings' => (int) $entityManager->createQueryBuilder()->select('COUNT(b.id)')->from(Booking::class, 'b')->getQuery()->getSingleScalarResult(),
            'pendingBookings' => (int) $entityManager->createQueryBuilder()->select('COUNT(b.id)')->from(Booking::class, 'b')->where('b.status = :status')->setParameter('status', Booking::STATUS_PENDING)->getQuery()->getSingleScalarResult(),
            'confirmedBookings' => (int) $entityManager->createQueryBuilder()->select('COUNT(b.id)')->from(Booking::class, 'b')->where('b.status = :status')->setParameter('status', Booking::STATUS_CONFIRMED)->getQuery()->getSingleScalarResult(),
        ];

        $pendingPlaces = $entityManager->createQueryBuilder()
            ->select('p', 'h')
            ->from(Place::class, 'p')
            ->leftJoin('p.host', 'h')
            ->where('p.status = :status')
            ->setParameter('status', Place::STATUS_PENDING)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        $recentBookings = $entityManager->createQueryBuilder()
            ->select('b', 'p', 'g')
            ->from(Booking::class, 'b')
            ->leftJoin('b.place', 'p')
            ->leftJoin('b.guest', 'g')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        $previewPlaces = [];
        foreach ($pendingPlaces as $pendingPlace) {
            if ($pendingPlace instanceof Place) {
                $previewPlaces[] = $pendingPlace;
            }
        }
        foreach ($recentBookings as $recentBooking) {
            if ($recentBooking instanceof Booking && $recentBooking->getPlace() instanceof Place) {
                $previewPlaces[] = $recentBooking->getPlace();
            }
        }

        $bookingPlacePreviewImages = $this->buildPlacePreviewImagesMap($entityManager, $previewPlaces);

        $bookingPlaces = $entityManager->createQueryBuilder()
            ->select('p', 'h')
            ->from(Place::class, 'p')
            ->leftJoin('p.host', 'h')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        $bookingHosts = $userRepository->createQueryBuilder('u')
            ->where('u.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        $bookingPlaceGalleryImages = $this->buildPlaceGalleryImagesMap($entityManager, $bookingPlaces);
        $bookingPlaceGalleryImageRecords = $this->buildPlaceGalleryImageRecordsMap($entityManager, $bookingPlaces);

        $experienceStats = [
            'totalActivities' => 0,
            'pendingActivities' => 0,
            'acceptedActivities' => 0,
            'refusedActivities' => 0,
            'totalEvents' => 0,
            'pendingEvents' => 0,
            'activeEvents' => 0,
            'endedEvents' => 0,
            'totalReservations' => 0,
            'pendingReservations' => 0,
            'confirmedReservations' => 0,
            'cancelledReservations' => 0,
        ];
        $experienceActivities = [];
        $experienceEvents = [];
        $experienceReservations = [];
        $experienceReservationTrend = [];
        $experienceStatusMixChartData = [];

        try {
            $experienceStats['totalActivities'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM activites')->fetchOne();
            $experienceStats['pendingActivities'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM activites WHERE status = 'en_attente'")->fetchOne();
            $experienceStats['acceptedActivities'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM activites WHERE status = 'accepte'")->fetchOne();
            $experienceStats['refusedActivities'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM activites WHERE status = 'refuse'")->fetchOne();
        } catch (\Throwable) {
            // Activities module tables may be unavailable in early rollout.
        }

        try {
            $experienceStats['totalEvents'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM events')->fetchOne();
            $experienceStats['pendingEvents'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM events WHERE status = 'en_attente'")->fetchOne();
            $experienceStats['activeEvents'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM events WHERE date_fin >= NOW()')->fetchOne();
            $experienceStats['endedEvents'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM events WHERE date_fin < NOW()')->fetchOne();
        } catch (\Throwable) {
            // Events module tables may be unavailable in early rollout.
        }

        try {
            $experienceStats['totalReservations'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM reservations')->fetchOne();
            $experienceStats['pendingReservations'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM reservations WHERE statut = 'en_attente'")->fetchOne();
            $experienceStats['confirmedReservations'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM reservations WHERE statut = 'confirmee'")->fetchOne();
            $experienceStats['cancelledReservations'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM reservations WHERE statut = 'annulee'")->fetchOne();
        } catch (\Throwable) {
            // Reservations module tables may be unavailable in early rollout.
        }

        try {
            $experienceActivities = $conn->executeQuery(
                "SELECT a.id, a.titre, a.categorie, a.type_activite, a.status, a.date_creation,
                        COALESCE(u.full_name, 'Unknown creator') AS creator_name
                 FROM activites a
                 LEFT JOIN users u ON u.id = a.created_by_id
                 ORDER BY a.date_creation DESC
                 LIMIT 12"
            )->fetchAllAssociative();
        } catch (\Throwable) {
            $experienceActivities = [];
        }

        try {
            $experienceEvents = $conn->executeQuery(
                "SELECT e.id, e.lieu, e.status AS statut, e.date_debut, e.date_fin, e.date_creation,
                        e.places_disponibles, e.capacite_max,
                        COALESCE(u.full_name, e.organisateur, 'Unknown organizer') AS organizer_name
                 FROM events e
                 LEFT JOIN users u ON u.id = e.created_by_id
                 ORDER BY e.date_creation DESC
                 LIMIT 12"
            )->fetchAllAssociative();
        } catch (\Throwable) {
            $experienceEvents = [];
        }

        try {
            $experienceReservations = $conn->executeQuery(
                "SELECT r.id, r.statut, r.nombre_personnes, r.prix_total, r.date_creation,
                        COALESCE(r.nom_complet, u.full_name, 'Unknown user') AS reserver_name,
                        COALESCE(e.lieu, 'Unknown event') AS event_lieu
                 FROM reservations r
                 LEFT JOIN users u ON u.id = r.user_id
                 LEFT JOIN events e ON e.id = r.id_event
                 ORDER BY r.date_creation DESC
                 LIMIT 14"
            )->fetchAllAssociative();
        } catch (\Throwable) {
            $experienceReservations = [];
        }

        try {
            $reservationTrendRows = $conn->executeQuery(
                "SELECT DATE(date_creation) AS day_label, COUNT(*) AS total
                 FROM reservations
                 WHERE date_creation >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY DATE(date_creation)
                 ORDER BY day_label ASC"
            )->fetchAllAssociative();

            $experienceReservationTrend = array_map(static fn (array $row): array => [
                'label' => (new \DateTimeImmutable((string) $row['day_label']))->format('M d'),
                'count' => (int) ($row['total'] ?? 0),
            ], $reservationTrendRows);
        } catch (\Throwable) {
            $experienceReservationTrend = [];
        }

        $experienceStatusMixChartData = [
            ['label' => 'Activities Pending', 'count' => (int) $experienceStats['pendingActivities']],
            ['label' => 'Activities Accepted', 'count' => (int) $experienceStats['acceptedActivities']],
            ['label' => 'Events Pending', 'count' => (int) $experienceStats['pendingEvents']],
            ['label' => 'Reservations Pending', 'count' => (int) $experienceStats['pendingReservations']],
            ['label' => 'Reservations Confirmed', 'count' => (int) $experienceStats['confirmedReservations']],
        ];

        $marketplaceStats = [
            'totalProducts' => 0,
            'activeListings' => 0,
            'sellerCount' => 0,
            'totalOrders' => 0,
            'pendingOrders' => 0,
            'confirmedOrders' => 0,
            'grossRevenue' => 0.0,
        ];
        $marketplaceRecentOrders = [];
        $marketplaceTopProducts = [];
        $marketplaceOrdersPerDay = [];
        $marketplaceProducts = [];
        $marketplaceSellerOptions = [];

        // Marketplace stats - gracefully handle if tables don't exist
        try {
            $marketplaceStats['totalProducts'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM products')->fetchOne();
            $marketplaceStats['activeListings'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM products WHERE (quantity - COALESCE(reserved_quantity, 0)) > 0')->fetchOne();
            $marketplaceStats['sellerCount'] = (int) $conn->executeQuery('SELECT COUNT(DISTINCT userId) FROM products')->fetchOne();
        } catch (\Throwable $e) {
            // Products table might not exist yet
        }

        // Facture stats - gracefully handle if tables don't exist
        try {
            $marketplaceStats['totalOrders'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM facture')->fetchOne();
            $marketplaceStats['pendingOrders'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM facture WHERE delivery_status = 'pending'")->fetchOne();
            $marketplaceStats['confirmedOrders'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM facture WHERE delivery_status = 'confirmed'")->fetchOne();
            $marketplaceStats['grossRevenue'] = (float) $conn->executeQuery("SELECT COALESCE(SUM(total_price), 0) FROM facture WHERE delivery_status = 'confirmed'")->fetchOne();
        } catch (\Throwable $e) {
            // Facture table might not exist yet
        }

        // Marketplace recent orders - gracefully handle if tables don't exist
        try {
            $marketplaceRecentOrders = $conn->executeQuery("\n                SELECT f.id_facture, f.date_facture, f.total_price, f.delivery_status,\n                       COALESCE(da.full_name, '') AS customer_name, COALESCE(da.city, '') AS customer_city\n                FROM facture f\n                LEFT JOIN delivery_address da ON da.facture_id = f.id_facture\n                ORDER BY f.date_facture DESC\n                LIMIT 8\n            ")->fetchAllAssociative();
        } catch (\Throwable $e) {
            // Facture tables might not exist yet
        }

        // Marketplace top products - gracefully handle if tables don't exist
        try {
            $marketplaceTopProducts = $conn->executeQuery("\n                SELECT p.id, p.title, p.category,\n                       COALESCE(SUM(fp.quantity), 0) AS sold_units,\n                       COALESCE(SUM(fp.quantity * fp.price), 0) AS revenue\n                FROM products p\n                LEFT JOIN facture_product fp ON fp.product_id = p.id\n                LEFT JOIN facture f ON f.id_facture = fp.facture_id AND f.delivery_status = 'confirmed'\n                GROUP BY p.id, p.title, p.category\n                ORDER BY sold_units DESC, revenue DESC\n                LIMIT 6\n            ")->fetchAllAssociative();
        } catch (\Throwable $e) {
            // Products or facture tables might not exist yet
        }

        // Marketplace orders per day - gracefully handle if tables don't exist
        try {
            $ordersPerDayRows = $conn->executeQuery("\n                SELECT DATE(date_facture) AS date, COUNT(*) AS count\n                FROM facture\n                WHERE date_facture >= DATE_SUB(NOW(), INTERVAL 7 DAY)\n                GROUP BY DATE(date_facture)\n                ORDER BY date ASC\n            ")->fetchAllAssociative();

            $marketplaceOrdersPerDay = array_map(static fn (array $row): array => [
                'label' => (new \DateTimeImmutable((string) $row['date']))->format('M d'),
                'count' => (int) $row['count'],
            ], $ordersPerDayRows);
        } catch (\Throwable $e) {
            // Facture table might not exist yet
        }

        // Marketplace products - this is critical, so we log errors if any
        try {
            $marketplaceProducts = $conn->executeQuery("\n                SELECT p.id, p.title, p.description, p.type, p.category, p.price, p.quantity,\n                       COALESCE(p.reserved_quantity, 0) AS reserved_quantity, p.image, p.userId,\n                       p.created_date, COALESCE(u.full_name, 'Unknown Seller') AS seller_name\n                FROM products p\n                LEFT JOIN users u ON u.id = p.userId\n                ORDER BY p.created_date DESC\n                LIMIT 40\n            ")->fetchAllAssociative();
        } catch (\Throwable $e) {
            // Products table might not exist yet or query failed
        }

        // Marketplace seller options
        try {
            $marketplaceSellerOptions = $conn->executeQuery("\n                SELECT u.id, u.full_name, u.email\n                FROM users u\n                ORDER BY u.full_name ASC\n            ")->fetchAllAssociative();
        } catch (\Throwable $e) {
            // Users table might not exist or unreachable
        }

        $blogStats = [
            'totalPosts' => 0,
            'publicPosts' => 0,
            'privatePosts' => 0,
            'hiddenPosts' => 0,
            'scheduledPosts' => 0,
            'publishedThisWeek' => 0,
        ];
        $blogRecentPosts = [];
        $blogPostsTrendChart = [];
        $blogStatusMixChartData = [];
        $blogEngagementChartData = [
            ['label' => 'Comments', 'count' => 0],
            ['label' => 'Reactions', 'count' => 0],
            ['label' => 'Saves', 'count' => 0],
        ];

        try {
            $blogStats['totalPosts'] = (int) $conn->executeQuery('SELECT COUNT(*) FROM posts')->fetchOne();
            $blogStats['publicPosts'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM posts WHERE statut = 'public'")->fetchOne();
            $blogStats['privatePosts'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM posts WHERE statut = 'private'")->fetchOne();
            $blogStats['hiddenPosts'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM posts WHERE statut = 'hidden'")->fetchOne();
            $blogStats['scheduledPosts'] = (int) $conn->executeQuery("SELECT COUNT(*) FROM posts WHERE statut = 'scheduled'")->fetchOne();
            $blogStats['publishedThisWeek'] = (int) $conn->executeQuery(
                "SELECT COUNT(*) FROM posts WHERE statut = 'public' AND date_creation >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )->fetchOne();
        } catch (\Throwable) {
            // Blog tables may not be available in early rollout.
        }

        try {
            $blogRecentPosts = $conn->executeQuery(
                "SELECT p.id_post, p.contenu, p.media, p.statut, p.date_creation, p.scheduled_at,
                        COALESCE(u.full_name, 'Unknown author') AS author_name,
                        (SELECT COUNT(*) FROM commentaires c WHERE c.id_post = p.id_post) AS comment_count,
                        (SELECT COUNT(*) FROM reactions r WHERE r.id_post = p.id_post) AS reaction_count,
                        (SELECT COUNT(*) FROM posts_sauvegardes s WHERE s.id_post = p.id_post) AS save_count
                 FROM posts p
                 LEFT JOIN users u ON u.id = p.id_user
                 ORDER BY p.date_creation DESC
                 LIMIT 18"
            )->fetchAllAssociative();
        } catch (\Throwable) {
            $blogRecentPosts = [];
        }

        try {
            $blogTrendRows = $conn->executeQuery(
                "SELECT DATE(date_creation) AS day_label, COUNT(*) AS total
                 FROM posts
                 WHERE date_creation >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY DATE(date_creation)
                 ORDER BY day_label ASC"
            )->fetchAllAssociative();

            $blogPostsTrendChart = array_map(static fn (array $row): array => [
                'label' => (new \DateTimeImmutable((string) $row['day_label']))->format('M d'),
                'count' => (int) ($row['total'] ?? 0),
            ], $blogTrendRows);
        } catch (\Throwable) {
            $blogPostsTrendChart = [];
        }

        try {
            $blogEngagementChartData = [
                ['label' => 'Comments', 'count' => (int) $conn->executeQuery('SELECT COUNT(*) FROM commentaires')->fetchOne()],
                ['label' => 'Reactions', 'count' => (int) $conn->executeQuery('SELECT COUNT(*) FROM reactions WHERE id_post IS NOT NULL')->fetchOne()],
                ['label' => 'Saves', 'count' => (int) $conn->executeQuery('SELECT COUNT(*) FROM posts_sauvegardes')->fetchOne()],
            ];
        } catch (\Throwable) {
            $blogEngagementChartData = [
                ['label' => 'Comments', 'count' => 0],
                ['label' => 'Reactions', 'count' => 0],
                ['label' => 'Saves', 'count' => 0],
            ];
        }

        $blogStatusMixChartData = [
            ['label' => 'Public', 'count' => (int) ($blogStats['publicPosts'] ?? 0)],
            ['label' => 'Private', 'count' => (int) ($blogStats['privatePosts'] ?? 0)],
            ['label' => 'Hidden', 'count' => (int) ($blogStats['hiddenPosts'] ?? 0)],
            ['label' => 'Scheduled', 'count' => (int) ($blogStats['scheduledPosts'] ?? 0)],
        ];

        $riskBandChartData = [
            ['label' => 'Normal', 'count' => (int) ($riskSummary['normal'] ?? 0)],
            ['label' => 'Suspicious', 'count' => (int) ($riskSummary['suspicious'] ?? 0)],
            ['label' => 'Abusive', 'count' => (int) ($riskSummary['abusive'] ?? 0)],
            ['label' => 'Critical', 'count' => (int) ($riskSummary['critical'] ?? 0)],
        ];

        $riskSignalAverages = [
            ['label' => 'Anomaly', 'score' => 0.0],
            ['label' => 'Click Speed', 'score' => 0.0],
            ['label' => 'Login Failures', 'score' => 0.0],
            ['label' => 'Toxicity', 'score' => 0.0],
            ['label' => 'Cancel Abuse', 'score' => 0.0],
            ['label' => 'Marketplace Fraud', 'score' => 0.0],
        ];

        if ($riskTopUsers !== []) {
            $riskRowsCount = (float) count($riskTopUsers);
            $riskSignalAverages = [
                ['label' => 'Anomaly', 'score' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['anomaly_score'] ?? 0), $riskTopUsers)) / $riskRowsCount, 2)],
                ['label' => 'Click Speed', 'score' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['click_speed_score'] ?? 0), $riskTopUsers)) / $riskRowsCount, 2)],
                ['label' => 'Login Failures', 'score' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['login_failure_score'] ?? 0), $riskTopUsers)) / $riskRowsCount, 2)],
                ['label' => 'Toxicity', 'score' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['message_toxicity_score'] ?? 0), $riskTopUsers)) / $riskRowsCount, 2)],
                ['label' => 'Cancel Abuse', 'score' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['cancellation_abuse_score'] ?? 0), $riskTopUsers)) / $riskRowsCount, 2)],
                ['label' => 'Marketplace Fraud', 'score' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['marketplace_fraud_score'] ?? 0), $riskTopUsers)) / $riskRowsCount, 2)],
            ];
        }

        $activityLogsTrendChartData = [];
        $activityLogsModuleChartData = [];
        $activityLogsSeverityChartData = [
            ['label' => 'Low', 'count' => 0],
            ['label' => 'Medium', 'count' => 0],
            ['label' => 'High', 'count' => 0],
        ];

        try {
            $activityTrendQuery = 'SELECT DATE(created_at) AS day_label, COUNT(*) AS total FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)';
            $activityTrendParams = [];
            if ($activityFocusUserId !== '') {
                $activityTrendQuery .= ' AND user_id = :user_id';
                $activityTrendParams['user_id'] = $activityFocusUserId;
            }
            $activityTrendQuery .= ' GROUP BY DATE(created_at) ORDER BY day_label ASC';

            $activityTrendRows = $conn->executeQuery($activityTrendQuery, $activityTrendParams)->fetchAllAssociative();
            $activityLogsTrendChartData = array_map(static fn (array $row): array => [
                'label' => (new \DateTimeImmutable((string) $row['day_label']))->format('M d'),
                'count' => (int) ($row['total'] ?? 0),
            ], $activityTrendRows);
        } catch (\Throwable) {
            $activityLogsTrendChartData = [];
        }

        if ($activityUserInsights['module_breakdown'] !== []) {
            $activityLogsModuleChartData = array_map(static fn (array $row): array => [
                'label' => (string) ($row['module'] ?? 'unknown'),
                'count' => (int) ($row['action_count'] ?? 0),
            ], array_slice((array) $activityUserInsights['module_breakdown'], 0, 8));
        } else {
            try {
                $globalModules = $conn->executeQuery(
                    "SELECT module, COUNT(*) AS action_count
                     FROM activity_log
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                     GROUP BY module
                     ORDER BY action_count DESC
                     LIMIT 8"
                )->fetchAllAssociative();

                $activityLogsModuleChartData = array_map(static fn (array $row): array => [
                    'label' => (string) ($row['module'] ?? 'unknown'),
                    'count' => (int) ($row['action_count'] ?? 0),
                ], $globalModules);
            } catch (\Throwable) {
                $activityLogsModuleChartData = [];
            }
        }

        foreach ((array) ($activityUserInsights['recent_entries'] ?? []) as $entry) {
            $severity = (string) ($entry['severity'] ?? 'low');
            if ($severity === 'high') {
                $activityLogsSeverityChartData[2]['count']++;
                continue;
            }
            if ($severity === 'medium') {
                $activityLogsSeverityChartData[1]['count']++;
                continue;
            }
            $activityLogsSeverityChartData[0]['count']++;
        }

        return $this->render('admin/dashboard.html.twig', [
            'users' => $users,
            'roles' => array_map(static fn (RoleEnum $role): array => [
                'value' => $role->value,
                'label' => $role->getLabel(),
            ], RoleEnum::cases()),
            'tfaMethods' => array_map(static fn (TFAMethod $method): array => [
                'value' => $method->value,
                'label' => $method->getLabel(),
            ], TFAMethod::cases()),
            'filters' => [
                'q' => $q,
                'role' => $role,
                'status' => $status,
            ],
            'activeSection' => $section,
            'stats' => $stats,
            'messagingStats' => [
                'totalConversations' => $totalConversations,
                'totalMessages' => $totalMessages,
                'totalMedia' => $totalMedia,
            ],
            'messagesPerDayChart' => $messagesPerDayChart,
            'topConversations' => $topConversations,
            'topSenders' => $topSenders,
            'adminMessagingConversations' => $adminMessagingConversations,
            'recentActivity' => $recentActivity,
            'systemActivityFeed' => $systemActivityFeed,
            'highRiskRecentAlerts' => $highRiskRecentAlerts,
            'conversationTypeChartData' => $conversationTypeChartData,
            'messageTypeChartData' => $messageTypeChartData,
            'bookingStats' => $bookingStats,
            'bookingPendingPlaces' => $pendingPlaces,
            'bookingRecentBookings' => $recentBookings,
            'bookingPlacePreviewImages' => $bookingPlacePreviewImages,
            'bookingPlaces' => $bookingPlaces,
            'bookingHosts' => $bookingHosts,
            'bookingPlaceGalleryImages' => $bookingPlaceGalleryImages,
            'bookingPlaceGalleryImageRecords' => $bookingPlaceGalleryImageRecords,
            'experienceStats' => $experienceStats,
            'experienceActivities' => $experienceActivities,
            'experienceEvents' => $experienceEvents,
            'experienceReservations' => $experienceReservations,
            'experienceReservationTrend' => $experienceReservationTrend,
            'experienceStatusMixChartData' => $experienceStatusMixChartData,
            'experienceFocus' => $experienceFocus,
            'marketplaceStats' => $marketplaceStats,
            'marketplaceRecentOrders' => $marketplaceRecentOrders,
            'marketplaceTopProducts' => $marketplaceTopProducts,
            'marketplaceOrdersPerDay' => $marketplaceOrdersPerDay,
            'marketplaceProducts' => $marketplaceProducts,
            'marketplaceSellerOptions' => $marketplaceSellerOptions,
            'blogStats' => $blogStats,
            'blogRecentPosts' => $blogRecentPosts,
            'blogPostsTrendChart' => $blogPostsTrendChart,
            'blogStatusMixChartData' => $blogStatusMixChartData,
            'blogEngagementChartData' => $blogEngagementChartData,
            'riskTopUsers' => $riskTopUsers,
            'riskFlaggedUsers' => $riskFlaggedUsers,
            'marketplaceFraudRows' => $marketplaceFraudRows,
            'riskSummary' => $riskSummary,
            'aiServiceStatus' => $aiServiceStatus,
            'activityLogUsers' => $activityLogUsers,
            'activityFocusUser' => $activityFocusUser,
            'activityFocusUserId' => $activityFocusUserId,
            'activityModuleFilter' => $activityModuleFilter,
            'activityActionFilter' => $activityActionFilter,
            'activityUserInsights' => $activityUserInsights,
            'riskBandChartData' => $riskBandChartData,
            'riskSignalAverages' => $riskSignalAverages,
            'activityLogsTrendChartData' => $activityLogsTrendChartData,
            'activityLogsModuleChartData' => $activityLogsModuleChartData,
            'activityLogsSeverityChartData' => $activityLogsSeverityChartData,
        ]);
    }

    #[Route('/admin/activity-logs/export/user/{userId}', name: 'app_admin_activity_export_user', methods: ['GET'])]
    public function exportUserActivityLogPDF(string $userId, Connection $conn, PDFExportService $pdfExportService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $userId = trim($userId);
        if ($userId === '') {
            throw $this->createNotFoundException('User ID is required');
        }

        try {
            $user = $conn->executeQuery(
                "SELECT id, full_name, email FROM users WHERE id = :user_id LIMIT 1",
                ['user_id' => $userId]
            )->fetchAssociative();

            if (!is_array($user)) {
                throw $this->createNotFoundException('User not found');
            }

            $activities = $conn->executeQuery(
                "SELECT * FROM activity_log WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 500",
                ['user_id' => $userId]
            )->fetchAllAssociative();

            $userName = (string) ($user['full_name'] ?? $user['id']);
            $userEmail = (string) ($user['email'] ?? 'unknown@example.com');

            $pdfContent = $pdfExportService->generateUserActivityLogPDF($userName, $userEmail, $activities);

            $filename = sprintf(
                'activity_log_%s_%s.pdf',
                str_replace([' ', '@'], '_', strtolower($userName)),
                (new \DateTime())->format('Y-m-d_H-i-s')
            );

            return new Response($pdfContent, Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ]);
        } catch (\Throwable $e) {
            throw $this->createNotFoundException(sprintf('Error generating PDF: %s', $e->getMessage()));
        }
    }

    #[Route('/admin/activity-feed/export', name: 'app_admin_activity_feed_export', methods: ['GET'])]
    public function exportPlatformActivityFeedPDF(Connection $conn, PDFExportService $pdfExportService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        try {
            $activities = $conn->executeQuery(
                "SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 500"
            )->fetchAllAssociative();

            $pdfContent = $pdfExportService->generatePlatformFeedPDF($activities);

            $filename = sprintf(
                'platform_activity_feed_%s.pdf',
                (new \DateTime())->format('Y-m-d_H-i-s')
            );

            return new Response($pdfContent, Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ]);
        } catch (\Throwable $e) {
            throw $this->createNotFoundException(sprintf('Error generating PDF: %s', $e->getMessage()));
        }
    }

    #[Route('/admin/dashboard/messaging/conversations/{id}/delete', name: 'app_admin_messaging_conversation_delete', methods: ['POST'])]
    public function deleteMessagingConversationFromDashboard(Request $request, int $id, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_messaging_conversation_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid delete token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'messaging']);
        }

        $conn = $entityManager->getConnection();
        $exists = $conn->executeQuery(
            'SELECT id FROM conversation WHERE id = :id',
            ['id' => $id]
        )->fetchOne();

        if ($exists === false) {
            $this->addFlash('error', 'Conversation not found.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'messaging']);
        }

        $conn->executeStatement('DELETE FROM message WHERE conversation_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM conversation_user WHERE conversation_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM conversation WHERE id = :id', ['id' => $id]);

        $this->addFlash('success', 'Conversation deleted.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'messaging']);
    }

    #[Route('/admin/dashboard/activities/{id}/status', name: 'app_admin_activity_status', methods: ['POST'])]
    public function updateActivityStatus(Request $request, Activities $activity, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_activity_status_' . $activity->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid activity status token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $status = StatusActiviteEnum::tryFrom(mb_strtolower(trim((string) $request->request->get('status', ''))));
        if (!$status instanceof StatusActiviteEnum) {
            $this->addFlash('error', 'Invalid activity status value.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $activity->setStatus($status);
        $entityManager->flush();

        $this->addFlash('success', 'Activity status updated successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
    }

    #[Route('/admin/dashboard/activities/{id}/delete', name: 'app_admin_activity_delete', methods: ['POST'])]
    public function deleteActivityFromAdmin(Request $request, Activities $activity, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_activity_delete_' . $activity->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid activity delete token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $entityManager->remove($activity);
        $entityManager->flush();

        $this->addFlash('success', 'Activity deleted from admin dashboard.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
    }

    #[Route('/admin/dashboard/events/{id}/status', name: 'app_admin_event_status', methods: ['POST'])]
    public function updateEventStatus(Request $request, Events $event, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_event_status_' . $event->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid event status token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $status = StatusEventEnum::tryFrom(mb_strtolower(trim((string) $request->request->get('status', ''))));
        if (!$status instanceof StatusEventEnum) {
            $this->addFlash('error', 'Invalid event status value.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $event->setStatus($status);
        $entityManager->flush();

        $this->addFlash('success', 'Event status updated successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
    }

    #[Route('/admin/dashboard/events/{id}/delete', name: 'app_admin_event_delete', methods: ['POST'])]
    public function deleteEventFromAdmin(Request $request, Events $event, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_event_delete_' . $event->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid event delete token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $entityManager->remove($event);
        $entityManager->flush();

        $this->addFlash('success', 'Event deleted from admin dashboard.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
    }

    #[Route('/admin/dashboard/reservations/{id}/status', name: 'app_admin_reservation_status', methods: ['POST'])]
    public function updateReservationStatus(Request $request, Reservations $reservation, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_reservation_status_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid reservation status token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $status = mb_strtolower(trim((string) $request->request->get('status', '')));
        if ($status === 'accepte') {
            $status = 'confirmee';
        }

        if (!in_array($status, ['en_attente', 'confirmee', 'annulee'], true)) {
            $this->addFlash('error', 'Invalid reservation status value.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $oldStatus = $reservation->getStatut();
        $wasConfirmed = in_array($oldStatus, ['confirmee', 'accepte'], true);
        $isConfirmed = ($status === 'confirmee');
        $event = $reservation->getEvent();
        $peopleCount = max(0, $reservation->getNombrePersonnes());

        if ($event instanceof Events) {
            // Consume places only when reservation becomes confirmed.
            if (!$wasConfirmed && $isConfirmed) {
                if ($peopleCount > $event->getPlacesDisponibles()) {
                    $this->addFlash('error', sprintf(
                        'Impossible de confirmer: seulement %d place(s) disponible(s).',
                        $event->getPlacesDisponibles()
                    ));
                    return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
                }

                $event->setPlacesDisponibles($event->getPlacesDisponibles() - $peopleCount);
                $entityManager->persist($event);
            }

            // Release places when reservation leaves confirmed status.
            if ($wasConfirmed && !$isConfirmed) {
                $event->setPlacesDisponibles($event->getPlacesDisponibles() + $peopleCount);
                $entityManager->persist($event);
            }
        }

        $reservation->setStatut($status);
        $entityManager->flush();

        $this->addFlash('success', 'Reservation status updated successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
    }

    #[Route('/admin/dashboard/reservations/{id}/delete', name: 'app_admin_reservation_delete', methods: ['POST'])]
    public function deleteReservationFromAdmin(Request $request, Reservations $reservation, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_reservation_delete_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid reservation delete token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
        }

        $event = $reservation->getEvent();
        if ($event instanceof Events && in_array($reservation->getStatut(), ['confirmee', 'accepte'], true)) {
            $event->setPlacesDisponibles($event->getPlacesDisponibles() + max(0, $reservation->getNombrePersonnes()));
            $entityManager->persist($event);
        }

        $entityManager->remove($reservation);
        $entityManager->flush();

        $this->addFlash('success', 'Reservation deleted from admin dashboard.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'experiences']);
    }

    #[Route('/admin/dashboard/blog/{id}/status', name: 'app_admin_blog_status', methods: ['POST'])]
    public function updateBlogStatus(Request $request, Posts $post, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_blog_status_' . $post->getIdPost(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid blog status token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
        }

        $status = mb_strtolower(trim((string) $request->request->get('status', '')));
        if (!in_array($status, ['public', 'private', 'hidden', 'scheduled'], true)) {
            $this->addFlash('error', 'Invalid blog status value.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
        }

        if ($status === 'scheduled') {
            $scheduledAtRaw = trim((string) $request->request->get('scheduled_at', ''));
            if ($scheduledAtRaw === '') {
                $this->addFlash('error', 'Scheduled posts require a future date and time.');
                return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
            }

            try {
                $scheduledAt = new \DateTimeImmutable($scheduledAtRaw);
            } catch (\Throwable) {
                $this->addFlash('error', 'Invalid scheduled date format.');
                return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
            }

            if ($scheduledAt <= new \DateTimeImmutable()) {
                $this->addFlash('error', 'Scheduled date must be in the future.');
                return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
            }

            $post->setScheduledAt(\DateTime::createFromImmutable($scheduledAt));
        } else {
            $post->setScheduledAt(null);
        }

        $post->setStatut($status);
        $entityManager->flush();

        $this->addFlash('success', 'Blog post status updated.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
    }

    #[Route('/admin/dashboard/blog/{id}/delete', name: 'app_admin_blog_delete', methods: ['POST'])]
    public function deleteBlogPostFromAdmin(Request $request, Posts $post, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_blog_delete_' . $post->getIdPost(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid blog delete token.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
        }

        $entityManager->remove($post);
        $entityManager->flush();

        $this->addFlash('success', 'Blog post deleted from admin dashboard.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'blog']);
    }

    /**
     * @return array{
     *   orders_24h:int,
     *   buyer_orders_30d:int,
     *   buyer_cancelled_30d:int,
     *   buyer_cancel_ratio:float,
     *   seller_orders_30d:int,
     *   seller_cancelled_orders_30d:int,
     *   seller_cancel_ratio:float,
     *   rapid_order_bucket_10m:int,
     *   products_24h:int,
     *   products_30d:int,
     *   suspicious_price_products_30d:int,
     *   has_activity:bool,
     *   trigger_reason:string
     * }
     */
    private function buildMarketplaceFraudBreakdown(Connection $connection, string $userId): array
    {
        $empty = [
            'orders_24h' => 0,
            'buyer_orders_30d' => 0,
            'buyer_cancelled_30d' => 0,
            'buyer_cancel_ratio' => 0.0,
            'seller_orders_30d' => 0,
            'seller_cancelled_orders_30d' => 0,
            'seller_cancel_ratio' => 0.0,
            'rapid_order_bucket_10m' => 0,
            'products_24h' => 0,
            'products_30d' => 0,
            'suspicious_price_products_30d' => 0,
            'has_activity' => false,
            'trigger_reason' => 'No marketplace activity',
        ];

        if (trim($userId) === '') {
            return $empty;
        }

        try {
            $orders24h = (int) $connection->executeQuery(
                "SELECT COUNT(*) FROM facture WHERE user_id = :user_id AND date_facture >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $buyerOrders30d = (int) $connection->executeQuery(
                "SELECT COUNT(*) FROM facture WHERE user_id = :user_id AND date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $buyerCancelled30d = (int) $connection->executeQuery(
                "SELECT COUNT(*) FROM facture WHERE user_id = :user_id AND delivery_status = 'cancelled' AND date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $rapidOrderBucket10m = (int) $connection->executeQuery(
                "SELECT COALESCE(MAX(bucket_count), 0) FROM (
                    SELECT COUNT(*) AS bucket_count
                    FROM facture
                    WHERE user_id = :user_id
                      AND date_facture >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                    GROUP BY FLOOR(UNIX_TIMESTAMP(date_facture) / 600)
                ) t",
                ['user_id' => $userId]
            )->fetchOne();

            $products24h = (int) $connection->executeQuery(
                "SELECT COUNT(*) FROM products WHERE userId = :user_id AND created_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $products30d = (int) $connection->executeQuery(
                "SELECT COUNT(*) FROM products WHERE userId = :user_id AND created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $suspiciousPriceProducts30d = (int) $connection->executeQuery(
                "SELECT COUNT(*) FROM products WHERE userId = :user_id AND (price <= 0.50 OR price >= 10000) AND created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $sellerOrders30d = (int) $connection->executeQuery(
                "SELECT COUNT(DISTINCT f.id_facture)
                 FROM facture f
                 JOIN facture_product fp ON fp.facture_id = f.id_facture
                 JOIN products p ON p.id = fp.product_id
                 WHERE p.userId = :user_id
                   AND f.date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();

            $sellerCancelledOrders30d = (int) $connection->executeQuery(
                "SELECT COUNT(DISTINCT f.id_facture)
                 FROM facture f
                 JOIN facture_product fp ON fp.facture_id = f.id_facture
                 JOIN products p ON p.id = fp.product_id
                 WHERE p.userId = :user_id
                   AND f.delivery_status = 'cancelled'
                   AND f.date_facture >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['user_id' => $userId]
            )->fetchOne();
        } catch (\Throwable) {
            return $empty;
        }

        $buyerCancelRatio = $buyerOrders30d > 0 ? round($buyerCancelled30d / $buyerOrders30d, 4) : 0.0;
        $sellerCancelRatio = $sellerOrders30d > 0 ? round($sellerCancelledOrders30d / $sellerOrders30d, 4) : 0.0;

        $hasActivity = $buyerOrders30d > 0 || $sellerOrders30d > 0 || $products30d > 0;

        $triggerReason = 'No strong marketplace fraud signal';
        if ($buyerCancelRatio >= 0.6) {
            $triggerReason = 'High buyer order cancellation ratio';
        } elseif ($sellerCancelRatio >= 0.5) {
            $triggerReason = 'High seller order cancellation ratio';
        } elseif ($rapidOrderBucket10m >= 3) {
            $triggerReason = 'Rapid order burst detected (10-minute bucket)';
        } elseif ($products24h >= 6) {
            $triggerReason = 'Rapid product listing burst detected';
        } elseif ($suspiciousPriceProducts30d >= 1) {
            $triggerReason = 'Abnormal marketplace pricing pattern detected';
        } elseif (!$hasActivity) {
            $triggerReason = 'No marketplace activity';
        }

        return [
            'orders_24h' => $orders24h,
            'buyer_orders_30d' => $buyerOrders30d,
            'buyer_cancelled_30d' => $buyerCancelled30d,
            'buyer_cancel_ratio' => $buyerCancelRatio,
            'seller_orders_30d' => $sellerOrders30d,
            'seller_cancelled_orders_30d' => $sellerCancelledOrders30d,
            'seller_cancel_ratio' => $sellerCancelRatio,
            'rapid_order_bucket_10m' => $rapidOrderBucket10m,
            'products_24h' => $products24h,
            'products_30d' => $products30d,
            'suspicious_price_products_30d' => $suspiciousPriceProducts30d,
            'has_activity' => $hasActivity,
            'trigger_reason' => $triggerReason,
        ];
    }

    /**
     * @return array{
     *     summary:array{total_actions:int,actions_last_24h:int,distinct_modules:int,distinct_actions:int,first_activity_at:?string,last_activity_at:?string},
     *     module_breakdown:array<int,array<string,mixed>>,
     *     action_breakdown:array<int,array<string,mixed>>,
     *     recent_entries:array<int,array<string,mixed>>,
     *     content_analysis:array{entries_with_content:int,toxic_like_entries:int,threat_like_entries:int},
    *     cancellation_tracking:array{recent_cancellations:array<int,array<string,mixed>>,burst_flags:array{ai_flag:bool,reason:string,closest_interval_minutes:?int,rapid_pair_count:int,max_burst_size:int}},
     *     risk:?array<string,mixed>
     * }
     */
    private function buildUserActivityInsights(Connection $connection, string $userId, string $moduleFilter = '', string $actionFilter = ''): array
    {
        $empty = [
            'summary' => [
                'total_actions' => 0,
                'actions_last_24h' => 0,
                'distinct_modules' => 0,
                'distinct_actions' => 0,
                'first_activity_at' => null,
                'last_activity_at' => null,
            ],
            'module_breakdown' => [],
            'action_breakdown' => [],
            'recent_entries' => [],
            'content_analysis' => [
                'entries_with_content' => 0,
                'toxic_like_entries' => 0,
                'threat_like_entries' => 0,
            ],
            'cancellation_tracking' => [
                'recent_cancellations' => [],
                'burst_flags' => [
                    'ai_flag' => false,
                    'reason' => 'No cancellation burst detected',
                    'closest_interval_minutes' => null,
                    'rapid_pair_count' => 0,
                    'max_burst_size' => 0,
                ],
            ],
            'risk' => null,
        ];

        if (trim($userId) === '') {
            return $empty;
        }

        $moduleFilter = mb_strtolower(trim($moduleFilter));
        $actionFilter = mb_strtolower(trim($actionFilter));

        try {
            $summary = $connection->executeQuery(
                "SELECT
                    COUNT(*) AS total_actions,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS actions_last_24h,
                    COUNT(DISTINCT module) AS distinct_modules,
                    COUNT(DISTINCT action) AS distinct_actions,
                    MIN(created_at) AS first_activity_at,
                    MAX(created_at) AS last_activity_at
                 FROM activity_log
                 WHERE user_id = :user_id",
                ['user_id' => $userId]
            )->fetchAssociative() ?: [];

            $moduleBreakdown = $connection->executeQuery(
                "SELECT module, COUNT(*) AS action_count, MAX(created_at) AS last_seen_at
                 FROM activity_log
                 WHERE user_id = :user_id
                 GROUP BY module
                 ORDER BY action_count DESC, last_seen_at DESC",
                ['user_id' => $userId]
            )->fetchAllAssociative();

            $actionBreakdown = $connection->executeQuery(
                "SELECT module, action, COUNT(*) AS action_count, MAX(created_at) AS last_seen_at
                 FROM activity_log
                 WHERE user_id = :user_id
                 GROUP BY module, action
                 ORDER BY action_count DESC, last_seen_at DESC
                 LIMIT 40",
                ['user_id' => $userId]
            )->fetchAllAssociative();

            $recentEntries = $connection->executeQuery(
                "SELECT id, module, action, target_type, target_id, target_name, content, destination, created_at
                 FROM activity_log
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT 240",
                ['user_id' => $userId]
            )->fetchAllAssociative();

            $risk = $connection->executeQuery(
                "SELECT risk_score, anomaly_score, click_speed_score, login_failure_score,
                        message_toxicity_score, cancellation_abuse_score, marketplace_fraud_score, risk_band,
                        recommended_action, updated_at
                 FROM risk_assessment
                 WHERE user_id = :user_id
                 LIMIT 1",
                ['user_id' => $userId]
            )->fetchAssociative() ?: null;

            $recentCancellations = $connection->executeQuery(
                "SELECT b.id,
                        b.place_id,
                        p.title AS place_title,
                        p.city AS place_city,
                        b.start_date,
                        b.end_date,
                        b.total_price,
                        b.created_at,
                        b.cancelled_at
                 FROM booking b
                 LEFT JOIN places p ON p.id = b.place_id
                 WHERE b.user_id = :user_id
                   AND b.status = 'CANCELLED'
                   AND b.cancelled_at IS NOT NULL
                 ORDER BY b.cancelled_at DESC
                 LIMIT 30",
                ['user_id' => $userId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return $empty;
        }

        $loggedCancelledBookingIds = [];
        foreach ($recentEntries as $entry) {
            $entryAction = mb_strtolower((string) ($entry['action'] ?? ''));
            if (!str_contains($entryAction, 'cancel')) {
                continue;
            }

            $targetId = (int) ($entry['target_id'] ?? 0);
            if ($targetId > 0) {
                $loggedCancelledBookingIds[$targetId] = true;
            }
        }

        $maxCancelledAt = null;
        $cancellationActionCount = 0;
        foreach ($recentCancellations as $cancel) {
            $bookingId = (int) ($cancel['id'] ?? 0);
            if ($bookingId <= 0) {
                continue;
            }

            ++$cancellationActionCount;
            $cancelledAt = (string) ($cancel['cancelled_at'] ?? '');
            if ($maxCancelledAt === null || ($cancelledAt !== '' && $cancelledAt > $maxCancelledAt)) {
                $maxCancelledAt = $cancelledAt;
            }

            if (isset($loggedCancelledBookingIds[$bookingId])) {
                continue;
            }

            $placeTitle = (string) ($cancel['place_title'] ?? 'Unknown place');
            $startDate = isset($cancel['start_date']) ? (string) $cancel['start_date'] : null;
            $endDate = isset($cancel['end_date']) ? (string) $cancel['end_date'] : null;
            $stay = $startDate && $endDate ? ($startDate . ' to ' . $endDate) : 'n/a';

            $recentEntries[] = [
                'id' => 'cancel-backfill-' . $bookingId,
                'module' => 'booking',
                'action' => 'booking_cancelled_by_guest',
                'target_type' => 'booking',
                'target_id' => (string) $bookingId,
                'target_name' => $placeTitle,
                'content' => sprintf('Cancelled booking #%d for %s (%s).', $bookingId, $placeTitle, $stay),
                'destination' => sprintf('Place #%d', (int) ($cancel['place_id'] ?? 0)),
                'created_at' => (string) ($cancel['cancelled_at'] ?? $cancel['created_at'] ?? ''),
            ];
        }

        if ($cancellationActionCount > 0) {
            $foundCancellationAction = false;
            foreach ($actionBreakdown as $row) {
                $rowModule = mb_strtolower((string) ($row['module'] ?? ''));
                $rowAction = mb_strtolower((string) ($row['action'] ?? ''));
                if ($rowModule === 'booking' && str_contains($rowAction, 'cancel')) {
                    $foundCancellationAction = true;
                    break;
                }
            }

            if (!$foundCancellationAction) {
                $actionBreakdown[] = [
                    'module' => 'booking',
                    'action' => 'booking_cancelled_by_guest',
                    'action_count' => $cancellationActionCount,
                    'last_seen_at' => $maxCancelledAt,
                ];
            }
        }

        usort($actionBreakdown, static function (array $a, array $b): int {
            $countA = (int) ($a['action_count'] ?? 0);
            $countB = (int) ($b['action_count'] ?? 0);
            if ($countA !== $countB) {
                return $countB <=> $countA;
            }

            $timeA = (string) ($a['last_seen_at'] ?? '');
            $timeB = (string) ($b['last_seen_at'] ?? '');
            return strcmp($timeB, $timeA);
        });

        usort($recentEntries, static function (array $a, array $b): int {
            $timeA = (string) ($a['created_at'] ?? '');
            $timeB = (string) ($b['created_at'] ?? '');
            return strcmp($timeB, $timeA);
        });

        if ($moduleFilter !== '' || $actionFilter !== '') {
            $recentEntries = array_values(array_filter($recentEntries, static function (array $entry) use ($moduleFilter, $actionFilter): bool {
                $entryModule = mb_strtolower((string) ($entry['module'] ?? ''));
                $entryAction = mb_strtolower((string) ($entry['action'] ?? ''));

                if ($moduleFilter !== '' && $entryModule !== $moduleFilter) {
                    return false;
                }

                if ($actionFilter !== '' && $entryAction !== $actionFilter) {
                    return false;
                }

                return true;
            }));
        }

        if (count($recentEntries) > 120) {
            $recentEntries = array_slice($recentEntries, 0, 120);
        }

        $toxicKeywords = ['idiot', 'stupid', 'hate', 'fuck', 'shit', 'bitch', 'bastard', 'die', 'death', 'kill', 'suicide'];
        $threatKeywords = ['die', 'death', 'kill', 'suicide', 'dead'];

        $entriesWithContent = 0;
        $toxicLikeEntries = 0;
        $threatLikeEntries = 0;

        foreach ($recentEntries as &$entry) {
            $content = mb_strtolower(trim((string) ($entry['content'] ?? '')));
            $action = mb_strtolower((string) ($entry['action'] ?? ''));

            $severity = 'low';
            $analysis = 'Routine interaction';

            if ($content !== '') {
                ++$entriesWithContent;
            }

            $hasToxicLike = false;
            foreach ($toxicKeywords as $keyword) {
                if ($content !== '' && str_contains($content, $keyword)) {
                    $hasToxicLike = true;
                    break;
                }
            }

            $hasThreatLike = false;
            foreach ($threatKeywords as $keyword) {
                if ($content !== '' && str_contains($content, $keyword)) {
                    $hasThreatLike = true;
                    break;
                }
            }

            if ($hasToxicLike) {
                ++$toxicLikeEntries;
                $severity = 'medium';
                $analysis = 'Content contains abusive wording signals';
            }

            if ($hasThreatLike) {
                ++$threatLikeEntries;
                $severity = 'high';
                $analysis = 'Potential threat-like wording detected';
            }

            if (str_contains($action, 'failed') || str_contains($action, 'ban') || str_contains($action, 'blocked')) {
                $severity = $severity === 'high' ? 'high' : 'medium';
                if ($severity === 'medium' && $analysis === 'Routine interaction') {
                    $analysis = 'Action indicates failed or restricted behavior';
                }
            }

            if (str_contains($action, 'cancelled') && $analysis === 'Routine interaction') {
                $severity = 'medium';
                $analysis = 'Booking cancellation event tracked for abuse pattern analysis';
            }

            $entry['analysis'] = $analysis;
            $entry['severity'] = $severity;
        }
        unset($entry);

        $cancellationBurstFlags = [
            'ai_flag' => false,
            'reason' => 'No cancellation burst detected',
            'closest_interval_minutes' => null,
            'rapid_pair_count' => 0,
            'max_burst_size' => 0,
        ];

        $cancelTimes = [];
        foreach ($recentCancellations as $item) {
            $cancelledAt = (string) ($item['cancelled_at'] ?? '');
            if ($cancelledAt === '') {
                continue;
            }

            $ts = strtotime($cancelledAt);
            if ($ts === false) {
                continue;
            }

            $cancelTimes[] = $ts;
        }

        if (count($cancelTimes) >= 2) {
            sort($cancelTimes);

            $closest = null;
            $rapidPairs = 0;
            $maxBurst = 1;

            for ($i = 1; $i < count($cancelTimes); ++$i) {
                $delta = (int) floor(($cancelTimes[$i] - $cancelTimes[$i - 1]) / 60);
                if ($closest === null || $delta < $closest) {
                    $closest = $delta;
                }

                if ($delta <= 45) {
                    ++$rapidPairs;
                }
            }

            $left = 0;
            $windowSeconds = 2 * 60 * 60;
            for ($right = 0; $right < count($cancelTimes); ++$right) {
                while ($cancelTimes[$right] - $cancelTimes[$left] > $windowSeconds) {
                    ++$left;
                }

                $size = $right - $left + 1;
                if ($size > $maxBurst) {
                    $maxBurst = $size;
                }
            }

            $flagged = $maxBurst >= 3 || $rapidPairs >= 2 || ($closest !== null && $closest <= 20);
            $reason = 'No cancellation burst detected';
            if ($flagged) {
                $reason = sprintf(
                    'Burst detected: %d close cancellation pair(s), max %d cancellations within 2h, nearest interval %s min.',
                    $rapidPairs,
                    $maxBurst,
                    $closest !== null ? (string) $closest : 'n/a'
                );
            }

            $cancellationBurstFlags = [
                'ai_flag' => $flagged,
                'reason' => $reason,
                'closest_interval_minutes' => $closest,
                'rapid_pair_count' => $rapidPairs,
                'max_burst_size' => $maxBurst,
            ];
        }

        return [
            'summary' => [
                'total_actions' => (int) ($summary['total_actions'] ?? 0),
                'actions_last_24h' => (int) ($summary['actions_last_24h'] ?? 0),
                'distinct_modules' => (int) ($summary['distinct_modules'] ?? 0),
                'distinct_actions' => (int) ($summary['distinct_actions'] ?? 0),
                'first_activity_at' => isset($summary['first_activity_at']) ? (string) $summary['first_activity_at'] : null,
                'last_activity_at' => isset($summary['last_activity_at']) ? (string) $summary['last_activity_at'] : null,
            ],
            'module_breakdown' => $moduleBreakdown,
            'action_breakdown' => $actionBreakdown,
            'recent_entries' => $recentEntries,
            'content_analysis' => [
                'entries_with_content' => $entriesWithContent,
                'toxic_like_entries' => $toxicLikeEntries,
                'threat_like_entries' => $threatLikeEntries,
            ],
            'cancellation_tracking' => [
                'recent_cancellations' => $recentCancellations,
                'burst_flags' => $cancellationBurstFlags,
            ],
            'risk' => is_array($risk) ? $risk : null,
        ];
    }

    /**
     * @param array<string, mixed> $riskRow
     *
     * @return array{signal_key:string,label:string,score:float}
     */
    private function buildFlagReason(array $riskRow): array
    {
        $signals = [
            'anomaly_score' => (float) ($riskRow['anomaly_score'] ?? 0.0),
            'click_speed_score' => (float) ($riskRow['click_speed_score'] ?? 0.0),
            'login_failure_score' => (float) ($riskRow['login_failure_score'] ?? 0.0),
            'message_toxicity_score' => (float) ($riskRow['message_toxicity_score'] ?? 0.0),
            'bot_behavior_score' => (float) ($riskRow['bot_behavior_score'] ?? 0.0),
            'cancellation_abuse_score' => (float) ($riskRow['cancellation_abuse_score'] ?? 0.0),
            'marketplace_fraud_score' => (float) ($riskRow['marketplace_fraud_score'] ?? 0.0),
        ];

        arsort($signals);
        $topSignal = (string) array_key_first($signals);
        $topScore = (float) ($signals[$topSignal] ?? 0.0);

        $labels = [
            'anomaly_score' => 'Anomalous behavior pattern',
            'click_speed_score' => 'High click/request speed burst',
            'login_failure_score' => 'Repeated login failures / unusual auth pattern',
            'message_toxicity_score' => 'Toxic message pattern detected',
            'bot_behavior_score' => 'Automated or repetitive bot-like behavior',
            'cancellation_abuse_score' => 'Booking cancellation abuse pattern',
            'marketplace_fraud_score' => 'Marketplace fake order/product behavior pattern',
        ];

        return [
            'signal_key' => $topSignal,
            'label' => $labels[$topSignal] ?? 'Composite high-risk behavior',
            'score' => round($topScore, 2),
        ];
    }

    /**
     * @return array{module:?string,action:?string,content:?string,created_at:?string}
     */
    private function resolveFlagSourceActivity(Connection $connection, string $userId, string $signalKey): array
    {
        if (trim($userId) === '') {
            return [
                'module' => null,
                'action' => null,
                'content' => null,
                'created_at' => null,
            ];
        }

        $module = null;
        $actions = [];

        if ($signalKey === 'message_toxicity_score') {
            $module = 'messaging';
            $actions = ['message_sent', 'message_edited', 'file_message_sent'];
        } elseif ($signalKey === 'login_failure_score') {
            $module = 'auth';
            $actions = ['login_failed', 'face_id_login_failed'];
        } elseif ($signalKey === 'cancellation_abuse_score') {
            $module = 'booking';
            $actions = ['booking_cancelled_by_guest'];
        } elseif ($signalKey === 'marketplace_fraud_score') {
            $module = 'marketplace';
            $actions = ['order_created', 'seller_order_cancelled', 'product_created', 'product_updated'];
        } elseif ($signalKey === 'moderation') {
            $module = 'moderation';
            $actions = ['high_risk_content_hidden'];
        } elseif ($signalKey === 'click_speed_score') {
            $module = null;
            $actions = [];
        } elseif ($signalKey === 'anomaly_score') {
            $module = null;
            $actions = [];
        }

        try {
            $sql = "SELECT module, action, content, created_at FROM activity_log WHERE user_id = :user_id";
            $params = ['user_id' => $userId];

            if ($module !== null) {
                $sql .= ' AND module = :module';
                $params['module'] = $module;
            }

            if ($actions !== []) {
                $placeholders = [];
                foreach ($actions as $index => $action) {
                    $key = 'action_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $action;
                }
                $sql .= ' AND action IN (' . implode(', ', $placeholders) . ')';
            }

            $sql .= ' ORDER BY created_at DESC LIMIT 1';

            $row = $connection->executeQuery($sql, $params)->fetchAssociative();

            if (!is_array($row)) {
                $row = $connection->executeQuery(
                    'SELECT module, action, content, created_at FROM activity_log WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1',
                    ['user_id' => $userId]
                )->fetchAssociative();
            }

            if (!is_array($row)) {
                return [
                    'module' => null,
                    'action' => null,
                    'content' => null,
                    'created_at' => null,
                ];
            }

            return [
                'module' => isset($row['module']) ? (string) $row['module'] : null,
                'action' => isset($row['action']) ? (string) $row['action'] : null,
                'content' => isset($row['content']) ? (string) $row['content'] : null,
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            ];
        } catch (\Throwable) {
            return [
                'module' => null,
                'action' => null,
                'content' => null,
                'created_at' => null,
            ];
        }
    }

    private function ensureRecentRiskAlertAckTableExists(Connection $connection): void
    {
        try {
            $connection->executeStatement("\n                CREATE TABLE IF NOT EXISTS admin_recent_risk_alert_ack (\n                    admin_user_id VARCHAR(36) NOT NULL,\n                    activity_log_id BIGINT NOT NULL,\n                    acknowledged_at DATETIME NOT NULL,\n                    INDEX idx_admin_recent_risk_alert_ack_admin_user_id (admin_user_id),\n                    INDEX idx_admin_recent_risk_alert_ack_activity_log_id (activity_log_id),\n                    PRIMARY KEY(admin_user_id, activity_log_id)\n                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB\n            ");
        } catch (\Throwable) {
            // Best effort for early rollout and local environments.
        }
    }

    /**
     * @return array<int, array{name:string,url:string,status:string,details:string,response_ms:?int}>
     */
    private function collectAiServiceStatus(): array
    {
        $defaultUrls = [
            'Anomaly API' => 'http://127.0.0.1:8101/health',
            'Toxicity API' => 'http://127.0.0.1:8102/health',
            'Bot API' => 'http://127.0.0.1:8105/health',
            'Rules API' => 'http://127.0.0.1:8103/health',
        ];

        $status = [];

        foreach ($defaultUrls as $name => $defaultUrl) {
            $envKey = match ($name) {
                'Anomaly API' => 'AI_ANOMALY_API_URL',
                'Toxicity API' => 'AI_TOXICITY_API_URL',
                'Bot API' => 'BOT_API_URL',
                default => 'AI_RULES_API_URL',
            };

            $baseUrl = trim((string) ($_ENV[$envKey] ?? getenv($envKey) ?: ''));
            $healthUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/health' : $defaultUrl;

            $startedAt = microtime(true);
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 2,
                ],
            ]);

            $result = @file_get_contents($healthUrl, false, $context);
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            if ($result === false) {
                $status[] = [
                    'name' => $name,
                    'url' => $healthUrl,
                    'status' => 'down',
                    'details' => 'Service unreachable',
                    'response_ms' => null,
                ];
                continue;
            }

            $decoded = json_decode($result, true);
            $details = 'Healthy';
            if (is_array($decoded) && isset($decoded['model']) && is_scalar($decoded['model'])) {
                $details = 'Model: ' . (string) $decoded['model'];
            } elseif (is_array($decoded) && isset($decoded['redis']) && is_scalar($decoded['redis'])) {
                $details = 'Redis: ' . (string) $decoded['redis'];
            }

            $status[] = [
                'name' => $name,
                'url' => $healthUrl,
                'status' => 'up',
                'details' => $details,
                'response_ms' => $duration,
            ];
        }

        return $status;
    }

    #[Route('/admin/marketplace/products/create', name: 'app_admin_marketplace_product_create', methods: ['POST'])]
    public function createMarketplaceProduct(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_marketplace_product_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid marketplace form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
        }

        $data = $this->normalizeMarketplaceProductPayload($request);
        $errors = $this->validateMarketplaceProductPayload($data, false, null);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
        }

        $conn = $entityManager->getConnection();
        $conn->insert('products', [
            'title' => $data['title'],
            'description' => $data['description'],
            'type' => $data['type'],
            'price' => $data['price'],
            'quantity' => $data['quantity'],
            'category' => $data['category'],
            'image' => $data['image'],
            'created_date' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'userId' => $data['userId'],
            'reserved_quantity' => 0,
        ]);

        $this->addFlash('success', 'Marketplace product created successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
    }

    #[Route('/admin/marketplace/products/{id}/update', name: 'app_admin_marketplace_product_update', methods: ['POST'])]
    public function updateMarketplaceProduct(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_marketplace_product_update_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid marketplace form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
        }

        $conn = $entityManager->getConnection();
        $existing = $conn->fetchAssociative('SELECT id, COALESCE(reserved_quantity, 0) AS reserved_quantity FROM products WHERE id = ?', [$id]);
        if (!is_array($existing)) {
            $this->addFlash('error', 'Product not found.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
        }

        $data = $this->normalizeMarketplaceProductPayload($request);
        $errors = $this->validateMarketplaceProductPayload($data, true, (int) $existing['reserved_quantity']);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
        }

        $conn->update('products', [
            'title' => $data['title'],
            'description' => $data['description'],
            'type' => $data['type'],
            'price' => $data['price'],
            'quantity' => $data['quantity'],
            'category' => $data['category'],
            'image' => $data['image'],
            'userId' => $data['userId'],
        ], ['id' => $id]);

        $this->addFlash('success', 'Marketplace product updated successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
    }

    #[Route('/admin/marketplace/products/{id}/delete', name: 'app_admin_marketplace_product_delete', methods: ['POST'])]
    public function deleteMarketplaceProduct(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_marketplace_product_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid marketplace form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
        }

        $conn = $entityManager->getConnection();
        $conn->executeStatement('DELETE FROM products WHERE id = ?', [$id]);

        $this->addFlash('success', 'Marketplace product deleted successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'marketplace']);
    }

    #[Route('/admin/places/create', name: 'app_admin_place_create', methods: ['POST'])]
    public function createPlace(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_place_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid place form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        $hostId = trim((string) $request->request->get('host_id', ''));
        $host = $entityManager->find(User::class, $hostId);
        if (!$host instanceof User) {
            $this->addFlash('error', 'Please select a valid host user.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        $formData = [
            'title' => trim((string) $request->request->get('title', '')),
            'description' => trim((string) $request->request->get('description', '')),
            'city' => trim((string) $request->request->get('city', '')),
            'category' => trim((string) $request->request->get('category', '')),
            'price_per_day' => trim((string) $request->request->get('price_per_day', '')),
            'capacity' => trim((string) $request->request->get('capacity', '')),
            'max_guests' => trim((string) $request->request->get('max_guests', '')),
            'address' => trim((string) $request->request->get('address', '')),
            'latitude' => trim((string) $request->request->get('latitude', '')),
            'longitude' => trim((string) $request->request->get('longitude', '')),
            'status' => strtoupper(trim((string) $request->request->get('status', Place::STATUS_PENDING))),
        ];

        $uploadedImages = $request->files->get('images', []);
        if (!is_array($uploadedImages)) {
            $uploadedImages = [$uploadedImages];
        }
        $errors = [];
        if (!$this->validatePlaceInput($formData, $uploadedImages, true, $errors)) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        if (!$host->isAdmin() && $host->getRole() !== RoleEnum::HOST) {
            $host->setRole(RoleEnum::HOST);
        }

        $place = new Place();
        $place->setHost($host);
        $place->setTitle($formData['title']);
        $place->setDescription($formData['description'] !== '' ? $formData['description'] : null);
        $place->setCity($formData['city']);
        $place->setCategory($formData['category'] !== '' ? $formData['category'] : null);
        $place->setPricePerDay((float) $formData['price_per_day']);
        $place->setCapacity((int) $formData['capacity']);
        $place->setMaxGuests((int) $formData['max_guests']);
        $place->setAddress($formData['address']);
        $place->setLatitude($this->normalizeDecimalString($formData['latitude']));
        $place->setLongitude($this->normalizeDecimalString($formData['longitude']));
        $place->setStatus($formData['status']);

        $entityManager->persist($place);
        $entityManager->flush();

        $this->persistPlaceImages($entityManager, $place, $uploadedImages, true);
        $entityManager->flush();

        $this->addFlash('success', 'Place created successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
    }

    #[Route('/admin/places/{id}/update', name: 'app_admin_place_update', methods: ['POST'])]
    public function updatePlace(Place $place, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_place_update_' . $place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid place form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        $hostId = trim((string) $request->request->get('host_id', ''));
        $host = $entityManager->find(User::class, $hostId);
        if (!$host instanceof User) {
            $this->addFlash('error', 'Please select a valid host user.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        $formData = [
            'title' => trim((string) $request->request->get('title', '')),
            'description' => trim((string) $request->request->get('description', '')),
            'city' => trim((string) $request->request->get('city', '')),
            'category' => trim((string) $request->request->get('category', '')),
            'price_per_day' => trim((string) $request->request->get('price_per_day', '')),
            'capacity' => trim((string) $request->request->get('capacity', '')),
            'max_guests' => trim((string) $request->request->get('max_guests', '')),
            'address' => trim((string) $request->request->get('address', '')),
            'latitude' => trim((string) $request->request->get('latitude', '')),
            'longitude' => trim((string) $request->request->get('longitude', '')),
            'status' => strtoupper(trim((string) $request->request->get('status', Place::STATUS_PENDING))),
        ];

        $uploadedImages = $request->files->get('images', []);
        if (!is_array($uploadedImages)) {
            $uploadedImages = [$uploadedImages];
        }
        $errors = [];
        if (!$this->validatePlaceInput($formData, $uploadedImages, false, $errors)) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        if (!$host->isAdmin() && $host->getRole() !== RoleEnum::HOST) {
            $host->setRole(RoleEnum::HOST);
        }

        $place->setHost($host);
        $place->setTitle($formData['title']);
        $place->setDescription($formData['description'] !== '' ? $formData['description'] : null);
        $place->setCity($formData['city']);
        $place->setCategory($formData['category'] !== '' ? $formData['category'] : null);
        $place->setPricePerDay((float) $formData['price_per_day']);
        $place->setCapacity((int) $formData['capacity']);
        $place->setMaxGuests((int) $formData['max_guests']);
        $place->setAddress($formData['address']);
        $place->setLatitude($this->normalizeDecimalString($formData['latitude']));
        $place->setLongitude($this->normalizeDecimalString($formData['longitude']));
        $place->setStatus($formData['status']);

        $removeImageIdsRaw = $request->request->all('remove_image_ids');
        $removeImageIds = array_values(array_unique(array_map('intval', is_array($removeImageIdsRaw) ? $removeImageIdsRaw : [])));
        if ($removeImageIds !== []) {
            $this->removePlaceImages($entityManager, $place, $removeImageIds);
        }

        $this->persistPlaceImages($entityManager, $place, $uploadedImages, false);
        $this->synchronizePlacePrimaryImage($entityManager, $place);
        $entityManager->flush();

        $this->addFlash('success', 'Place updated successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
    }

    #[Route('/admin/places/{id}/delete', name: 'app_admin_place_delete', methods: ['POST'])]
    public function deletePlace(Place $place, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_place_delete_' . $place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid place form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        $entityManager->remove($place);
        $entityManager->flush();

        $this->addFlash('success', 'Place deleted successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
    }

    #[Route('/admin/places/{id}/approve', name: 'app_admin_place_approve', methods: ['POST'])]
    public function approvePlaceFromDashboard(Place $place, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_place_approve_' . $place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid place form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        $place->setStatus(Place::STATUS_APPROVED);
        $place->setDenialReason(null);

        $host = $place->getHost();
        if ($host instanceof User && !$host->isAdmin() && $host->getRole() !== RoleEnum::HOST) {
            $host->setRole(RoleEnum::HOST);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Place approved successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
    }

    #[Route('/admin/places/{id}/reject', name: 'app_admin_place_reject', methods: ['POST'])]
    public function rejectPlaceFromDashboard(Place $place, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_place_reject_' . $place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid place form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
        }

        $reason = trim((string) $request->request->get('reason', ''));
        if ($reason === '') {
            $reason = 'Rejected by admin review.';
        }

        $place->setStatus(Place::STATUS_DENIED);
        $place->setDenialReason($reason);
        $entityManager->flush();

        $this->addFlash('success', 'Place rejected successfully.');
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'booking']);
    }

    private function buildPlacePreviewImagesMap(EntityManagerInterface $entityManager, array $places): array
    {
        $placeIds = [];
        foreach ($places as $place) {
            if ($place instanceof Place && $place->getId() !== null) {
                $placeIds[] = $place->getId();
            }
        }

        $placeIds = array_values(array_unique($placeIds));
        if ($placeIds === []) {
            return [];
        }

        $images = $entityManager->createQueryBuilder()
            ->select('i', 'p')
            ->from(PlaceImage::class, 'i')
            ->join('i.place', 'p')
            ->where('p.id IN (:placeIds)')
            ->setParameter('placeIds', $placeIds)
            ->orderBy('i.isPrimary', 'DESC')
            ->addOrderBy('i.sortOrder', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $previewMap = [];
        foreach ($images as $image) {
            if (!$image instanceof PlaceImage || !$image->getPlace() instanceof Place) {
                continue;
            }

            $placeId = $image->getPlace()->getId();
            if ($placeId === null || isset($previewMap[$placeId])) {
                continue;
            }

            $previewMap[$placeId] = $image->getUrl();
        }

        return $previewMap;
    }

    private function buildPlaceGalleryImagesMap(EntityManagerInterface $entityManager, array $places): array
    {
        $placeIds = [];
        foreach ($places as $place) {
            if ($place instanceof Place && $place->getId() !== null) {
                $placeIds[] = $place->getId();
            }
        }

        $placeIds = array_values(array_unique($placeIds));
        if ($placeIds === []) {
            return [];
        }

        $images = $entityManager->createQueryBuilder()
            ->select('i', 'p')
            ->from(PlaceImage::class, 'i')
            ->join('i.place', 'p')
            ->where('p.id IN (:placeIds)')
            ->setParameter('placeIds', $placeIds)
            ->orderBy('i.isPrimary', 'DESC')
            ->addOrderBy('i.sortOrder', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $galleryMap = [];
        foreach ($images as $image) {
            if (!$image instanceof PlaceImage || !$image->getPlace() instanceof Place) {
                continue;
            }

            $placeId = $image->getPlace()->getId();
            if ($placeId === null) {
                continue;
            }

            if (!isset($galleryMap[$placeId])) {
                $galleryMap[$placeId] = [];
            }

            $galleryMap[$placeId][] = $image->getUrl();
        }

        return $galleryMap;
    }

    private function buildPlaceGalleryImageRecordsMap(EntityManagerInterface $entityManager, array $places): array
    {
        $placeIds = [];
        foreach ($places as $place) {
            if ($place instanceof Place && $place->getId() !== null) {
                $placeIds[] = $place->getId();
            }
        }

        $placeIds = array_values(array_unique($placeIds));
        if ($placeIds === []) {
            return [];
        }

        $images = $entityManager->createQueryBuilder()
            ->select('i', 'p')
            ->from(PlaceImage::class, 'i')
            ->join('i.place', 'p')
            ->where('p.id IN (:placeIds)')
            ->setParameter('placeIds', $placeIds)
            ->orderBy('i.isPrimary', 'DESC')
            ->addOrderBy('i.sortOrder', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $galleryMap = [];
        foreach ($images as $image) {
            if (!$image instanceof PlaceImage || !$image->getPlace() instanceof Place) {
                continue;
            }

            $placeId = $image->getPlace()->getId();
            if ($placeId === null) {
                continue;
            }

            if (!isset($galleryMap[$placeId])) {
                $galleryMap[$placeId] = [];
            }

            $galleryMap[$placeId][] = [
                'id' => $image->getId(),
                'url' => $image->getUrl(),
            ];
        }

        return $galleryMap;
    }

    /**
     * @param array<int, UploadedFile|mixed> $uploadedImages
     * @param array<int, string> $errors
     */
    private function validatePlaceInput(array $formData, array $uploadedImages, bool $requireImages, array &$errors): bool
    {
        $errors = [];

        if ($formData['title'] === '') {
            $errors[] = 'Place title is required.';
        }
        if ($formData['city'] === '') {
            $errors[] = 'Place city is required.';
        }
        if ($formData['address'] === '') {
            $errors[] = 'Place address is required.';
        }

        $pricePerDay = (float) $formData['price_per_day'];
        if ($formData['price_per_day'] === '' || $pricePerDay <= 0) {
            $errors[] = 'Price per day must be greater than 0.';
        }

        $capacity = (int) $formData['capacity'];
        if ($formData['capacity'] === '' || $capacity < 1) {
            $errors[] = 'Capacity must be at least 1.';
        }

        $maxGuests = (int) $formData['max_guests'];
        if ($formData['max_guests'] === '' || $maxGuests < 1) {
            $errors[] = 'Max guests must be at least 1.';
        }

        if (!in_array($formData['status'], [Place::STATUS_PENDING, Place::STATUS_APPROVED, Place::STATUS_DENIED], true)) {
            $errors[] = 'Invalid place status selected.';
        }

        if ($formData['latitude'] === '' || !is_numeric($formData['latitude']) || (float) $formData['latitude'] < -90 || (float) $formData['latitude'] > 90) {
            $errors[] = 'Latitude must be between -90 and 90.';
        }

        if ($formData['longitude'] === '' || !is_numeric($formData['longitude']) || (float) $formData['longitude'] < -180 || (float) $formData['longitude'] > 180) {
            $errors[] = 'Longitude must be between -180 and 180.';
        }

        $validImages = 0;
        foreach ($uploadedImages as $uploadedImage) {
            if (!$uploadedImage instanceof UploadedFile) {
                continue;
            }

            ++$validImages;
            if (!$this->isImageUpload($uploadedImage)) {
                $errors[] = 'Only image files are allowed for place images.';
                break;
            }
        }

        if ($requireImages && $validImages === 0) {
            $errors[] = 'Please upload at least one image for the place.';
        }

        return $errors === [];
    }

    /**
     * @param array<int, UploadedFile|mixed> $uploadedImages
     */
    private function persistPlaceImages(EntityManagerInterface $entityManager, Place $place, array $uploadedImages, bool $resetPrimary): void
    {
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/places';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $currentSortOrder = 0;
        if ($place->getImages()->count() > 0) {
            $currentSortOrder = $place->getImages()->count();
            if ($resetPrimary) {
                foreach ($place->getImages() as $existingImage) {
                    if ($existingImage instanceof PlaceImage) {
                        $existingImage->setIsPrimary(false);
                    }
                }
            }
        }

        foreach ($uploadedImages as $uploadedImage) {
            if (!$uploadedImage instanceof UploadedFile) {
                continue;
            }

            $extension = $this->guessUploadedImageExtension($uploadedImage);
            $fileName = sprintf('%s_%s.%s', $place->getId(), bin2hex(random_bytes(8)), $extension);
            $uploadedImage->move($uploadDir, $fileName);

            $placeImage = new PlaceImage();
            $placeImage->setPlace($place);
            $placeImage->setUrl($fileName);
            $placeImage->setSortOrder($currentSortOrder);
            $placeImage->setIsPrimary($currentSortOrder === 0);
            $entityManager->persist($placeImage);

            if ($currentSortOrder === 0) {
                $place->setImageUrl($fileName);
            }

            ++$currentSortOrder;
        }
    }

    private function normalizeDecimalString(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.') ?: '0';
    }

    private function removePlaceImages(EntityManagerInterface $entityManager, Place $place, array $removeImageIds): void
    {
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/places';

        foreach ($place->getImages()->toArray() as $existingImage) {
            if (!$existingImage instanceof PlaceImage) {
                continue;
            }

            $imageId = $existingImage->getId();
            if ($imageId === null || !in_array($imageId, $removeImageIds, true)) {
                continue;
            }

            $filePath = $uploadDir . '/' . $existingImage->getUrl();
            if (is_file($filePath)) {
                @unlink($filePath);
            }

            $entityManager->remove($existingImage);
        }
    }

    private function synchronizePlacePrimaryImage(EntityManagerInterface $entityManager, Place $place): void
    {
        $images = $entityManager->createQueryBuilder()
            ->select('i')
            ->from(PlaceImage::class, 'i')
            ->where('i.place = :place')
            ->setParameter('place', $place)
            ->orderBy('i.isPrimary', 'DESC')
            ->addOrderBy('i.sortOrder', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        if ($images === []) {
            $place->setImageUrl(null);
            return;
        }

        $primaryImage = $images[0];
        if (!$primaryImage instanceof PlaceImage) {
            $place->setImageUrl(null);
            return;
        }

        foreach ($images as $index => $image) {
            if ($image instanceof PlaceImage) {
                $image->setIsPrimary($index === 0);
            }
        }

        $place->setImageUrl($primaryImage->getUrl());
    }

    private function isImageUpload(UploadedFile $uploadedFile): bool
    {
        try {
            $mimeType = (string) $uploadedFile->getMimeType();
            if ($mimeType !== '' && str_starts_with($mimeType, 'image/')) {
                return true;
            }
        } catch (\Throwable) {
        }

        $clientMimeType = (string) $uploadedFile->getClientMimeType();
        if ($clientMimeType !== '' && str_starts_with($clientMimeType, 'image/')) {
            return true;
        }

        $clientExtension = strtolower((string) $uploadedFile->getClientOriginalExtension());
        return in_array($clientExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    private function guessUploadedImageExtension(UploadedFile $uploadedFile): string
    {
        try {
            $guessed = strtolower((string) $uploadedFile->guessExtension());
            if ($guessed !== '') {
                return $guessed;
            }
        } catch (\Throwable) {
        }

        $clientExtension = strtolower((string) $uploadedFile->getClientOriginalExtension());
        if ($clientExtension !== '') {
            return $clientExtension;
        }

        return 'jpg';
    }

    #[Route('/admin/users/create', name: 'app_admin_user_create', methods: ['POST'])]
    public function createUser(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordHasherInterface $userPasswordHasher,
        SluggerInterface $slugger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $fullName = trim((string) $request->request->get('fullName', ''));
        $email = trim((string) $request->request->get('email', ''));
        $phoneNumber = trim((string) $request->request->get('phoneNumber', ''));
        $roleValue = strtoupper((string) $request->request->get('role', RoleEnum::PARTICIPANT->value));
        $tfaValue = strtoupper((string) $request->request->get('tfaMethod', TFAMethod::NONE->value));
        $password = (string) $request->request->get('password', '');

        $errors = $this->validateUserInput($fullName, $email, $phoneNumber, $roleValue, $tfaValue, $password, null, $userRepository);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_admin_dashboard');
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setPhoneNumber($phoneNumber === '' ? null : $phoneNumber);
        $user->setRole(RoleEnum::from($roleValue));
        $user->setTfaMethod(TFAMethod::from($tfaValue));
        $user->setIsActive(true);
        $user->setPassword($userPasswordHasher->hashPassword($user, $password));

        $profilePictureFile = $request->files->get('profilePicture');
        if ($profilePictureFile instanceof UploadedFile) {
            $profilePicture = $this->uploadProfilePicture($profilePictureFile, $slugger);
            if ($profilePicture !== null) {
                $user->setProfilePicture($profilePicture);
            }
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'User created successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/update', name: 'app_admin_user_update', methods: ['POST'])]
    public function updateUser(
        User $target,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordHasherInterface $userPasswordHasher,
        SluggerInterface $slugger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_update_' . $target->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $fullName = trim((string) $request->request->get('fullName', ''));
        $email = trim((string) $request->request->get('email', ''));
        $phoneNumber = trim((string) $request->request->get('phoneNumber', ''));
        $roleValue = strtoupper((string) $request->request->get('role', $target->getRole()->value));
        $tfaValue = strtoupper((string) $request->request->get('tfaMethod', $target->getTfaMethod()->value));
        $newPassword = (string) $request->request->get('password', '');

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $target->getId() && $roleValue !== RoleEnum::ADMIN->value) {
            $this->addFlash('error', 'You cannot change your own role from admin.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $errors = $this->validateUserInput($fullName, $email, $phoneNumber, $roleValue, $tfaValue, $newPassword, $target, $userRepository);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_admin_dashboard');
        }

        $target->setFullName($fullName);
        $target->setEmail($email);
        $target->setPhoneNumber($phoneNumber === '' ? null : $phoneNumber);
        $target->setRole(RoleEnum::from($roleValue));
        $target->setTfaMethod(TFAMethod::from($tfaValue));

        if ($newPassword !== '') {
            $target->setPassword($userPasswordHasher->hashPassword($target, $newPassword));
        }

        $profilePictureFile = $request->files->get('profilePicture');
        if ($profilePictureFile instanceof UploadedFile) {
            $profilePicture = $this->uploadProfilePicture($profilePictureFile, $slugger);
            if ($profilePicture !== null) {
                $target->setProfilePicture($profilePicture);
            }
        }

        $entityManager->flush();

        $this->addFlash('success', 'User updated successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/toggle-ban', name: 'app_admin_user_toggle_ban', methods: ['POST'])]
    public function toggleBan(User $target, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_ban_' . $target->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $target->getId()) {
            $this->addFlash('error', 'You cannot ban your own account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if ($target->getRole() === RoleEnum::ADMIN) {
            $this->addFlash('error', 'You cannot ban another admin account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $target->setIsActive(!$target->isIsActive());
        $entityManager->flush();

        $this->addFlash('success', $target->isIsActive() ? 'User unbanned successfully.' : 'User banned successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $target, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_delete_' . $target->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $target->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if ($target->getRole() === RoleEnum::ADMIN) {
            $this->addFlash('error', 'You cannot delete another admin account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $entityManager->remove($target);
        $entityManager->flush();

        $this->addFlash('success', 'User deleted successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    /**
     * @return string[]
     */
    private function validateUserInput(
        string $fullName,
        string $email,
        string $phoneNumber,
        string $roleValue,
        string $tfaValue,
        string $password,
        ?User $currentTarget,
        UserRepository $userRepository
    ): array {
        $errors = [];

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        } elseif (mb_strlen($fullName) < 2) {
            $errors[] = 'Full name must be at least 2 characters.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        if ($phoneNumber !== '' && !preg_match('/^\+?[0-9\s\-]{8,20}$/', $phoneNumber)) {
            $errors[] = 'Phone number format is invalid.';
        }

        if (!in_array($roleValue, array_map(static fn (RoleEnum $item) => $item->value, RoleEnum::cases()), true)) {
            $errors[] = 'Invalid role selected.';
        }

        if (!in_array($tfaValue, array_map(static fn (TFAMethod $item) => $item->value, TFAMethod::cases()), true)) {
            $errors[] = 'Invalid 2FA method selected.';
        }

        $existingUser = $userRepository->findOneBy(['email' => $email]);
        if ($existingUser !== null && ($currentTarget === null || $existingUser->getId() !== $currentTarget->getId())) {
            $errors[] = 'Email is already used by another user.';
        }

        if ($currentTarget === null && $password === '') {
            $errors[] = 'Password is required for new users.';
        }

        if ($password !== '') {
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
                $errors[] = 'Password must contain uppercase, lowercase, and a number.';
            }
        }

        return $errors;
    }

    /**
     * @return array{title:string,description:string,type:string,category:string,image:string,userId:string,price:float,quantity:int}
     */
    private function normalizeMarketplaceProductPayload(Request $request): array
    {
        return [
            'title' => trim((string) $request->request->get('title', '')),
            'description' => trim((string) $request->request->get('description', '')),
            'type' => trim((string) $request->request->get('type', '')),
            'category' => trim((string) $request->request->get('category', '')),
            'image' => trim((string) $request->request->get('image', '')),
            'userId' => trim((string) $request->request->get('user_id', '')),
            'price' => (float) $request->request->get('price', 0),
            'quantity' => (int) $request->request->get('quantity', 0),
        ];
    }

    /**
     * @param array{title:string,description:string,type:string,category:string,image:string,userId:string,price:float,quantity:int} $data
     * @return string[]
     */
    private function validateMarketplaceProductPayload(array $data, bool $isUpdate, ?int $reservedQuantity): array
    {
        $errors = [];

        if ($data['title'] === '') {
            $errors[] = 'Product title is required.';
        }
        if ($data['description'] === '') {
            $errors[] = 'Product description is required.';
        }
        if (!in_array($data['type'], ['For Sale', 'For Rent'], true)) {
            $errors[] = 'Invalid product type.';
        }
        if ($data['category'] === '') {
            $errors[] = 'Product category is required.';
        }
        if ($data['price'] <= 0) {
            $errors[] = 'Product price must be greater than 0.';
        }
        if ($data['quantity'] < 0) {
            $errors[] = 'Product quantity must be 0 or greater.';
        }
        if ($data['userId'] === '') {
            $errors[] = 'Please choose a seller.';
        }

        if ($isUpdate && $reservedQuantity !== null && $data['quantity'] < $reservedQuantity) {
            $errors[] = sprintf('Quantity cannot be less than reserved quantity (%d).', $reservedQuantity);
        }

        return $errors;
    }

    private function uploadProfilePicture(UploadedFile $profilePictureFile, SluggerInterface $slugger): ?string
    {
        $originalFilename = pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename);

        try {
            $extension = $profilePictureFile->guessExtension();
        } catch (\Throwable) {
            $extension = null;
        }

        if (!$extension) {
            $extension = strtolower(pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_EXTENSION));
        }

        if (!$extension) {
            $extension = 'bin';
        }

        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        try {
            $profilePictureFile->move(
                $this->getParameter('kernel.project_dir') . '/public/uploads/profiles',
                $newFilename
            );
        } catch (FileException) {
            return null;
        }

        return $newFilename;
    }
}
