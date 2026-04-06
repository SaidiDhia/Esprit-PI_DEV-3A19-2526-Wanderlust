<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Place;
use App\Entity\PlaceImage;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\RoleEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/booking', name: 'app_booking')]
class BookingController extends AbstractController
{
    private const DEFAULT_LATITUDE = '36.80650000';
    private const DEFAULT_LONGITUDE = '10.18150000';

    #[Route('', name: '')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $city = trim((string) $request->query->get('city', ''));
        $category = trim((string) $request->query->get('category', ''));

        $qb = $em->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->andWhere('p.status = :status')
            ->setParameter('status', Place::STATUS_APPROVED)
            ->orderBy('p.createdAt', 'DESC');

        if ($search !== '') {
            $qb->andWhere('LOWER(p.title) LIKE :search OR LOWER(p.description) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        if ($city !== '') {
            $qb->andWhere('LOWER(p.city) = :city')
                ->setParameter('city', mb_strtolower($city));
        }

        if ($category !== '') {
            $qb->andWhere('LOWER(p.category) = :category')
                ->setParameter('category', mb_strtolower($category));
        }

        $places = $qb->getQuery()->getResult();
        $placePreviewImages = $this->buildPlacePreviewImagesMap($em, $places);

        $myPendingPlaces = [];
        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $myPendingPlaces = $em->createQueryBuilder()
                ->select('p')
                ->from(Place::class, 'p')
                ->where('p.host = :user')
                ->andWhere('p.status IN (:statuses)')
                ->setParameter('user', $currentUser)
                ->setParameter('statuses', [Place::STATUS_PENDING, Place::STATUS_DENIED])
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
            'stats' => $stats,
            'filters' => [
                'q' => $search,
                'city' => $city,
                'category' => $category,
            ],
        ]);
    }

    #[Route('/place/{id}', name: '_place', methods: ['GET'])]
    public function place(int $id, EntityManagerInterface $em): Response
    {
        $place = $em->find(Place::class, $id);
        if (!$place instanceof Place) {
            throw $this->createNotFoundException('Place not found.');
        }

        return $this->render('booking/place.html.twig', $this->buildPlacePageData($em, $place));
    }

    #[Route('/place/{id}/book', name: '_book', methods: ['POST'])]
    public function book(int $id, Request $request, EntityManagerInterface $em): Response
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

        $this->addFlash('success', 'Booking request sent. Waiting for confirmation.');
        return $this->redirectToRoute('app_booking_my_bookings');
    }

    #[Route('/place/{id}/review', name: '_review', methods: ['POST'])]
    public function submitReview(int $id, Request $request, EntityManagerInterface $em): Response
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

        $existingReview = $em->createQueryBuilder()
            ->select('r')
            ->from(Review::class, 'r')
            ->where('r.place = :place')
            ->andWhere('r.user = :user')
            ->setParameter('place', $place)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $review = $existingReview instanceof Review ? $existingReview : new Review();
        if (!$existingReview instanceof Review) {
            $review->setPlace($place);
            $review->setUser($user);
            $em->persist($review);
        }

        $review->setRating($rating);
        $review->setComment($reviewFormData['comment'] !== '' ? $reviewFormData['comment'] : null);

        $this->recalculatePlaceRatingStats($em, $place);
        $em->flush();

        $this->addFlash('success', $existingReview instanceof Review ? 'Review updated successfully.' : 'Review submitted successfully.');
        return $this->redirectToRoute('app_booking_place', ['id' => $place->getId()]);
    }

    #[Route('/my-bookings', name: '_my_bookings')]
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

    #[Route('/my-bookings/{id}/edit', name: '_my_booking_edit', methods: ['POST'])]
    public function editMyBooking(int $id, Request $request, EntityManagerInterface $em): Response
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

        $this->addFlash('success', 'Booking dates updated successfully.');
        return $this->redirectToRoute('app_booking_my_bookings');
    }

    #[Route('/host', name: '_host')]
    public function hostDashboard(EntityManagerInterface $em): Response
    {
        $user = $this->requireUser();
        return $this->renderHostDashboard($em, $user, $user);
    }

    #[Route('/host/{id}', name: '_host_view', methods: ['GET'])]
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

    #[Route('/host/request', name: '_host_request', methods: ['POST'])]
    public function submitPlaceRequest(Request $request, EntityManagerInterface $em): Response
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

        if (!isset($formErrors['capacity']) && !isset($formErrors['max_guests']) && $maxGuests > $capacity) {
            $formErrors['max_guests'] = 'Max guests cannot exceed capacity.';
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
            $hostPlaces = $this->fetchHostPlaces($em, $user);
            return $this->render('booking/host_dashboard.html.twig', [
                'places' => $hostPlaces,
                'placePreviewImages' => $this->buildPlacePreviewImagesMap($em, $hostPlaces),
                'placeGalleryImages' => $this->buildPlaceGalleryImagesMap($em, $hostPlaces),
                'pendingRequests' => $this->countHostPendingRequests($em, $user),
                'formErrors' => $formErrors,
                'formData' => $formData,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
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

        $this->addFlash('success', 'Your place request was submitted for admin review.');
        return $this->redirectToRoute('app_booking_host');
    }

    #[Route('/host/places/{id}/update', name: '_host_place_update', methods: ['POST'])]
    public function updateHostPlace(Place $place, Request $request, EntityManagerInterface $em): Response
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

        if (empty($errors) && $maxGuests > $capacity) {
            $errors[] = 'Max guests cannot exceed capacity.';
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

        $this->addFlash('success', 'Place updated successfully.');
        return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $host->getId()]);
    }

    #[Route('/host/places/{id}/delete', name: '_host_place_delete', methods: ['POST'])]
    public function deleteHostPlace(Place $place, Request $request, EntityManagerInterface $em): Response
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

        $em->remove($place);
        $em->flush();

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

    private function renderHostDashboard(EntityManagerInterface $em, User $hostUser, ?User $viewer = null): Response
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

        return $this->render('booking/host_dashboard.html.twig', [
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
        ]);
    }

    #[Route('/host/bookings/{id}/confirm', name: '_host_booking_confirm', methods: ['POST'])]
    public function confirmHostBooking(int $id, Request $request, EntityManagerInterface $em): Response
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
            return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $hostUser->getId()]);
        }

        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $em->flush();

        $this->addFlash('success', 'Booking request approved.');
        return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $hostUser->getId()]);
    }

    #[Route('/host/bookings/{id}/reject', name: '_host_booking_reject', methods: ['POST'])]
    public function rejectHostBooking(int $id, Request $request, EntityManagerInterface $em): Response
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
            return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $hostUser->getId()]);
        }

        $booking->setStatus(Booking::STATUS_REJECTED);
        $em->flush();

        $this->addFlash('success', 'Booking request rejected.');
        return $this->redirectToRoute($viewer->isAdmin() ? 'app_booking_host_view' : 'app_booking_host', ['id' => $hostUser->getId()]);
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

    #[Route('/admin', name: '_admin')]
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

    #[Route('/admin/place/{id}/approve', name: '_admin_place_approve', methods: ['POST'])]
    public function approvePlace(int $id, EntityManagerInterface $em): Response
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
            $this->addFlash('success', 'Place approved.');
        }

        return $this->redirectToRoute('app_booking_admin');
    }

    #[Route('/admin/place/{id}/deny', name: '_admin_place_deny', methods: ['POST'])]
    public function denyPlace(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $place = $em->find(Place::class, $id);
        if ($place instanceof Place) {
            $reason = trim((string) $request->request->get('reason', ''));
            $place->setStatus(Place::STATUS_DENIED);
            $place->setDenialReason($reason !== '' ? $reason : null);
            $em->flush();
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
