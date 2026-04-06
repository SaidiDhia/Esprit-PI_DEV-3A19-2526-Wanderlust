<?php

namespace App\Controller;

use App\Entity\Events;
use App\Form\EventsType;
use App\Repository\EventsRepository;
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
    #[Route(name: 'app_events_index', methods: ['GET'])]
    public function index(EventsRepository $eventsRepository): Response
    {
        return $this->render('events/index.html.twig', [
            'events' => $eventsRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_events_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $event = new Events();
        $form = $this->createForm(EventsType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Debug: Afficher les données du formulaire
                dump($form->getData());
                
                // Gérer la confirmation de l'organisateur
                $confirmationOrganisateur = $form->get('confirmation_organisateur')->getData();
                if (!$confirmationOrganisateur) {
                    $this->addFlash('error', 'Vous devez confirmer être l\'organisateur de cet événement.');
                    return $this->redirectToRoute('app_events_new');
                }
                $event->setConfirmationOrganisateur($confirmationOrganisateur);
                
                // Set date_creation to current date
                $event->setDateCreation(new \DateTime());
                
                // Validation des dates
                $dateDebut = $event->getDateDebut();
                $dateFin = $event->getDateFin();
                if ($dateDebut >= $dateFin) {
                    $this->addFlash('error', 'La date de fin doit être postérieure à la date de début.');
                    return $this->redirectToRoute('app_events_new');
                }
                
                // Validation des places
                $capaciteMax = $event->getCapaciteMax();
                $placesDisponibles = $event->getPlacesDisponibles();
                if ($placesDisponibles > $capaciteMax) {
                    $this->addFlash('error', 'Les places disponibles ne peuvent pas dépasser la capacité maximale.');
                    return $this->redirectToRoute('app_events_new');
                }
                
                // Gestion de l'upload d'image
                /** @var UploadedFile $imageFile */
                $imageFile = $form->get('image')->getData();
                
                if ($imageFile) {
                    // Debug: Afficher les infos du fichier
                    dump([
                        'filename' => $imageFile->getClientOriginalName(),
                        'size' => $imageFile->getSize(),
                        'mimeType' => $imageFile->getMimeType(),
                        'error' => $imageFile->getError()
                    ]);
                    
                    // Validation de l'image
                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    if (!in_array($imageFile->getMimeType(), $allowedMimeTypes)) {
                        $this->addFlash('error', 'Format d\'image non valide. Formats acceptés: JPG, PNG, GIF.');
                        return $this->redirectToRoute('app_events_new');
                    }
                    
                    if ($imageFile->getSize() > 5 * 1024 * 1024) { // 5MB
                        $this->addFlash('error', 'L\'image est trop volumineuse. Taille maximale: 5MB.');
                        return $this->redirectToRoute('app_events_new');
                    }
                    
                    $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    
                    // Obtenir l'extension de manière sécurisée
                    $extension = $imageFile->getClientOriginalExtension();
                    if (!$extension) {
                        $extension = 'jpg'; // Extension par défaut
                    }
                    
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$extension;
                    
                    try {
                        $imageFile->move(
                            $this->getParameter('kernel.project_dir').'/public/uploads/events',
                            $newFilename
                        );
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Erreur lors de l\'upload de l\'image: '.$e->getMessage());
                        return $this->redirectToRoute('app_events_new');
                    }
                    
                    $event->setImage($newFilename);
                } else {
                    $this->addFlash('error', 'Une image est obligatoire pour créer un événement.');
                    return $this->redirectToRoute('app_events_new');
                }
                
                // Synchroniser les places disponibles avec la capacité maximale
                $event->setPlacesDisponibles($capaciteMax);
                
                // Définir le statut par défaut
                $event->setStatut('en_attente');
                
                $entityManager->persist($event);
                $entityManager->flush();

                $this->addFlash('success', 'L\'événement a été créé avec succès et est en attente de validation.');

                return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
                
            } catch (\Exception $e) {
                $this->addFlash('error', 'Une erreur est survenue lors de la création de l\'événement: '.$e->getMessage());
                return $this->redirectToRoute('app_events_new');
            }
        } else {
            // Debug: Afficher les erreurs de formulaire
            if ($form->isSubmitted()) {
                dump('Form submitted but invalid');
                foreach ($form->getErrors(true) as $error) {
                    dump($error->getMessage());
                }
            }
        }

        return $this->render('events/new.html.twig', [
            'event' => $event,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_events_show', methods: ['GET'])]
    public function show(Events $event): Response
    {
        return $this->render('events/show.html.twig', [
            'event' => $event,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_events_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Events $event, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $form = $this->createForm(EventsType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Gérer la confirmation de l'organisateur
            $confirmationOrganisateur = $form->get('confirmation_organisateur')->getData();
            $event->setConfirmationOrganisateur($confirmationOrganisateur);
            
            // Gestion de l'upload d'image
            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('image')->getData();
            
            if ($imageFile) {
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                
                // Obtenir l'extension de manière sécurisée sans détection MIME
                $extension = $imageFile->getClientOriginalExtension();
                if (!$extension) {
                    // Fallback si l'extension n'est pas disponible
                    $extension = 'jpg'; // Extension par défaut
                }
                
                $newFilename = $safeFilename.'-'.uniqid().'.'.$extension;
                
                try {
                    $imageFile->move(
                        $this->getParameter('kernel.project_dir').'/public/uploads/events',
                        $newFilename
                    );
                    
                    // Supprimer l'ancienne image si elle existe et n'est pas un chemin temporaire
                    $oldImage = $event->getImage();
                    if ($oldImage && !str_contains($oldImage, 'AppData') && !str_contains($oldImage, 'tmp')) {
                        $oldImagePath = $this->getParameter('kernel.project_dir').'/public/uploads/events/'.$oldImage;
                        if (file_exists($oldImagePath)) {
                            unlink($oldImagePath);
                        }
                    }
                    
                    $event->setImage($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Erreur lors de l\'upload de l\'image: '.$e->getMessage());
                    return $this->redirectToRoute('app_events_edit', ['id' => $event->getId()]);
                }
            } else {
                // Si aucune nouvelle image n'est uploadée, vérifier si l'image actuelle est un chemin temporaire
                $currentImage = $event->getImage();
                if ($currentImage && (str_contains($currentImage, 'AppData') || str_contains($currentImage, 'tmp'))) {
                    // Nettoyer le chemin temporaire
                    $event->setImage(null);
                }
            }
            
            // Synchroniser les places disponibles avec la capacité maximale
            $capaciteMax = $event->getCapaciteMax();
            $event->setPlacesDisponibles($capaciteMax);
            
            $entityManager->flush();

            $this->addFlash('success', 'L\'événement a été modifié avec succès.');

            return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('events/edit.html.twig', [
            'event' => $event,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_events_delete', methods: ['POST'])]
    public function delete(Request $request, Events $event, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$event->getId(), $request->request->get('_token'))) {
            $entityManager->remove($event);
            $entityManager->flush();
            $this->addFlash('success', 'L\'événement a été supprimé avec succès.');
        } else {
            $this->addFlash('error', 'Token CSRF invalide.');
        }

        return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
    }
}
