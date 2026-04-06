<?php

namespace App\Controller;

use App\Entity\Reservations;
use App\Entity\Events;
use App\Form\ReservationsType;
use App\Repository\ReservationsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/reservations')]
class ReservationsController extends AbstractController
{
    #[Route('/', name: 'app_reservations_index', methods: ['GET'])]
    public function index(ReservationsRepository $repository): Response
    {
        return $this->render('reservations/index.html.twig', [
            'reservations' => $repository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_reservations_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $reservation = new Reservations();
        
        // Récupérer l'ID de l'événement depuis la requête
        $eventId = $request->query->get('event_id');
        $eventName = '';
        
        // Si un eventId est fourni, pré-remplir l'événement
        if ($eventId) {
            $event = $entityManager->find(Events::class, $eventId);
            if ($event) {
                $reservation->setEvent($event);
                $eventName = $event->getLieu() . ' - ' . $event->getDateDebut()->format('d/m/Y H:i');
                
                // Pré-remplir le prix si l'événement est sélectionné
                $prixUnitaire = $event->getPrix();
                $prixTotal = bcmul($prixUnitaire, '1', 2); // Par défaut 1 personne
                $reservation->setPrixTotal($prixTotal);
                $reservation->setNombrePersonnes(1); // Par défaut 1 personne
            }
        }
        
        // Créer le formulaire avec les options
        $form = $this->createForm(ReservationsType::class, $reservation, [
            'event_id' => $eventId,
            'event_name' => $eventName
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Définir la date de réservation si nécessaire
            if (!$reservation->getDateCreation()) {
                $reservation->setDateCreation(new \DateTime());
            }
            
            // Calculer le prix total automatiquement
            $event = $reservation->getEvent();
            if ($event && $reservation->getNombrePersonnes()) {
                $prixUnitaireFloat = (float) $event->getPrix();
                $nombrePersonnesInt = (int) $reservation->getNombrePersonnes();
                $prixTotal = $prixUnitaireFloat * $nombrePersonnesInt;
                
                $reservation->setPrixTotal(number_format($prixTotal, 2, '.', ''));
                
                // Diminuer les places disponibles dans l'événement
                $placesDisponibles = $event->getPlacesDisponibles();
                if ($placesDisponibles !== null) {
                    $nouvellesPlacesDisponibles = $placesDisponibles - $nombrePersonnesInt;
                    $event->setPlacesDisponibles($nouvellesPlacesDisponibles);
                    
                    // Sauvegarder aussi l'événement modifié
                    $entityManager->persist($event);
                }
            }
            
            $entityManager->persist($reservation);
            $entityManager->flush();

            $this->addFlash('success', 'Réservation créée avec succès !');
            return $this->redirectToRoute('app_reservations_index');
        }

        return $this->render('reservations/new.html.twig', [
            'form' => $form->createView(),
            'eventId' => $eventId,
        ]);
    }

    #[Route('/{id}', name: 'app_reservations_show', methods: ['GET'])]
    public function show(Reservations $reservation): Response
    {
        // Récupérer l'événement associé via la relation
        $event = $reservation->getEvent();
        
        return $this->render('reservations/show.html.twig', [
            'reservation' => $reservation,
            'event' => $event,  // Passer l'objet event complet
        ]);
    }

    #[Route('/{id}/edit', name: 'app_reservations_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Reservations $reservation, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ReservationsType::class, $reservation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Réservation modifiée avec succès !');
            return $this->redirectToRoute('app_reservations_index');
        }

        // Récupérer l'événement associé
        $event = $reservation->getEvent();

        return $this->render('reservations/edit.html.twig', [
            'form' => $form->createView(),
            'reservation' => $reservation,
            'event' => $event,  // Passer l'objet event complet
        ]);
    }

    #[Route('/{id}', name: 'app_reservations_delete', methods: ['POST'])]
    public function delete(Request $request, Reservations $reservation, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $reservation->getId(), $request->request->get('_token'))) {
            // Libérer les places de l'événement avant de supprimer la réservation
            $event = $reservation->getEvent();
            if ($event && $reservation->getNombrePersonnes()) {
                $nombrePersonnes = $reservation->getNombrePersonnes();
                $placesDisponibles = $event->getPlacesDisponibles();
                if ($placesDisponibles !== null) {
                    $nouvellesPlacesDisponibles = $placesDisponibles + $nombrePersonnes;
                    $event->setPlacesDisponibles($nouvellesPlacesDisponibles);
                    
                    // Sauvegarder les modifications de l'événement
                    $entityManager->persist($event);
                }
            }
            
            // Supprimer la réservation
            $entityManager->remove($reservation);
            $entityManager->flush();
            
            $this->addFlash('success', 'Réservation supprimée avec succès ! Les places ont été libérées.');
        }

        return $this->redirectToRoute('app_reservations_index');
    }
    
    #[Route('/{id}/confirm', name: 'app_reservations_confirm', methods: ['POST'])]
    public function confirm(Request $request, Reservations $reservation, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('confirm' . $reservation->getId(), $request->request->get('_token'))) {
            $reservation->setStatut('confirmee');
            $entityManager->flush();
            $this->addFlash('success', 'La réservation a été confirmée.');
        }
        
        return $this->redirectToRoute('app_reservations_index');
    }
    
    #[Route('/{id}/cancel', name: 'app_reservations_cancel', methods: ['POST'])]
    public function cancel(Request $request, Reservations $reservation, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('cancel' . $reservation->getId(), $request->request->get('_token'))) {
            $reservation->setStatut('annulee');
            $entityManager->flush();
            $this->addFlash('success', 'La réservation a été annulée.');
        }
        
        return $this->redirectToRoute('app_reservations_index');
    }
}