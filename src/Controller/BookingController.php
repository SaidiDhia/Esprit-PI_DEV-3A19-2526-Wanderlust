<?php

namespace App\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\Booking;
use App\Entity\Place;
use App\Entity\PlaceImage;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\RoleEnum;
use App\Service\AiReviewService;
use App\Service\ActivityLogger;
use App\Service\ContentModerationService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/booking')]
class BookingController extends AbstractController
{
    private const DEFAULT_LATITUDE = '36.80650000';
    private const DEFAULT_LONGITUDE = '10.18150000';
    private const TUNISIA_SOUTH = 30.1;
    private const TUNISIA_NORTH = 37.6;
    private const TUNISIA_WEST = 7.4;
    private const TUNISIA_EAST = 11.8;

    public function __construct(
        private readonly ContentModerationService $contentModerationService,
        private readonly string $locationApiUrl = ''
    )
    {
    }

    #[Route('', name: 'app_booking')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $city = trim((string) $request->query->get('city', ''));
        $category = trim((string) $request->query->get('category', ''));
        $distanceInput = trim((string) $request->query->get('distance', ''));
        $userLatInput = trim((string) $request->query->get('user_lat', ''));
        $userLngInput = trim((string) $request->query->get('user_lng', ''));

        $distanceKm = null;
        if ($distanceInput !== '' && is_numeric($distanceInput)) {
            $distanceKm = (float) $distanceInput;
            if ($distanceKm <= 0) {
                $distanceKm = null;
            }
        }

        $hasUserCoordinates = is_numeric($userLatInput) && is_numeric($userLngInput);
        $userLatitude = $hasUserCoordinates ? (float) $userLatInput : null;
        $userLongitude = $hasUserCoordinates ? (float) $userLngInput : null;

        $qb = $em->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->andWhere('p.status = :status')
            ->setParameter('status', Place::STATUS_APPROVED)
            ->orderBy('p.createdAt', 'DESC');

        if ($search !== '') {
            $qb->andWhere('LOWER(p.title) LIKE :search OR LOWER(p.description) LIKE :search OR LOWER(COALESCE(p.category, \'\')) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        if ($city !== '') {
            $qb->andWhere('LOWER(p.city) = :city')
                ->setParameter('city', mb_strtolower($city));
        }

        if ($category !== '') {
            $qb->andWhere('LOWER(COALESCE(p.category, \'\')) LIKE :category')
                ->setParameter('category', '%' . mb_strtolower($category) . '%');
        }

        $places = $qb->getQuery()->getResult();
        $distanceByPlaceIdMeters = [];
        if ($distanceKm !== null && $hasUserCoordinates && $userLatitude !== null && $userLongitude !== null) {
            $distanceByPlaceIdMeters = $this->fetchOsrmDistancesFromOrigin($userLatitude, $userLongitude, $places);
            $maxDistanceMeters = $distanceKm * 1000;

            $places = array_values(array_filter($places, function ($place) use ($distanceByPlaceIdMeters, $maxDistanceMeters): bool {
                if (!$place instanceof Place || $place->getId() === null) {
                    return false;
                }

                $distanceMeters = $distanceByPlaceIdMeters[$place->getId()] ?? null;
                return is_numeric($distanceMeters) && (float) $distanceMeters <= $maxDistanceMeters;
            }));

            $visiblePlaceIds = [];
            foreach ($places as $place) {
                if ($place instanceof Place && $place->getId() !== null) {
                    $visiblePlaceIds[(int) $place->getId()] = true;
                }
            }

            $distanceByPlaceIdMeters = array_filter(
                $distanceByPlaceIdMeters,
                static fn (string|int $placeId): bool => isset($visiblePlaceIds[(int) $placeId]),
                ARRAY_FILTER_USE_KEY
            );
        }

        $placePreviewImages = $this->buildPlacePreviewImagesMap($em, $places);

        $mapPlaces = [];
        $distanceByPlaceIdKm = [];
        foreach ($places as $place) {
            if (!$place instanceof Place) {
                continue;
            }

            $latitude = $place->getLatitude();
            $longitude = $place->getLongitude();
            if ($latitude === null || $longitude === null || !is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }

            $placeId = $place->getId();
            if ($placeId === null) {
                continue;
            }

            $distanceMeters = $distanceByPlaceIdMeters[$placeId] ?? null;
            if (is_numeric($distanceMeters)) {
                $distanceByPlaceIdKm[$placeId] = round(((float) $distanceMeters) / 1000, 2);
            }

            $previewImage = $placePreviewImages[$placeId] ?? $place->getImageUrl();
            $imageUrl = null;
            if (is_string($previewImage) && $previewImage !== '') {
                $imageUrl = str_starts_with($previewImage, 'http')
                    ? $previewImage
                    : $request->getBasePath() . '/uploads/places/' . ltrim($previewImage, '/');
            }

            $mapPlaces[] = [
                'id' => $placeId,
                'title' => $place->getTitle(),
                'city' => $place->getCity(),
                'category' => $place->getCategory(),
                'pricePerDay' => (float) $place->getPricePerDay(),
                'avgRating' => $place->getAvgRating() !== null ? (float) $place->getAvgRating() : null,
                'reviewsCount' => $place->getReviewsCount(),
                'imageUrl' => $imageUrl,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'distanceKm' => $distanceByPlaceIdKm[$placeId] ?? null,
                'url' => $this->generateUrl('app_booking_place', ['id' => $placeId]),
            ];
        }

        $myPendingPlaces = [];
        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $myPendingPlaces = $em->createQueryBuilder()
                ->select('p')
                ->from(Place::class, 'p')
                ->where('p.host = :user')
                ->andWhere('p.status = :status')
                ->setParameter('user', $currentUser)
                ->setParameter('status', Place::STATUS_PENDING)
                ->orderBy('p.createdAt', 'DESC')
                ->setMaxResults(5)
                ->getQuery()
                ->getResult();
        }

        $stats = [
            'places' => (int) $em->createQueryBuilder()->select('COUNT(p.id)')->from(Place::class, 'p')->getQuery()->getSingleScalarResult(),
            'bookings' => (int) $em->createQueryBuilder()->select('COUNT(b.id)')->from(Booking::class, 'b')->getQuery()->getSingleScalarResult(),
            'hosts' => (int) $em->createQueryBuilder()->select('COUNT(DISTINCT h.id)')->from(Place::class, 'p')->join('p.host', 'h')->getQuery()->getSingleScalarResult(),
            'reviews' => (int) $em->createQueryBuilder()->select('COUNT(r.id)')->from(Review::class, 'r')->getQuery()->getSingleScalarResult(),
        ];

