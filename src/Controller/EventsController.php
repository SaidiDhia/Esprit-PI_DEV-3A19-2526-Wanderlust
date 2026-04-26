<?php

namespace App\Controller;

use App\Entity\Events;
use App\Entity\EventImages;
use App\Entity\User;
use App\Enum\StatusEventEnum;
use App\Form\EventsType;
use App\Repository\EventsRepository;
use App\Repository\ActivitiesRepository;
use App\Repository\ReservationsRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/events')]
final class EventsController extends AbstractController
{
    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication required.');
        }

        return $user;
    }

    private function denyUnlessEventOwner(Events $event): void
    {
        $user = $this->getAuthenticatedUser();
        $ownerId = $event->getCreatedBy()?->getId();

        if ($ownerId === null || $ownerId !== $user->getId()) {
            throw $this->createAccessDeniedException('Only the creator can modify or delete this event.');
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────────
    #[Route(name: 'app_events_index', methods: ['GET'])]
    public function index(Request $request, EventsRepository $eventsRepository, PaginatorInterface $paginator): Response
    {
        $user = $this->getUser();
        $canManage = $user && in_array('ROLE_ADMIN', $user->getRoles());
        
        $queryBuilder = $eventsRepository->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->addSelect('a');

        // Admin sees all events
        if ($canManage) {
            $queryBuilder->orderBy('e.dateCreation', 'DESC');
        } else {
            // Authenticated users see: accepted events + their own events (any status)
            // Anonymous users see: only accepted events
            if ($user instanceof User) {
                $queryBuilder
                    ->where('e.status = :acceptedStatus OR e.createdBy = :currentUser')
                    ->setParameter('acceptedStatus', StatusEventEnum::ACCEPTE)
                    ->setParameter('currentUser', $user);
            } else {
                $queryBuilder->where('e.status = :acceptedStatus')
                    ->setParameter('acceptedStatus', StatusEventEnum::ACCEPTE);
            }
            $queryBuilder->orderBy('e.dateCreation', 'DESC');
        }

        $pagination = $paginator->paginate(
            $queryBuilder->getQuery(),
            $request->query->getInt('page', 1),
            6 // Limite par page demandée par l'utilisateur
        );

        return $this->render('events/index.html.twig', [
            'events' => $pagination,
            'canManage' => $canManage,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  NEW
    // ─────────────────────────────────────────────────────────────────
    #[Route('/new', name: 'app_events_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger,
        ActivitiesRepository $activitiesRepo
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $event = new Events();
        $event->setCreatedBy($this->getAuthenticatedUser());
        $user = $this->getUser();
        $isAdmin = $user && in_array('ROLE_ADMIN', $user->getRoles());
        $form = $this->createForm(EventsType::class, $event, ['is_admin' => $isAdmin]);
        $form->handleRequest($request);

        // Récupérer les conditions acceptées
        $selectedConditions = $request->request->all('event_conditions', []);
    // 🔥 Récupérer les activités sélectionnées depuis le formulaire
    $eventActivities = $request->request->all('event_activities', []);
    if (!is_array($eventActivities)) {
        $eventActivities = array_filter(explode(',', (string) $eventActivities), function ($id) {
            return $id !== '';
        });
    }
    $selectedActivitiesIds = array_filter($eventActivities, function ($id) {
        return is_numeric($id);
    });

    if ($form->isSubmitted() && $form->isValid()) {
        // ── Vérification des conditions ──────────────────────────────
        $requiredConditions = ['security', 'qualified', 'verification', 'risks'];
        $missingConditions = array_diff($requiredConditions, $selectedConditions);
        
        if (!empty($missingConditions)) {
            $this->addFlash('error', 'Veuillez accepter toutes les conditions requises avant de créer l\'événement.');
            return $this->render('events/new.html.twig', [
                'event' => $event,
                'form' => $form,
                'activities' => $activitiesRepo->findAll(),
                'selectedConditions' => $selectedConditions,
                'selectedActivitiesIds' => $selectedActivitiesIds,
            ]);
        }

        // ── Vérification des activités sélectionnées ─────────────────
        if (empty($selectedActivitiesIds)) {
            $this->addFlash('error', 'Sélectionnez au moins une activité avant de continuer.');
            return $this->render('events/new.html.twig', [
                'event' => $event,
                'form' => $form,
                'activities' => $activitiesRepo->findAll(),
                'selectedConditions' => $selectedConditions,
                'selectedActivitiesIds' => $selectedActivitiesIds,
            ]);
        }

        // ── Ajouter les activités à l'événement ──────────────────────
        foreach ($selectedActivitiesIds as $activityId) {
            $activity = $activitiesRepo->find((int)$activityId);
            if ($activity) {
                $event->addActivity($activity);
            }
        }

        // ── Validation des dates ────────────────────────────────────
        if ($event->getDateDebut() >= $event->getDateFin()) {
            $this->addFlash('error', 'La date de fin doit être postérieure à la date de début.');
            return $this->render('events/new.html.twig', [
                'event' => $event,
                'form' => $form,
                'activities' => $activitiesRepo->findAll(),
                'selectedConditions' => $selectedConditions,
                'selectedActivitiesIds' => $selectedActivitiesIds,
            ]);
        }

        if ($event->getDateLimiteInscription() >= $event->getDateDebut()) {
            $this->addFlash('error', "La date limite d'inscription doit être avant la date de début.");
            return $this->render('events/new.html.twig', [
                'event' => $event,
                'form' => $form,
                'activities' => $activitiesRepo->findAll(),
                'selectedConditions' => $selectedConditions,
                'selectedActivitiesIds' => $selectedActivitiesIds,
            ]);
        }

        // ── Upload des images ───────────────────────────────────────
        $imageFiles = $form->get('imagesFiles')->getData();
        
        if (!empty($imageFiles)) {
            foreach ($imageFiles as $imageFile) {
                $eventImage = $this->uploadImage($imageFile, $slugger);
                if ($eventImage === null) {
                    $this->addFlash('error', 'Erreur upload image. Formats : JPG, PNG, WEBP. Max 5 Mo.');
                    return $this->render('events/new.html.twig', [
                        'event' => $event,
                        'form' => $form,
                        'activities' => $activitiesRepo->findAll(),
                        'selectedConditions' => $selectedConditions,
                        'selectedActivitiesIds' => $selectedActivitiesIds,
                    ]);
                }
                $event->addImage($eventImage);
            }
        }

        // ── Valeurs automatiques ────────────────────────────────────
        $event->setDateCreation(new \DateTime());
        $event->setPlacesDisponibles($event->getCapaciteMax());
        // Le statut sera automatiquement 'en_attente' grâce à la valeur par défaut dans l'entité

        $entityManager->persist($event);
        $entityManager->flush();

        $this->addFlash('success', 'Événement créé avec succès !');
        return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
    }

    if ($form->isSubmitted() && !$form->isValid()) {
        foreach ($form->getErrors(true) as $error) {
            $this->addFlash('error', $error->getMessage());
        }
    }

    return $this->render('events/new.html.twig', [
        'event' => $event,
        'form' => $form,
        'activities' => $activitiesRepo->findAll(),
        'selectedConditions' => $selectedConditions,
        'selectedActivitiesIds' => $selectedActivitiesIds,
    ]);
}

    // ─────────────────────────────────────────────────────────────────
    //  SHOW
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'app_events_show', methods: ['GET'])]
    public function show(Events $event): Response
    {
        $currentUser = $this->getUser();
        $isOwner = $currentUser instanceof User && $event->getCreatedBy()?->getId() === $currentUser->getId();

        if (!$event->isAccepted() && !$isOwner && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException('Event not found.');
        }

        return $this->render('events/show.html.twig', [
            'event' => $event,
        ]);
    }

    #[Route('/{id}/details-pdf', name: 'app_events_details_pdf', methods: ['GET'])]
    public function detailsPdf(Events $event): Response
    {
        $currentUser = $this->getUser();
        $isOwner = $currentUser instanceof User && $event->getCreatedBy()?->getId() === $currentUser->getId();

        if (!$event->isAccepted() && !$isOwner && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException('Event not found.');
        }

        $html = $this->renderView('events/details_pdf.html.twig', [
            'event' => $event,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('event-details-%d.pdf', (int) $event->getId());
        $response = new Response($dompdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────
    //  SHARE (AJAX)
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/share', name: 'app_events_share', methods: ['POST'])]
    public function share(Events $event, EntityManagerInterface $em): Response
    {
        $event->setShareCount($event->getShareCount() + 1);
        $em->flush();

        return $this->json(['success' => true, 'count' => $event->getShareCount()]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  EDIT
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/edit', name: 'app_events_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Events $event,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger,
        ActivitiesRepository $activitiesRepo,
        ReservationsRepository $reservationsRepo
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyUnlessEventOwner($event);

        $user = $this->getUser();
        $isAdmin = $user && in_array('ROLE_ADMIN', $user->getRoles());
        $form = $this->createForm(EventsType::class, $event, ['is_admin' => $isAdmin]);
        $form->handleRequest($request);

        $selectedConditions = $request->request->all('event_conditions', []);

        $eventActivities = $request->request->all('event_activities', []);
        if (!is_array($eventActivities)) {
            $eventActivities = array_filter(explode(',', (string) $eventActivities), function ($id) {
                return $id !== '';
            });
        }
        $selectedActivitiesIds = array_filter($eventActivities, function ($id) {
            return is_numeric($id);
        });

        if (!$form->isSubmitted()) {
            $selectedActivitiesIds = array_map(function ($activity) {
                return $activity->getId();
            }, $event->getActivities()->toArray());
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $requiredConditions = ['security', 'qualified', 'verification', 'risks'];
            if (count(array_intersect($requiredConditions, $selectedConditions)) !== count($requiredConditions)) {
                $this->addFlash('error', 'Veuillez accepter toutes les conditions requises avant de modifier l\'événement.');
                return $this->render('events/edit.html.twig', [
                    'event' => $event,
                    'form' => $form,
                    'activities' => $activitiesRepo->findAll(),
                    'selectedConditions' => $selectedConditions,
                    'selectedActivitiesIds' => $selectedActivitiesIds,
                ]);
            }

            if (empty($selectedActivitiesIds)) {
                $this->addFlash('error', 'Sélectionnez au moins une activité avant de continuer.');
                return $this->render('events/edit.html.twig', [
                    'event' => $event,
                    'form' => $form,
                    'activities' => $activitiesRepo->findAll(),
                    'selectedConditions' => $selectedConditions,
                    'selectedActivitiesIds' => $selectedActivitiesIds,
                ]);
            }

            if ($event->getDateDebut() >= $event->getDateFin()) {
                $this->addFlash('error', 'La date de fin doit être postérieure à la date de début.');
                return $this->render('events/edit.html.twig', [
                    'event' => $event,
                    'form' => $form,
                    'activities' => $activitiesRepo->findAll(),
                    'selectedConditions' => $selectedConditions,
                    'selectedActivitiesIds' => $selectedActivitiesIds,
                ]);
            }

            if ($event->getDateLimiteInscription() >= $event->getDateDebut()) {
                $this->addFlash('error', "La date limite d'inscription doit être avant la date de début.");
                return $this->render('events/edit.html.twig', [
                    'event' => $event,
                    'form' => $form,
                    'activities' => $activitiesRepo->findAll(),
                    'selectedConditions' => $selectedConditions,
                    'selectedActivitiesIds' => $selectedActivitiesIds,
                ]);
            }

            foreach ($event->getActivities()->toArray() as $existingActivity) {
                $event->removeActivity($existingActivity);
            }
            foreach ($selectedActivitiesIds as $activityId) {
                $activity = $activitiesRepo->find((int) $activityId);
                if ($activity) {
                    $event->addActivity($activity);
                }
            }

            $imageFiles = $form->get('imagesFiles')->getData();
            if (!empty($imageFiles)) {
                $this->deleteImages($event->getImages());
                $imagePaths = [];
                foreach ($imageFiles as $imageFile) {
                    $filename = $this->uploadImage($imageFile, $slugger);
                    if ($filename === null) {
                        $this->addFlash('error', 'Erreur upload image.');
                        return $this->render('events/edit.html.twig', [
                            'event' => $event,
                            'form' => $form,
                            'activities' => $activitiesRepo->findAll(),
                            'selectedConditions' => $selectedConditions,
                            'selectedActivitiesIds' => $selectedActivitiesIds,
                        ]);
                    }
                    $imagePaths[] = $filename;
                }
                $event->setImages($imagePaths);
            }

            // Calculate available places based on accepted reservations only
            $acceptedReservations = $reservationsRepo->findBy([
                'event' => $event,
                'statut' => ['confirmee', 'accepte'],
            ]);
            
            $acceptedPlaces = 0;
            foreach ($acceptedReservations as $reservation) {
                $acceptedPlaces += $reservation->getNombrePersonnes();
            }
            
            $event->setPlacesDisponibles($event->getCapaciteMax() - $acceptedPlaces);
            $entityManager->flush();

            $this->addFlash('success', 'Événement modifié avec succès.');
            return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('events/edit.html.twig', [
            'event' => $event,
            'form' => $form,
            'activities' => $activitiesRepo->findAll(),
            'selectedConditions' => $selectedConditions,
            'selectedActivitiesIds' => $selectedActivitiesIds,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  DELETE
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'app_events_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Events $event,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyUnlessEventOwner($event);

        if ($this->isCsrfTokenValid('delete' . $event->getId(), $request->request->get('_token'))) {
            $this->deleteImages($event->getImages());
            $entityManager->remove($event);
            $entityManager->flush();
            $this->addFlash('success', 'Événement supprimé avec succès.');
        } else {
            $this->addFlash('error', 'Token CSRF invalide.');
        }

        return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
    }

    // ─────────────────────────────────────────────────────────────────
    //  MÉTHODES PRIVÉES
    // ─────────────────────────────────────────────────────────────────

    private function uploadImage(UploadedFile $file, SluggerInterface $slugger): ?EventImages
    {
        // ── Vérifications ──────────────────────────────────────────
        if (!$file->isValid()) {
            return null;
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        $resolvedMime = $this->resolveUploadedMimeType($file);
        if (!in_array($resolvedMime, $allowedMimes, true)) {
            return null;
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return null;
        }

        // ── Nom du fichier ─────────────────────────────────────────
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = $slugger->slug($originalName);
        $extension    = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = match ($resolvedMime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
        }
        $newFilename  = $safeName . '-' . uniqid() . '.' . $extension;

        // ── Dossier de destination ─────────────────────────────────
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/events';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // ── Déplacement ────────────────────────────────────────────
        try {
            $file->move($uploadDir, $newFilename);
            
            // Créer et retourner l'entité EventImages
            $eventImage = new EventImages();
            $eventImage->setImagePath($newFilename);
            $eventImage->setOriginalName($file->getClientOriginalName());
            
            return $eventImage;
        } catch (FileException) {
            return null;
        }
    }

    /**
     * Supprime les fichiers images du disque.
     *
     * @param iterable|null $images
     */
    private function deleteImages(?iterable $images): void
    {
        if (empty($images)) return;
        
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/events';
        
        foreach ($images as $image) {
            $imagePath = $uploadDir . '/' . $image->getImagePath();
            
            if (file_exists($imagePath) && is_writable($uploadDir)) {
                if (!unlink($imagePath)) {
                    error_log('DeleteImages - ERREUR: Impossible de supprimer ' . $imagePath);
                } else {
                    error_log('DeleteImages - SUCCÈS: Fichier supprimé ' . $imagePath);
                }
            } else {
                error_log('DeleteImages - ERREUR: Fichier non trouvé ou dossier non accessible: ' . $imagePath);
            }
        }
    }

    private function resolveUploadedMimeType(UploadedFile $file): string
    {
        try {
            $serverMime = (string) $file->getMimeType();
            if ($serverMime !== '' && str_starts_with($serverMime, 'image/')) {
                return $serverMime;
            }
        } catch (\Throwable) {
        }

        $clientMime = (string) $file->getClientMimeType();
        if ($clientMime !== '' && str_starts_with($clientMime, 'image/')) {
            return $clientMime;
        }

        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        return match ($clientExtension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/jpeg',
        };
    }
}