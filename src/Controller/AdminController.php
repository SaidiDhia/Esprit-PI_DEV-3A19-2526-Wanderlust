<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Place;
use App\Entity\PlaceImage;
use App\Entity\User;
use App\Enum\RoleEnum;
use App\Enum\TFAMethod;
use App\Repository\UserRepository;
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
    #[Route('/admin/dashboard', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $section = (string) $request->query->get('section', 'users');
        if (!in_array($section, ['users', 'messaging', 'booking'], true)) {
            $section = 'users';
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

        $recentActivity = $conn->executeQuery("\n            SELECT m.*, u.full_name as sender_name, c.name as conversation_name\n            FROM message m\n            JOIN users u ON m.sender_id = u.id\n            JOIN conversation c ON m.conversation_id = c.id\n            ORDER BY m.created_at DESC\n            LIMIT 10\n        ")->fetchAllAssociative();

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
            'recentActivity' => $recentActivity,
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
        ]);
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

        if ($capacity > 0 && $maxGuests > $capacity) {
            $errors[] = 'Max guests cannot exceed capacity.';
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
