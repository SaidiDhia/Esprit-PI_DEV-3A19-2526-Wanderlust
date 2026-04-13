<?php

namespace App\Controller;

use App\Entity\Events;
use App\Entity\EventImages;
use App\Form\EventsType;
use App\Repository\EventsRepository;
use App\Repository\ActivitiesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/events')]
final class EventsController extends AbstractController
{
    // ─────────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────────
    #[Route(name: 'app_events_index', methods: ['GET'])]
    public function index(EventsRepository $eventsRepository): Response
    {
        return $this->render('events/index.html.twig', [
            'events' => $eventsRepository->findAllWithActivites(),
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
    $event = new Events();
    $form = $this->createForm(EventsType::class, $event);
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
        $event->setStatut('en_attente');

        $entityManager->persist($event);
        $entityManager->flush();

        $this->addFlash('success', 'Événement créé avec succès !');
        return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
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
        return $this->render('events/show.html.twig', [
            'event' => $event,
        ]);
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
        ActivitiesRepository $activitiesRepo
    ): Response {
        $form = $this->createForm(EventsType::class, $event);
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

            $event->setPlacesDisponibles($event->getCapaciteMax());
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
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return null;
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return null;
        }

        // ── Nom du fichier ─────────────────────────────────────────
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = $slugger->slug($originalName);
        $extension    = $file->getClientOriginalExtension() ?: 'jpg';
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
}