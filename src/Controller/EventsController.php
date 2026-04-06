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
            // Gérer la confirmation de l'organisateur
            $confirmationOrganisateur = $form->get('confirmation_organisateur')->getData();
            $event->setConfirmationOrganisateur($confirmationOrganisateur);
            
            // Set date_creation to current date
            $event->setDateCreation(new \DateTime());
            
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
                } catch (FileException $e) {
                    // Gérer l'erreur si nécessaire
                    $this->addFlash('error', 'Erreur lors de l\'upload de l\'image: '.$e->getMessage());
                    return $this->redirectToRoute('app_events_new');
                }
                
                $event->setImage($newFilename);
            }
            
            // Synchroniser les places disponibles avec la capacité maximale
            $capaciteMax = $event->getCapaciteMax();
            $event->setPlacesDisponibles($capaciteMax);
            
            $entityManager->persist($event);
            $entityManager->flush();

            $this->addFlash('success', 'L\'événement a été créé avec succès.');

            return $this->redirectToRoute('app_events_index', [], Response::HTTP_SEE_OTHER);
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