        return $this->render('booking/index.html.twig', [
            'places' => $places,
            'placePreviewImages' => $placePreviewImages,
            'myPendingPlaces' => $myPendingPlaces,
            'mapPlaces' => $mapPlaces,
            'stats' => $stats,
            'filters' => [
                'q' => $search,
                'city' => $city,
                'category' => $category,
                'distance' => $distanceInput,
                'user_lat' => $userLatInput,
                'user_lng' => $userLngInput,
            ],
            'distanceByPlaceIdKm' => $distanceByPlaceIdKm,
            'distanceFilterActive' => $distanceKm !== null,
        ]);
    }

    #[Route('/place/{id}', name: 'app_booking_place', methods: ['GET'])]
    public function place(int $id, EntityManagerInterface $em): Response
    {
        $place = $em->find(Place::class, $id);
        if (!$place instanceof Place) {
            throw $this->createNotFoundException('Place not found.');
        }

        return $this->render('booking/place.html.twig', $this->buildPlacePageData($em, $place));
    }

    #[Route('/place/{id}/book', name: 'app_booking_book', methods: ['POST'])]
    public function book(int $id, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $user = $this->requireUser();
        $place = $em->find(Place::class, $id);
        if (!$place instanceof Place || !$place->isApproved()) {
            $this->addFlash('error', 'This place is not available for booking.');
            return $this->redirectToRoute('app_booking');
        }

        $bookingFormData = [
            'start_date' => trim((string) $request->request->get('start_date', '')),
            'end_date' => trim((string) $request->request->get('end_date', '')),
            'guests_count' => trim((string) $request->request->get('guests_count', '1')),
        ];
        $bookingFormErrors = [];

        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $bookingFormData['start_date']) ?: null;
        if ($bookingFormData['start_date'] === '') {
            $bookingFormErrors['start_date'] = 'Start date is required.';
        } elseif (!$start instanceof \DateTimeImmutable) {
            $bookingFormErrors['start_date'] = 'Start date is invalid.';
        }

        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $bookingFormData['end_date']) ?: null;
        if ($bookingFormData['end_date'] === '') {
            $bookingFormErrors['end_date'] = 'End date is required.';
        } elseif (!$end instanceof \DateTimeImmutable) {
            $bookingFormErrors['end_date'] = 'End date is invalid.';
        }

        $guests = (int) $bookingFormData['guests_count'];
        if ($bookingFormData['guests_count'] === '' || $guests < 1) {
            $bookingFormErrors['guests_count'] = 'Guests must be at least 1.';
        }

        if ($start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable && $start >= $end) {
            $bookingFormErrors['end_date'] = 'End date must be after start date.';
        }

        $today = new \DateTimeImmutable('today');
        if ($start instanceof \DateTimeImmutable && $start < $today) {
            $bookingFormErrors['start_date'] = 'Start date cannot be before today.';
        }

        if ($end instanceof \DateTimeImmutable && $end <= $today) {
            $bookingFormErrors['end_date'] = 'End date must be after today.';
        }

        if (!isset($bookingFormErrors['guests_count']) && $guests > $place->getMaxGuests()) {
            $bookingFormErrors['guests_count'] = 'Guests exceed the place limit.';
        }

        if (
            empty($bookingFormErrors)
            && $start instanceof \DateTimeImmutable
            && $end instanceof \DateTimeImmutable
            && !$this->isPlaceAvailable($em, $place, $start, $end)
        ) {
            $bookingFormErrors['_global'] = 'Those dates are already reserved.';
        }

        if (!empty($bookingFormErrors)) {
            return $this->render('booking/place.html.twig', [
                ...$this->buildPlacePageData($em, $place),
                'bookingFormErrors' => $bookingFormErrors,
                'bookingFormData' => $bookingFormData,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $nights = (int) $start->diff($end)->days;
        $booking = new Booking();
        $booking->setPlace($place);
        $booking->setGuest($user);
        $booking->setStartDate($start);
        $booking->setEndDate($end);
        $booking->setGuestsCount($guests);
        $booking->setStatus(Booking::STATUS_PENDING);
        $booking->setTotalPrice((string) ((float) $place->getPricePerDay() * $nights));

        $em->persist($booking);
        $em->flush();

        $activityLogger->logAction($user, 'booking', 'booking_request_created', [
            'targetType' => 'booking',
            'targetId' => $booking->getId(),
            'targetName' => $place->getTitle(),
            'targetImage' => $place->getImageUrl(),
            'destination' => sprintf('Place #%d', (int) $place->getId()),
            'metadata' => [
                'start_date' => $start?->format('Y-m-d'),
                'end_date' => $end?->format('Y-m-d'),
                'guests' => $guests,
                'total_price' => $booking->getTotalPrice(),
            ],
        ]);

        $this->addFlash('success', 'Booking request sent. Waiting for confirmation.');
        return $this->redirectToRoute('app_booking_my_bookings');
    }

    #[Route('/place/{id}/review', name: 'app_booking_review', methods: ['POST'])]
    public function submitReview(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AiReviewService $aiReviewService,
        ActivityLogger $activityLogger,
        EmailService $emailService
    ): Response
    {
        $user = $this->requireUser();
        $place = $em->find(Place::class, $id);
        if (!$place instanceof Place || !$place->isApproved()) {
            $this->addFlash('error', 'This place is not available for reviews.');
            return $this->redirectToRoute('app_booking');
        }

        $reviewFormData = [
            'rating' => trim((string) $request->request->get('rating', '5')),
            'comment' => trim((string) $request->request->get('comment', '')),
        ];
        $reviewFormErrors = [];

        $rating = (int) $reviewFormData['rating'];
        if ($reviewFormData['rating'] === '' || $rating < 1 || $rating > 5) {
            $reviewFormErrors['rating'] = 'Rating must be between 1 and 5.';
        }

        if ($reviewFormData['comment'] !== '' && mb_strlen($reviewFormData['comment']) > 1200) {
            $reviewFormErrors['comment'] = 'Comment is too long (max 1200 characters).';
        }

        if (!empty($reviewFormErrors)) {
            return $this->render('booking/place.html.twig', [
                ...$this->buildPlacePageData($em, $place),
                'reviewFormErrors' => $reviewFormErrors,
                'reviewFormData' => $reviewFormData,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $existingReviews = $em->createQueryBuilder()
            ->select('r')
            ->from(Review::class, 'r')
            ->where('r.place = :place')
            ->andWhere('r.user = :user')
            ->setParameter('place', $place)
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $existingReview = $existingReviews[0] ?? null;
        if (!$existingReview instanceof Review) {
            $existingReview = null;
        }

        if (count($existingReviews) > 1) {
            foreach (array_slice($existingReviews, 1) as $duplicateReview) {
                if ($duplicateReview instanceof Review) {
                    $em->remove($duplicateReview);
                }
            }
        }

        $review = $existingReview instanceof Review ? $existingReview : new Review();
        if (!$existingReview instanceof Review) {
            $review->setPlace($place);
            $review->setUser($user);
            $em->persist($review);
        }

        $review->setRating($rating);
        $review->setComment($reviewFormData['comment'] !== '' ? $reviewFormData['comment'] : null);

        $moderation = [
            'severity' => 'none',
            'reason' => 'No moderation needed',
            'should_hide' => false,
            'display_text' => $review->getComment() ?? '',
        ];
        if (($review->getComment() ?? '') !== '') {
            $moderation = $this->contentModerationService->moderateForDisplay((string) $review->getComment());
        }

        $aiResult = $aiReviewService->analyzeReview($review->getComment(), $rating);
        if ($aiResult->getSummary() !== '') {
            $review->setSentiment($aiResult->getSentiment());
            $review->setAiSummary($aiResult->getSummary());
            $review->setLanguageQuality($aiResult->getLanguageQuality());
        }

        $this->recalculatePlaceRatingStats($em, $place);
        $em->flush();

        $activityLogger->logAction($user, 'booking', $existingReview instanceof Review ? 'review_updated' : 'review_created', [
            'targetType' => 'place_review',
            'targetId' => $review->getId(),
            'targetName' => $place->getTitle(),
            'targetImage' => $place->getImageUrl(),
            'content' => $review->getComment() ?: sprintf('Rated %s with %d/5 stars.', $place->getTitle(), $rating),
            'metadata' => [
                'rating' => $rating,
                'sentiment' => $review->getSentiment(),
                'moderation_severity' => (string) ($moderation['severity'] ?? 'none'),
            ],
        ]);

        if (($moderation['severity'] ?? 'none') === 'severe' && ($review->getComment() ?? '') !== '') {
            $activityLogger->logAction($user, 'moderation', 'high_risk_content_hidden', [
                'targetType' => 'place_review',
                'targetId' => $review->getId(),
                'targetName' => $place->getTitle(),
                'targetImage' => $place->getImageUrl(),
                'content' => $review->getComment(),
                'destination' => '/admin/dashboard?section=activity_logs',
                'metadata' => [
                    'source' => 'booking_review',
                    'moderation_severity' => 'severe',
                    'moderation_reason' => (string) ($moderation['reason'] ?? 'High-risk content'),
                ],
            ]);

            $emailService->sendContentModerationWarning($user, 'review comment', (string) $review->getComment());
            $this->addFlash('error', 'Your review comment was saved but removed from public display due to policy violation. A warning email was sent and admins were informed.');
            return $this->redirectToRoute('app_booking_place', ['id' => $place->getId()]);
        }

        $this->addFlash('success', $existingReview instanceof Review ? 'Review updated successfully.' : 'Review submitted successfully.');
        return $this->redirectToRoute('app_booking_place', ['id' => $place->getId()]);
    }

    #[Route('/my-bookings', name: 'app_booking_my_bookings')]
    public function myBookings(EntityManagerInterface $em): Response
    {
        $user = $this->requireUser();

        $bookings = $em->createQueryBuilder()
            ->select('b', 'p', 'pi')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->leftJoin('p.images', 'pi')
            ->where('b.guest = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $visitedPlaces = [];
        foreach ($bookings as $booking) {
            if (!$booking instanceof Booking || !$booking->getPlace() instanceof Place) {
                continue;
            }

            $place = $booking->getPlace();
            $placeId = $place->getId();
            if ($placeId === null || isset($visitedPlaces[$placeId])) {
                continue;
            }

            $visitedPlaces[$placeId] = $place;
        }

        return $this->render('booking/my_bookings.html.twig', [
            'bookings' => $bookings,
            'visitedPlaces' => array_values($visitedPlaces),
        ]);
    }

    #[Route('/my-bookings/{id}/route-data', name: 'app_booking_my_booking_route_data', methods: ['POST'])]
    public function myBookingRouteData(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requireUser();

        $booking = $em->createQueryBuilder()
            ->select('b', 'p')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->where('b.id = :id')
            ->andWhere('b.guest = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$booking instanceof Booking || !$booking->getPlace() instanceof Place) {
            return new JsonResponse(['error' => 'Booking not found.'], Response::HTTP_NOT_FOUND);
        }

        $placeLat = $booking->getPlace()->getLatitude();
        $placeLng = $booking->getPlace()->getLongitude();
        if ($placeLat === null || $placeLng === null || !is_numeric($placeLat) || !is_numeric($placeLng)) {
            return new JsonResponse(['error' => 'Place coordinates are not available.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid request payload.'], Response::HTTP_BAD_REQUEST);
        }

        $userLat = $payload['userLat'] ?? null;
        $userLng = $payload['userLng'] ?? null;
        if (!is_numeric($userLat) || !is_numeric($userLng)) {
            return new JsonResponse(['error' => 'Your coordinates are invalid.'], Response::HTTP_BAD_REQUEST);
        }

        $userLat = (float) $userLat;
        $userLng = (float) $userLng;
        $placeLatValue = (float) $placeLat;
        $placeLngValue = (float) $placeLng;

        // Tunisia bounds to keep route logic aligned with Tunisia-only mapping setup.
        if (
            $userLat < 30.1 || $userLat > 37.6 || $userLng < 7.4 || $userLng > 11.8
            || $placeLatValue < 30.1 || $placeLatValue > 37.6 || $placeLngValue < 7.4 || $placeLngValue > 11.8
        ) {
            return new JsonResponse([
                'error' => 'Route is available only when both points are in Tunisia.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $osrmUrl = sprintf(
            'http://127.0.0.1:5001/route/v1/driving/%s,%s;%s,%s?overview=full&geometries=geojson&steps=true',
            rawurlencode((string) $userLng),
            rawurlencode((string) $userLat),
            rawurlencode((string) $placeLngValue),
            rawurlencode((string) $placeLatValue)
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $response = @file_get_contents($osrmUrl, false, $context);
        if (!is_string($response) || $response === '') {
            return new JsonResponse(['error' => 'Unable to reach OSRM service.'], Response::HTTP_BAD_GATEWAY);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['code'] ?? '') !== 'Ok' || !isset($decoded['routes'][0])) {
            return new JsonResponse(['error' => 'No route found.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $route = $decoded['routes'][0];

        return new JsonResponse([
            'route' => [
                'distance' => (float) ($route['distance'] ?? 0),
                'duration' => (float) ($route['duration'] ?? 0),
                'geometry' => $route['geometry'] ?? null,
            ],
            'place' => [
                'latitude' => $placeLatValue,
                'longitude' => $placeLngValue,
                'title' => $booking->getPlace()->getTitle(),
            ],
        ]);
    }

    #[Route('/my-bookings/{id}/invoice', name: 'app_booking_my_booking_invoice_pdf', methods: ['GET'])]
    public function exportMyBookingInvoicePdf(int $id, EntityManagerInterface $em): Response
    {
        $user = $this->requireUser();
        $booking = $em->createQueryBuilder()
            ->select('b', 'p', 'g')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->join('b.guest', 'g')
            ->where('b.id = :id')
            ->andWhere('b.guest = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$booking instanceof Booking) {
            throw $this->createNotFoundException('Booking not found.');
        }

        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            $this->addFlash('error', 'Invoice export is available only for confirmed bookings.');
            return $this->redirectToRoute('app_booking_my_bookings');
        }

        $issuedAt = new \DateTimeImmutable();
        $nights = max(1, (int) $booking->getStartDate()->diff($booking->getEndDate())->days);
        $invoiceNumber = sprintf('RES-%s-%04d', $issuedAt->format('Ymd'), (int) $booking->getId());

        $response = $this->withSuppressedIconvNotice(function () use ($booking, $issuedAt, $invoiceNumber, $nights): Response {
            $html = $this->renderView('booking/invoice_pdf.html.twig', [
                'booking' => $booking,
                'issuedAt' => $issuedAt,
                'invoiceNumber' => $invoiceNumber,
                'nights' => $nights,
            ]);

            // Some legacy DB rows may contain invalid byte sequences; Dompdf uses iconv internally.
            // Scrub the rendered HTML to valid UTF-8 before Dompdf processing.
            $html = $this->scrubUtf8($html);

            $options = new Options();
            $options->set('isRemoteEnabled', true);
            $options->setDefaultFont('DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return new Response($dompdf->output());
        });

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="reservation-invoice-%d.pdf"', (int) $booking->getId())
        );

        return $response;
    }

    #[Route('/my-bookings/{id}/calendar', name: 'app_booking_my_booking_google_calendar', methods: ['GET'])]
    public function addMyBookingToGoogleCalendar(int $id, EntityManagerInterface $em): Response
    {
        $user = $this->requireUser();
        $booking = $em->createQueryBuilder()
            ->select('b', 'p')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->where('b.id = :id')
            ->andWhere('b.guest = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$booking instanceof Booking) {
            throw $this->createNotFoundException('Booking not found.');
        }

        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            $this->addFlash('error', 'Google Calendar planning is available only for confirmed bookings.');
            return $this->redirectToRoute('app_booking_my_bookings');
        }

        $calendarUrl = $this->buildGoogleCalendarUrl($booking);
        return new RedirectResponse($calendarUrl);
    }

    #[Route('/my-bookings/{id}/edit', name: 'app_booking_my_booking_edit', methods: ['POST'])]
    public function editMyBooking(int $id, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $user = $this->requireUser();
        $booking = $em->createQueryBuilder()
            ->select('b', 'p')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->where('b.id = :id')
            ->andWhere('b.guest = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$booking instanceof Booking) {
            throw $this->createNotFoundException('Booking not found.');
        }

        if ($booking->getStatus() !== Booking::STATUS_PENDING) {
            $this->addFlash('error', 'Only pending bookings can be edited.');
            return $this->redirectToRoute('app_booking_my_bookings');
        }

        if (!$this->isCsrfTokenValid('booking_edit_' . $booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid booking token. Please try again.');
            return $this->redirectToRoute('app_booking_my_bookings');
        }

        $bookingFormData = [
            'start_date' => trim((string) $request->request->get('start_date', '')),
            'end_date' => trim((string) $request->request->get('end_date', '')),
        ];
        $bookingFormErrors = [];

        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $bookingFormData['start_date']) ?: null;
        if ($bookingFormData['start_date'] === '') {
            $bookingFormErrors['start_date'] = 'Start date is required.';
        } elseif (!$start instanceof \DateTimeImmutable) {
            $bookingFormErrors['start_date'] = 'Start date is invalid.';
        }

        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $bookingFormData['end_date']) ?: null;
        if ($bookingFormData['end_date'] === '') {
            $bookingFormErrors['end_date'] = 'End date is required.';
        } elseif (!$end instanceof \DateTimeImmutable) {
            $bookingFormErrors['end_date'] = 'End date is invalid.';
        }

        $today = new \DateTimeImmutable('today');
        if ($start instanceof \DateTimeImmutable && $start < $today) {
            $bookingFormErrors['start_date'] = 'Start date cannot be before today.';
        }

        if ($end instanceof \DateTimeImmutable && $end <= $today) {
            $bookingFormErrors['end_date'] = 'End date must be after today.';
        }

        if ($start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable && $start >= $end) {
            $bookingFormErrors['end_date'] = 'End date must be after start date.';
        }

        $place = $booking->getPlace();
        if ($start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable && !$this->isPlaceAvailable($em, $place, $start, $end, $booking->getId())) {
            $bookingFormErrors['_global'] = 'Those dates are already reserved.';
        }

        if (!empty($bookingFormErrors)) {
            $this->addFlash('error', implode(' ', $bookingFormErrors));
            return $this->redirectToRoute('app_booking_my_bookings');
        }

        $booking->setStartDate($start);
        $booking->setEndDate($end);

        $nights = (int) $start->diff($end)->days;
        $booking->setTotalPrice((string) ((float) $place->getPricePerDay() * $nights));
        $em->flush();

        $activityLogger->logAction($user, 'booking', 'booking_request_updated', [
            'targetType' => 'booking',
            'targetId' => $booking->getId(),
            'targetName' => $place->getTitle(),
            'targetImage' => $place->getImageUrl(),
            'metadata' => [
                'start_date' => $booking->getStartDate()->format('Y-m-d'),
                'end_date' => $booking->getEndDate()->format('Y-m-d'),
                'total_price' => $booking->getTotalPrice(),
            ],
        ]);

        $this->addFlash('success', 'Booking dates updated successfully.');
        return $this->redirectToRoute('app_booking_my_bookings');
    }

    #[Route('/my-bookings/{id}/cancel', name: 'app_booking_my_booking_cancel', methods: ['POST'])]
    public function cancelMyBooking(int $id, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $user = $this->requireUser();
        $booking = $em->createQueryBuilder()
            ->select('b', 'p')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->where('b.id = :id')
            ->andWhere('b.guest = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$booking instanceof Booking) {
            throw $this->createNotFoundException('Booking not found.');
        }

        if (!$this->isCsrfTokenValid('booking_cancel_' . $booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid booking token. Please try again.');
            return $this->redirectToRoute('app_booking_my_bookings');
        }

        $currentStatus = $booking->getStatus();
        if (!in_array($currentStatus, [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true)) {
            $this->addFlash('error', 'Only pending or confirmed bookings can be cancelled.');
            return $this->redirectToRoute('app_booking_my_bookings');
        }

        $refundPolicy = $this->calculateCancellationRefundPolicy($booking, new \DateTimeImmutable());

        $booking->setStatus(Booking::STATUS_CANCELLED);
        $booking->setCancelledAt(new \DateTimeImmutable());
        $booking->setCancelledBy('GUEST');
        $booking->setCancelReason('Cancelled by guest');
        $booking->setRefundAmount((string) $refundPolicy['amount']);
        $em->flush();

        $activityLogger->logAction($user, 'booking', 'booking_cancelled_by_guest', [
            'targetType' => 'booking',
            'targetId' => $booking->getId(),
            'targetName' => $booking->getPlace()?->getTitle(),
            'targetImage' => $booking->getPlace()?->getImageUrl(),
            'destination' => sprintf('Place #%d', (int) ($booking->getPlace()?->getId() ?? 0)),
            'content' => sprintf(
                'Cancelled booking #%d for %s (%s to %s).',
                (int) $booking->getId(),
                (string) ($booking->getPlace()?->getTitle() ?? 'Unknown place'),
                $booking->getStartDate()->format('Y-m-d'),
                $booking->getEndDate()->format('Y-m-d')
            ),
            'metadata' => [
                'place_id' => $booking->getPlace()?->getId(),
                'place_title' => $booking->getPlace()?->getTitle(),
                'cancelled_at' => $booking->getCancelledAt()?->format('Y-m-d H:i:s'),
                'previous_status' => $currentStatus,
                'refund_percent' => $refundPolicy['percent'],
                'refund_amount' => $refundPolicy['amount'],
            ],
        ]);

        if ($currentStatus === Booking::STATUS_CONFIRMED) {
            $this->addFlash('success', sprintf(
                'Booking cancelled. Refund: %.2f%% (%.2f).',
                (float) $refundPolicy['percent'],
                (float) $refundPolicy['amount']
            ));
        } else {
            $this->addFlash('success', 'Booking cancelled. No refund is applied to non-confirmed bookings.');
        }

        return $this->redirectToRoute('app_booking_my_bookings');
    }

    #[Route('/host', name: 'app_booking_host')]
    public function hostDashboard(EntityManagerInterface $em): Response
    {
        $user = $this->requireUser();
        return $this->renderHostDashboard($em, $user, $user);
    }

    #[Route('/location/search', name: 'app_booking_location_search', methods: ['GET'])]
    public function searchLocations(Request $request): JsonResponse
    {
        $this->requireUser();

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '' || mb_strlen($query) < 2) {
            return new JsonResponse(['items' => []]);
        }

        $items = [];
        $dedupe = [];

        $coordinates = $this->extractCoordinatesFromQuery($query);
        if ($coordinates !== null) {
            $lat = $coordinates['lat'];
            $lng = $coordinates['lng'];
            if ($this->isCoordinateInTunisia($lat, $lng)) {
                $snapped = $this->snapCoordinateToOsrmRoad($lat, $lng);
                $resultLat = $snapped['lat'] ?? $lat;
                $resultLng = $snapped['lng'] ?? $lng;
                $key = number_format($resultLat, 6, '.', '') . ':' . number_format($resultLng, 6, '.', '');
                $dedupe[$key] = true;

                $items[] = [
                    'name' => sprintf('Dropped pin (%s, %s)', number_format($resultLat, 5, '.', ''), number_format($resultLng, 5, '.', '')),
                    'latitude' => number_format($resultLat, 8, '.', ''),
                    'longitude' => number_format($resultLng, 8, '.', ''),
                ];
            }
        }

        $localSuggestions = $this->fetchTunisiaLocationSuggestionsFromApi($query);
        foreach ($localSuggestions as $item) {
            $key = number_format((float) $item['latitude'], 6, '.', '') . ':' . number_format((float) $item['longitude'], 6, '.', '');
            if (isset($dedupe[$key])) {
                continue;
            }

            $items[] = $item;
            $dedupe[$key] = true;

            if (count($items) >= 8) {
                break;
            }
        }

        return new JsonResponse(['items' => array_slice($items, 0, 8)]);
    }

    private function extractCoordinatesFromQuery(string $query): ?array
    {
        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)\s*$/u', $query, $matches) !== 1) {
            return null;
        }

        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['lat' => $latitude, 'lng' => $longitude];
    }

    private function fetchTunisiaLocationSuggestionsFromApi(string $query): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $decoded = null;
        foreach ($this->getLocationApiBaseUrls() as $baseUrl) {
            $url = sprintf(
                '%s/locations/search?q=%s&limit=8&country=tn',
                rtrim($baseUrl, '/'),
                rawurlencode($term)
            );

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 4,
                    'header' => "Accept: application/json\r\n",
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            if (!is_string($response) || $response === '') {
                continue;
            }

            $candidate = json_decode($response, true);
            if (!is_array($candidate) || !isset($candidate['items']) || !is_array($candidate['items'])) {
                continue;
            }

            $decoded = $candidate;
            break;
        }

        if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
            return [];
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $latitude = (string) ($item['latitude'] ?? '');
            $longitude = (string) ($item['longitude'] ?? '');
            if ($name === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }

            $lat = (float) $latitude;
            $lng = (float) $longitude;
            if (!$this->isCoordinateInTunisia($lat, $lng)) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'latitude' => number_format($lat, 8, '.', ''),
                'longitude' => number_format($lng, 8, '.', ''),
            ];

            if (count($items) >= 8) {
                break;
            }
        }

        return $items;
    }

    private function getLocationApiBaseUrls(): array
    {
        $candidates = [
            $this->locationApiUrl,
            (string) ($_ENV['LOCATION_API_URL'] ?? ''),
            (string) getenv('LOCATION_API_URL'),
            'http://127.0.0.1:8104',
            'http://location_api:8000',
        ];

        $urls = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $normalized = rtrim($candidate, '/');
            if (in_array($normalized, $urls, true)) {
                continue;
            }

            $urls[] = $normalized;
        }

        return $urls;
    }

    #[Route('/host/{id}', name: 'app_booking_host_view', methods: ['GET'])]
    public function hostDashboardForUser(string $id, EntityManagerInterface $em): Response
    {
        $viewer = $this->requireUser();
        $hostUser = $em->find(User::class, $id);
        if (!$hostUser instanceof User || $hostUser->getRole() !== RoleEnum::HOST) {
            throw $this->createNotFoundException('Host not found.');
        }

        if (!$viewer->isAdmin() && $viewer->getId() !== $hostUser->getId()) {
            throw $this->createAccessDeniedException('You cannot view this host dashboard.');
        }

        return $this->renderHostDashboard($em, $hostUser, $viewer);
    }

    #[Route('/host/request', name: 'app_booking_host_request', methods: ['POST'])]
    public function submitPlaceRequest(Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $user = $this->requireUser();

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
        ];
        $formErrors = [];

        if ($formData['title'] === '') {
            $formErrors['title'] = 'Title is required.';
        }
        if ($formData['city'] === '') {
            $formErrors['city'] = 'City is required.';
        }
        if ($formData['address'] === '') {
            $formErrors['address'] = 'Address is required.';
        }

        $pricePerDay = (float) $formData['price_per_day'];
        if ($formData['price_per_day'] === '' || $pricePerDay <= 0) {
            $formErrors['price_per_day'] = 'Price per day must be greater than 0.';
        }

        $capacity = (int) $formData['capacity'];
        if ($formData['capacity'] === '' || $capacity < 1) {
            $formErrors['capacity'] = 'Capacity must be at least 1.';
        }

        $maxGuests = (int) $formData['max_guests'];
        if ($formData['max_guests'] === '' || $maxGuests < 1) {
            $formErrors['max_guests'] = 'Max guests must be at least 1.';
        }

        $uploadedImages = $request->files->get('images', []);
        if (!is_array($uploadedImages) || count(array_filter($uploadedImages)) === 0) {
            $formErrors['images'] = 'Please upload at least one image.';
            $uploadedImages = [];
        }

        foreach ($uploadedImages as $uploadedImage) {
            if (!$uploadedImage instanceof UploadedFile) {
                continue;
            }

            if (!$this->isImageUpload($uploadedImage)) {
                $formErrors['images'] = 'Only image files are allowed.';
                break;
            }
        }

        $latitude = $formData['latitude'] !== '' ? $formData['latitude'] : self::DEFAULT_LATITUDE;
        $longitude = $formData['longitude'] !== '' ? $formData['longitude'] : self::DEFAULT_LONGITUDE;

        if (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90) {
            $formErrors['latitude'] = 'Latitude must be between -90 and 90.';
        }

        if (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180) {
            $formErrors['longitude'] = 'Longitude must be between -180 and 180.';
        }

        if (!empty($formErrors)) {
            return $this->renderHostDashboard(
                $em,
                $user,
                $user,
                [
                    'formErrors' => $formErrors,
                    'formData' => $formData,
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $place = new Place();
        $place->setHost($user);
        $place->setTitle($formData['title']);
        $place->setDescription($formData['description'] !== '' ? $formData['description'] : null);
        $place->setPricePerDay($pricePerDay);
        $place->setCapacity($capacity);
        $place->setMaxGuests($maxGuests);
        $place->setAddress($formData['address']);
        $place->setCity($formData['city']);
        $place->setCategory($formData['category'] !== '' ? $formData['category'] : null);
        $place->setStatus(Place::STATUS_PENDING);
        $place->setLatitude((string) $latitude);
        $place->setLongitude((string) $longitude);

        $em->persist($place);
        $em->flush();

        $this->persistHostPlaceImages($em, $place, $uploadedImages);
        $em->flush();

        $activityLogger->logAction($user, 'booking', 'place_request_created', [
            'targetType' => 'place',
            'targetId' => $place->getId(),
            'targetName' => $place->getTitle(),
            'targetImage' => $place->getImageUrl(),
            'metadata' => [
                'city' => $place->getCity(),
                'category' => $place->getCategory(),
                'status' => $place->getStatus(),
                'price_per_day' => $place->getPricePerDay(),
            ],
        ]);

        $this->addFlash('success', 'Your place request was submitted for admin review.');
        return $this->redirectToRoute('app_booking_host');
    }

    #[Route('/host/places/{id}/update', name: 'app_booking_host_place_update', methods: ['POST'])]
    public function updateHostPlace(Place $place, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $viewer = $this->requireUser();
        $host = $place->getHost();
        if (!$host instanceof User || (!$viewer->isAdmin() && $host->getId() !== $viewer->getId())) {
            throw $this->createAccessDeniedException('You cannot edit this place.');
        }

        if (!$this->isCsrfTokenValid('booking_host_place_update_' . $place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid place token. Please try again.');
            return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $host->getId()]);
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
        ];

        $errors = [];
        if ($formData['title'] === '') {
            $errors[] = 'Title is required.';
        }
        if ($formData['city'] === '') {
            $errors[] = 'City is required.';
        }
        if ($formData['address'] === '') {
            $errors[] = 'Address is required.';
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

        $uploadedImages = $request->files->get('images', []);
        if (!is_array($uploadedImages)) {
            $uploadedImages = [$uploadedImages];
        }

        $uploadedImages = array_values(array_filter($uploadedImages, static fn ($uploadedImage): bool => $uploadedImage instanceof UploadedFile));
        foreach ($uploadedImages as $uploadedImage) {
            if (!$this->isImageUpload($uploadedImage)) {
                $errors[] = 'Only image files are allowed.';
                break;
            }
        }

        $latitude = $formData['latitude'] !== '' ? $formData['latitude'] : self::DEFAULT_LATITUDE;
        $longitude = $formData['longitude'] !== '' ? $formData['longitude'] : self::DEFAULT_LONGITUDE;
        if (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90) {
            $errors[] = 'Latitude must be between -90 and 90.';
        }
        if (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180) {
            $errors[] = 'Longitude must be between -180 and 180.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $host->getId()]);
        }

        $place->setTitle($formData['title']);
        $place->setDescription($formData['description'] !== '' ? $formData['description'] : null);
        $place->setCity($formData['city']);
        $place->setCategory($formData['category'] !== '' ? $formData['category'] : null);
        $place->setPricePerDay($pricePerDay);
        $place->setCapacity($capacity);
        $place->setMaxGuests($maxGuests);
        $place->setAddress($formData['address']);
        $place->setLatitude($this->normalizeDecimalString($latitude));
        $place->setLongitude($this->normalizeDecimalString($longitude));

        $this->persistHostPlaceImages($em, $place, $uploadedImages);
        $em->flush();

        $activityLogger->logAction($viewer, 'booking', 'place_updated', [
            'targetType' => 'place',
            'targetId' => $place->getId(),
            'targetName' => $place->getTitle(),
            'targetImage' => $place->getImageUrl(),
            'metadata' => [
                'city' => $place->getCity(),
                'category' => $place->getCategory(),
                'price_per_day' => $place->getPricePerDay(),
            ],
        ]);

        $this->addFlash('success', 'Place updated successfully.');
        return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $host->getId()]);
    }

    #[Route('/host/places/{id}/delete', name: 'app_booking_host_place_delete', methods: ['POST'])]
    public function deleteHostPlace(Place $place, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $viewer = $this->requireUser();
        $host = $place->getHost();
        if (!$host instanceof User || (!$viewer->isAdmin() && $host->getId() !== $viewer->getId())) {
            throw $this->createAccessDeniedException('You cannot delete this place.');
        }

        if (!$this->isCsrfTokenValid('booking_host_place_delete_' . $place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid place token. Please try again.');
            return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $host->getId()]);
        }

        $placeId = $place->getId();
        $placeTitle = $place->getTitle();
        $placeImage = $place->getImageUrl();

        $em->remove($place);
        $em->flush();

        $activityLogger->logAction($viewer, 'booking', 'place_deleted', [
            'targetType' => 'place',
            'targetId' => $placeId,
            'targetName' => $placeTitle,
            'targetImage' => $placeImage,
        ]);

        $this->addFlash('success', 'Place deleted successfully.');
        return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $host->getId()]);
    }

    private function fetchHostPlaces(EntityManagerInterface $em, User $user): array
    {
        return $em->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->where('p.host = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function fetchHostBookings(EntityManagerInterface $em, User $user): array
    {
        return $em->createQueryBuilder()
            ->select('b', 'p', 'g')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->join('b.guest', 'g')
            ->where('p.host = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function renderHostDashboard(EntityManagerInterface $em, User $hostUser, ?User $viewer = null, array $additionalContext = [], int $statusCode = Response::HTTP_OK): Response
    {
        $places = $this->fetchHostPlaces($em, $hostUser);
        $placePreviewImages = $this->buildPlacePreviewImagesMap($em, $places);
        $placeGalleryImages = $this->buildPlaceGalleryImagesMap($em, $places);
        $bookings = $this->fetchHostBookings($em, $hostUser);

        $bookingStatusCounts = [
            Booking::STATUS_PENDING => 0,
            Booking::STATUS_CONFIRMED => 0,
            Booking::STATUS_REJECTED => 0,
            Booking::STATUS_CANCELLED => 0,
            Booking::STATUS_COMPLETED => 0,
        ];
        $earningsTotal = 0.0;
        $earningsByMonth = [];
        $monthKeys = [];
        $monthCursor = new \DateTimeImmutable('first day of this month');
        for ($offset = 5; $offset >= 0; --$offset) {
            $month = $monthCursor->modify(sprintf('-%d months', $offset));
            $monthKey = $month->format('Y-m');
            $monthKeys[] = $monthKey;
            $earningsByMonth[$monthKey] = 0.0;
        }

        foreach ($bookings as $booking) {
            if (!$booking instanceof Booking) {
                continue;
            }

            $status = $booking->getStatus();
            if (isset($bookingStatusCounts[$status])) {
                ++$bookingStatusCounts[$status];
            }

            if ($status === Booking::STATUS_CONFIRMED) {
                $earningsTotal += (float) $booking->getTotalPrice();
                $monthKey = $booking->getCreatedAt()->format('Y-m');
                if (isset($earningsByMonth[$monthKey])) {
                    $earningsByMonth[$monthKey] += (float) $booking->getTotalPrice();
                }
            }
        }

        $dashboardStats = [
            'places' => count($places),
            'approvedPlaces' => count(array_filter($places, static fn (Place $place): bool => $place->isApproved())),
            'pendingPlaces' => count(array_filter($places, static fn (Place $place): bool => $place->getStatus() === Place::STATUS_PENDING)),
            'bookings' => count($bookings),
            'pendingBookings' => $bookingStatusCounts[Booking::STATUS_PENDING],
            'confirmedBookings' => $bookingStatusCounts[Booking::STATUS_CONFIRMED],
            'rejectedBookings' => $bookingStatusCounts[Booking::STATUS_REJECTED],
            'earningsTotal' => number_format($earningsTotal, 2, '.', ''),
        ];

        return $this->render('booking/host_dashboard.html.twig', array_merge([
            'hostUser' => $hostUser,
            'viewerIsAdmin' => $viewer instanceof User ? $viewer->isAdmin() : false,
            'places' => $places,
            'bookings' => $bookings,
            'placePreviewImages' => $placePreviewImages,
            'placeGalleryImages' => $placeGalleryImages,
            'pendingRequests' => $bookingStatusCounts[Booking::STATUS_PENDING],
            'dashboardStats' => $dashboardStats,
            'bookingStatusChartData' => [
                ['label' => 'Pending', 'count' => $bookingStatusCounts[Booking::STATUS_PENDING]],
                ['label' => 'Confirmed', 'count' => $bookingStatusCounts[Booking::STATUS_CONFIRMED]],
                ['label' => 'Rejected', 'count' => $bookingStatusCounts[Booking::STATUS_REJECTED]],
                ['label' => 'Cancelled', 'count' => $bookingStatusCounts[Booking::STATUS_CANCELLED]],
                ['label' => 'Completed', 'count' => $bookingStatusCounts[Booking::STATUS_COMPLETED]],
            ],
            'earningsChartData' => [
                'labels' => array_map(static fn (string $monthKey): string => (\DateTimeImmutable::createFromFormat('Y-m', $monthKey) ?: new \DateTimeImmutable())->format('M Y'), $monthKeys),
                'values' => array_map(static fn (float $value): float => round($value, 2), array_values($earningsByMonth)),
            ],
            'formErrors' => [],
            'formData' => $this->defaultHostFormData(),
        ], $additionalContext), new Response('', $statusCode));
    }

    #[Route('/host/bookings/{id}/confirm', name: 'app_booking_host_booking_confirm', methods: ['POST'])]
    public function confirmHostBooking(int $id, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $viewer = $this->requireUser();
        $booking = $em->find(Booking::class, $id);
        if (!$booking instanceof Booking || !$booking->getPlace() instanceof Place || !$booking->getPlace()->getHost() instanceof User) {
            throw $this->createNotFoundException('Booking not found.');
        }

        $hostUser = $booking->getPlace()->getHost();
        if (!$viewer->isAdmin() && $hostUser->getId() !== $viewer->getId()) {
            throw $this->createAccessDeniedException('You cannot manage this booking.');
        }

        if (!$this->isCsrfTokenValid('booking_host_confirm_' . $booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid booking token. Please try again.');
            return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_admin' : 'app_booking_host', ['id' => $hostUser->getId()]);
        }

        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $em->flush();

        $activityLogger->logAction($viewer, 'booking', 'booking_confirmed_by_host', [
            'targetType' => 'booking',
            'targetId' => $booking->getId(),
            'targetName' => $booking->getPlace()?->getTitle(),
            'targetImage' => $booking->getPlace()?->getImageUrl(),
            'destination' => $booking->getGuest()?->getFullName(),
            'content' => sprintf(
                'Approved booking request for %s from %s to %s.',
                $booking->getPlace()?->getTitle() ?? 'booking',
                $booking->getStartDate()?->format('Y-m-d') ?? 'unknown date',
                $booking->getEndDate()?->format('Y-m-d') ?? 'unknown date'
            ),
            'metadata' => [
                'status' => Booking::STATUS_CONFIRMED,
                'guest' => $booking->getGuest()?->getFullName(),
                'reason' => null,
            ],
        ]);

        $this->addFlash('success', 'Booking request approved.');
        return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_admin' : 'app_booking_host', ['id' => $hostUser->getId()]);
    }

    #[Route('/host/bookings/{id}/reject', name: 'app_booking_host_booking_reject', methods: ['POST'])]
    public function rejectHostBooking(int $id, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $viewer = $this->requireUser();
        $booking = $em->find(Booking::class, $id);
        if (!$booking instanceof Booking || !$booking->getPlace() instanceof Place || !$booking->getPlace()->getHost() instanceof User) {
            throw $this->createNotFoundException('Booking not found.');
        }

        $hostUser = $booking->getPlace()->getHost();
        if (!$viewer->isAdmin() && $hostUser->getId() !== $viewer->getId()) {
            throw $this->createAccessDeniedException('You cannot manage this booking.');
        }

        if (!$this->isCsrfTokenValid('booking_host_reject_' . $booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid booking token. Please try again.');
            return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_admin' : 'app_booking_host', ['id' => $hostUser->getId()]);
        }

        $reason = trim((string) $request->request->get('reason', ''));

        $booking->setStatus(Booking::STATUS_REJECTED);
        $em->flush();

        $activityLogger->logAction($viewer, 'booking', 'booking_rejected_by_host', [
            'targetType' => 'booking',
            'targetId' => $booking->getId(),
            'targetName' => $booking->getPlace()?->getTitle(),
            'targetImage' => $booking->getPlace()?->getImageUrl(),
            'destination' => $booking->getGuest()?->getFullName(),
            'content' => $reason !== ''
                ? $reason
                : sprintf('Rejected booking request for %s.', $booking->getPlace()?->getTitle() ?? 'booking'),
            'metadata' => [
                'status' => Booking::STATUS_REJECTED,
                'guest' => $booking->getGuest()?->getFullName(),
                'reason' => $reason !== '' ? $reason : null,
            ],
        ]);

        $this->addFlash('success', 'Booking request rejected.');
        return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_admin' : 'app_booking_host', ['id' => $hostUser->getId()]);
    }

    private function countHostPendingRequests(EntityManagerInterface $em, User $user): int
    {
        return (int) $em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->where('p.host = :user')
            ->andWhere('b.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', Booking::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function scrubUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        set_error_handler(static function (): bool {
            // Prevent iconv warnings from being converted into exceptions by Symfony.
            return true;
        });

        try {
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);
        } finally {
            restore_error_handler();
        }

        if ($clean === false) {
            return '';
        }

        return $clean;
    }

    private function withSuppressedIconvNotice(callable $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'iconv(): Detected an incomplete multibyte character')) {
                return true;
            }

            return false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private function buildGoogleCalendarUrl(Booking $booking): string
    {
        $place = $booking->getPlace();
        $title = sprintf('Wanderlust Stay - %s', $place instanceof Place ? $place->getTitle() : 'Reservation');
        $location = '';
        if ($place instanceof Place) {
            $location = trim(($place->getAddress() ?? '') . ' ' . ($place->getCity() ?? ''));
        }

        $details = sprintf(
            "Reservation #%d\nGuests: %d\nTotal: %s\nGenerated from Wanderlust.",
            (int) $booking->getId(),
            (int) $booking->getGuestsCount(),
            $booking->getTotalPrice()
        );

        $dates = sprintf(
            '%s/%s',
            $booking->getStartDate()->format('Ymd'),
            $booking->getEndDate()->format('Ymd')
        );

        $query = http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $dates,
            'details' => $details,
            'location' => $location,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://calendar.google.com/calendar/render?' . $query;
    }

    private function defaultHostFormData(): array
    {
        return [
            'title' => '',
            'description' => '',
            'city' => '',
            'category' => '',
            'price_per_day' => '',
            'capacity' => '',
            'max_guests' => '',
            'address' => '',
            'latitude' => self::DEFAULT_LATITUDE,
            'longitude' => self::DEFAULT_LONGITUDE,
        ];
    }

    private function persistHostPlaceImages(EntityManagerInterface $em, Place $place, array $uploadedImages): void
    {
        if ($uploadedImages === []) {
            return;
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/places';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $existingCount = (int) $em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(PlaceImage::class, 'i')
            ->where('i.place = :place')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();

        $sortOrder = $existingCount;
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
            $placeImage->setSortOrder($sortOrder);
            $placeImage->setIsPrimary($sortOrder === 0 && $place->getImageUrl() === null);
            $em->persist($placeImage);

            if ($place->getImageUrl() === null) {
                $place->setImageUrl($fileName);
            }

            ++$sortOrder;
        }
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

    private function buildPlacePageData(EntityManagerInterface $em, Place $place): array
    {
        $images = $em->createQueryBuilder()
            ->select('i')
            ->from(PlaceImage::class, 'i')
            ->where('i.place = :place')
            ->setParameter('place', $place)
            ->orderBy('i.isPrimary', 'DESC')
            ->addOrderBy('i.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $reviews = $em->createQueryBuilder()
            ->select('r', 'u')
            ->from(Review::class, 'r')
            ->join('r.user', 'u')
            ->where('r.place = :place')
            ->setParameter('place', $place)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $reviewCards = [];
        foreach ($reviews as $review) {
            if (!$review instanceof Review) {
                continue;
            }

            $comment = $review->getComment() ?? '';
            $moderation = $comment !== ''
                ? $this->contentModerationService->moderateForDisplay($comment)
                : [
                    'severity' => 'none',
                    'should_hide' => false,
                    'display_text' => '',
                    'reason' => 'Empty text',
                ];

            $reviewCards[] = [
                'review' => $review,
                'displayComment' => (string) ($moderation['display_text'] ?? ''),
                'hiddenByModeration' => (bool) ($moderation['should_hide'] ?? false),
                'moderationSeverity' => (string) ($moderation['severity'] ?? 'none'),
            ];
        }

        $bookings = $em->createQueryBuilder()
            ->select('b')
            ->from(Booking::class, 'b')
            ->where('b.place = :place')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('place', $place)
            ->setParameter('statuses', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->orderBy('b.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        $reviewFormData = [
            'rating' => '5',
            'comment' => '',
        ];

        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $userReview = $em->createQueryBuilder()
                ->select('r')
                ->from(Review::class, 'r')
                ->where('r.place = :place')
                ->andWhere('r.user = :user')
                ->setParameter('place', $place)
                ->setParameter('user', $currentUser)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($userReview instanceof Review) {
                $reviewFormData = [
                    'rating' => (string) $userReview->getRating(),
                    'comment' => $userReview->getComment() ?? '',
                ];
            }
        }

        return [
            'place' => $place,
            'images' => $images,
            'mainImageUrl' => $images !== [] ? $images[0]->getUrl() : $place->getImageUrl(),
            'reviews' => $reviews,
            'reviewCards' => $reviewCards,
            'bookings' => $bookings,
            'bookedRanges' => array_map(static fn (Booking $booking): array => [
                'start' => $booking->getStartDate()->format('Y-m-d'),
                'end' => $booking->getEndDate()->format('Y-m-d'),
                'status' => $booking->getStatus(),
            ], $bookings),
            'todayDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'bookingFormErrors' => [],
            'bookingFormData' => [
                'start_date' => '',
                'end_date' => '',
                'guests_count' => '1',
            ],
            'reviewFormErrors' => [],
            'reviewFormData' => $reviewFormData,
        ];
    }

    private function recalculatePlaceRatingStats(EntityManagerInterface $em, Place $place): void
    {
        $stats = $em->createQueryBuilder()
            ->select('COUNT(r.id) AS reviewsCount', 'AVG(r.rating) AS avgRating')
            ->from(Review::class, 'r')
            ->where('r.place = :place')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleResult();

        $reviewsCount = (int) ($stats['reviewsCount'] ?? 0);
        $avgRating = $stats['avgRating'] !== null ? number_format((float) $stats['avgRating'], 2, '.', '') : null;

        $place->setReviewsCount($reviewsCount);
        $place->setAvgRating($avgRating);
    }

    #[Route('/admin', name: 'app_booking_admin')]
    public function adminDashboard(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $stats = [
            'totalPlaces' => (int) $em->createQueryBuilder()->select('COUNT(p.id)')->from(Place::class, 'p')->getQuery()->getSingleScalarResult(),
            'pendingPlaces' => (int) $em->createQueryBuilder()->select('COUNT(p.id)')->from(Place::class, 'p')->where('p.status = :status')->setParameter('status', Place::STATUS_PENDING)->getQuery()->getSingleScalarResult(),
            'approvedPlaces' => (int) $em->createQueryBuilder()->select('COUNT(p.id)')->from(Place::class, 'p')->where('p.status = :status')->setParameter('status', Place::STATUS_APPROVED)->getQuery()->getSingleScalarResult(),
            'totalBookings' => (int) $em->createQueryBuilder()->select('COUNT(b.id)')->from(Booking::class, 'b')->getQuery()->getSingleScalarResult(),
            'pendingBookings' => (int) $em->createQueryBuilder()->select('COUNT(b.id)')->from(Booking::class, 'b')->where('b.status = :status')->setParameter('status', Booking::STATUS_PENDING)->getQuery()->getSingleScalarResult(),
        ];

        $pendingPlaces = $em->createQueryBuilder()
            ->select('p', 'h')
            ->from(Place::class, 'p')
            ->join('p.host', 'h')
            ->where('p.status = :status')
            ->setParameter('status', Place::STATUS_PENDING)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $recentBookings = $em->createQueryBuilder()
            ->select('b', 'p', 'g')
            ->from(Booking::class, 'b')
            ->join('b.place', 'p')
            ->join('b.guest', 'g')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(10)
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

        $placePreviewImages = $this->buildPlacePreviewImagesMap($em, $previewPlaces);

        return $this->render('booking/admin_dashboard.html.twig', [
            'stats' => $stats,
            'pendingPlaces' => $pendingPlaces,
            'recentBookings' => $recentBookings,
            'placePreviewImages' => $placePreviewImages,
        ]);
    }

    private function buildPlacePreviewImagesMap(EntityManagerInterface $em, array $places): array
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

        $images = $em->createQueryBuilder()
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

    private function fetchOsrmDistancesFromOrigin(float $originLat, float $originLng, array $places): array
    {
        $destinations = [];
        foreach ($places as $place) {
            if (!$place instanceof Place || $place->getId() === null) {
                continue;
            }

            $latitude = $place->getLatitude();
            $longitude = $place->getLongitude();
            if ($latitude === null || $longitude === null || !is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }

            $latValue = (float) $latitude;
            $lngValue = (float) $longitude;

            $destinations[] = [
                'id' => (int) $place->getId(),
                'lat' => $latValue,
                'lng' => $lngValue,
            ];
        }

        if ($destinations === []) {
            return [];
        }

        $distanceByPlaceId = [];
        foreach (array_chunk($destinations, 60) as $chunk) {
            $coordinates = [
                sprintf('%.8F,%.8F', $originLng, $originLat),
            ];

            foreach ($chunk as $destination) {
                $coordinates[] = sprintf('%.8F,%.8F', $destination['lng'], $destination['lat']);
            }

            $destinationsParam = implode(',', range(1, count($chunk)));
            $osrmUrl = sprintf(
                'http://127.0.0.1:5001/table/v1/driving/%s?sources=0&destinations=%s&annotations=distance',
                implode(';', $coordinates),
                $destinationsParam
            );

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 12,
                    'header' => "Accept: application/json\r\n",
                ],
            ]);

            $response = @file_get_contents($osrmUrl, false, $context);

            $fallbackDistances = [];
            foreach ($chunk as $destination) {
                $fallbackDistances[$destination['id']] = $this->calculateHaversineDistanceMeters(
                    $originLat,
                    $originLng,
                    (float) $destination['lat'],
                    (float) $destination['lng']
                );
            }

            if (!is_string($response) || $response === '') {
                foreach ($fallbackDistances as $destinationId => $fallbackDistance) {
                    $distanceByPlaceId[$destinationId] = $fallbackDistance;
                }
                continue;
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded) || ($decoded['code'] ?? '') !== 'Ok') {
                foreach ($fallbackDistances as $destinationId => $fallbackDistance) {
                    $distanceByPlaceId[$destinationId] = $fallbackDistance;
                }
                continue;
            }

            $distances = $decoded['distances'][0] ?? null;
            if (!is_array($distances)) {
                foreach ($fallbackDistances as $destinationId => $fallbackDistance) {
                    $distanceByPlaceId[$destinationId] = $fallbackDistance;
                }
                continue;
            }

            foreach ($chunk as $index => $destination) {
                $distanceValue = $distances[$index] ?? null;
                if (is_numeric($distanceValue)) {
                    $distanceByPlaceId[$destination['id']] = (float) $distanceValue;
                    continue;
                }

                $distanceByPlaceId[$destination['id']] = $fallbackDistances[$destination['id']];
            }
        }

        return $distanceByPlaceId;
    }

    private function calculateHaversineDistanceMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusMeters = 6371000.0;

        $latFromRad = deg2rad($fromLat);
        $latToRad = deg2rad($toLat);
        $deltaLat = deg2rad($toLat - $fromLat);
        $deltaLng = deg2rad($toLng - $fromLng);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
            + cos($latFromRad) * cos($latToRad) * sin($deltaLng / 2) * sin($deltaLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }

    private function isCoordinateInTunisia(float $latitude, float $longitude): bool
    {
        return $latitude >= self::TUNISIA_SOUTH
            && $latitude <= self::TUNISIA_NORTH
            && $longitude >= self::TUNISIA_WEST
            && $longitude <= self::TUNISIA_EAST;
    }

    private function buildPlaceGalleryImagesMap(EntityManagerInterface $em, array $places): array
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

        $images = $em->createQueryBuilder()
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

    #[Route('/admin/place/{id}/approve', name: 'app_booking_admin_place_approve', methods: ['POST'])]
    public function approvePlace(int $id, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $place = $em->find(Place::class, $id);
        if ($place instanceof Place) {
            $place->setStatus(Place::STATUS_APPROVED);
            $place->setDenialReason(null);

            $host = $place->getHost();
            if ($host instanceof User && !$host->isAdmin() && $host->getRole() !== RoleEnum::HOST) {
                $host->setRole(RoleEnum::HOST);
            }

            $em->flush();
            $activityLogger->logAction($this->getUser() instanceof User ? $this->getUser() : null, 'booking', 'place_approved_by_admin', [
                'targetType' => 'place',
                'targetId' => $place->getId(),
                'targetName' => $place->getTitle(),
                'targetImage' => $place->getImageUrl(),
                'destination' => $place->getHost()?->getFullName(),
            ]);
            $this->addFlash('success', 'Place approved.');
        }

        return $this->redirectToRoute('app_booking_admin');
    }

    #[Route('/admin/place/{id}/deny', name: 'app_booking_admin_place_deny', methods: ['POST'])]
    public function denyPlace(int $id, Request $request, EntityManagerInterface $em, ActivityLogger $activityLogger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $place = $em->find(Place::class, $id);
        if ($place instanceof Place) {
            $reason = trim((string) $request->request->get('reason', ''));
            $place->setStatus(Place::STATUS_DENIED);
            $place->setDenialReason($reason !== '' ? $reason : null);
            $em->flush();
            $activityLogger->logAction($this->getUser() instanceof User ? $this->getUser() : null, 'booking', 'place_denied_by_admin', [
                'targetType' => 'place',
                'targetId' => $place->getId(),
                'targetName' => $place->getTitle(),
                'targetImage' => $place->getImageUrl(),
                'destination' => $place->getHost()?->getFullName(),
                'content' => $reason !== '' ? $reason : null,
            ]);
            $this->addFlash('success', 'Place denied.');
        }

        return $this->redirectToRoute('app_booking_admin');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('You must be logged in.');
        }

        return $user;
    }

    private function normalizeDecimalString(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.') ?: '0';
    }

    private function calculateCancellationRefundPolicy(Booking $booking, \DateTimeImmutable $cancelledAt): array
    {
        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            return ['percent' => 0.0, 'amount' => '0.00'];
        }

        $secondsUntilStart = $booking->getStartDate()->setTime(0, 0)->getTimestamp() - $cancelledAt->getTimestamp();
        $daysUntilStart = (int) floor($secondsUntilStart / 86400);

        $refundPercent = 0.0;
        if ($daysUntilStart >= 7) {
            $refundPercent = 100.0;
        } elseif ($daysUntilStart >= 3) {
            $refundPercent = 50.0;
        }

        $refundAmount = ((float) $booking->getTotalPrice() * $refundPercent) / 100;

        return [
            'percent' => $refundPercent,
            'amount' => number_format($refundAmount, 2, '.', ''),
        ];
    }

    private function isPlaceAvailable(EntityManagerInterface $em, Place $place, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $ignoreBookingId = null): bool
    {
        $qb = $em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(Booking::class, 'b')
            ->where('b.place = :place')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('NOT (b.endDate <= :start OR b.startDate >= :end)')
            ->setParameter('place', $place)
            ->setParameter('statuses', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($ignoreBookingId !== null) {
            $qb->andWhere('b.id != :ignoreBookingId')
                ->setParameter('ignoreBookingId', $ignoreBookingId);
        }

        $count = (int) $qb->getQuery()->getSingleScalarResult();

        return $count === 0;
    }
}
