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
    // ─────────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────────
    #[Route('/', name: 'app_reservations_index', methods: ['GET'])]
    public function index(ReservationsRepository $repository): Response
    {
        return $this->render('reservations/index.html.twig', [
            'reservations' => $repository->findAll(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  NEW
    // ─────────────────────────────────────────────────────────────────
    #[Route('/new', name: 'app_reservations_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $eventId = $request->query->get('event_id');
        $event   = $em->find(Events::class, $eventId);

        if (!$event) {
            $this->addFlash('error', 'Événement introuvable.');
            return $this->redirectToRoute('app_events_index');
        }

        if ($event->getPlacesDisponibles() <= 0) {
            $this->addFlash('error', 'Cet événement est complet.');
            return $this->redirectToRoute('app_events_show', ['id' => $event->getId()]);
        }

        $reservation = new Reservations();
        $reservation->setEvent($event);

        $form = $this->createForm(ReservationsType::class, $reservation, [
            'max_places' => $event->getPlacesDisponibles(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $adultes = $reservation->getNombreAdultes();
            $enfants = $reservation->getNombreEnfants();
            $total   = $adultes + $enfants;

            // Validation : au moins 1 personne
            if ($total <= 0) {
                $this->addFlash('error', 'Veuillez indiquer au moins 1 adulte ou enfant.');
                return $this->render('reservations/new.html.twig', [
                    'form' => $form, 'event' => $event,
                ]);
            }

            // Validation : assez de places
            if ($total > $event->getPlacesDisponibles()) {
                $this->addFlash('error', sprintf(
                    'Seulement %d place(s) disponible(s).', $event->getPlacesDisponibles()
                ));
                return $this->render('reservations/new.html.twig', [
                    'form' => $form, 'event' => $event,
                ]);
            }

            // ── Calculs automatiques selon la structure de table ───────────────────────────────
            $reservation->setNombrePersonnes($total); // nombre_personnes = nombre_adultes + nombre_enfants
            $reservation->setPrixTotal(
                number_format((float) $event->getPrix() * $total, 2, '.', '') // prix_total = (nombre_enfants + nombre_adultes) × prix_event
            );
            $reservation->setStatut('en_attente'); // statut par défaut
            // date_creation est déjà défini dans le constructeur de l'entité

            // Décrémenter les places dans l'événement
            $event->setPlacesDisponibles($event->getPlacesDisponibles() - $total);

            $em->persist($reservation);
            $em->persist($event);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Réservation confirmée ! %d personne(s) — %s TND.',
                $total, $reservation->getPrixTotal()
            ));

            return $this->redirectToRoute('app_reservations_show', [
                'id' => $reservation->getId(),
            ]);
        }

        return $this->render('reservations/new.html.twig', [
            'form'  => $form->createView(),
            'event' => $event,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  SHOW
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'app_reservations_show', methods: ['GET'])]
    public function show(Reservations $reservation): Response
    {
        return $this->render('reservations/show.html.twig', [
            'reservation' => $reservation,
            'event'       => $reservation->getEvent(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  EDIT
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/edit', name: 'app_reservations_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Reservations $reservation,
        EntityManagerInterface $em
    ): Response {
        $event = $reservation->getEvent();

        $form = $this->createForm(ReservationsType::class, $reservation, [
            'max_places' => $event
                ? $event->getPlacesDisponibles() + $reservation->getNombrePersonnes()
                : 100,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $adultes = $reservation->getNombreAdultes();
            $enfants = $reservation->getNombreEnfants();
            $total   = $adultes + $enfants;

            $reservation->setNombrePersonnes($total);

            if ($event) {
                $prixTotal = number_format((float) $event->getPrix() * $total, 2, '.', '');
                $reservation->setPrixTotal($prixTotal);
            }

            // S'assurer que l'entité est bien suivie par Doctrine
            $em->merge($reservation);
            $em->flush();
            $this->addFlash('success', 'Réservation modifiée avec succès !');
            return $this->redirectToRoute('app_reservations_index');
        }

        return $this->render('reservations/edit.html.twig', [
            'form'        => $form->createView(),
            'reservation' => $reservation,
            'event'       => $event,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  DELETE
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'app_reservations_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Reservations $reservation,
        EntityManagerInterface $em
    ): Response {
        if ($this->isCsrfTokenValid('delete'.$reservation->getId(), $request->request->get('_token'))) {
            $event = $reservation->getEvent();
            if ($event) {
                $event->setPlacesDisponibles(
                    $event->getPlacesDisponibles() + $reservation->getNombrePersonnes()
                );
                $em->persist($event);
            }
            $em->remove($reservation);
            $em->flush();
            $this->addFlash('success', 'Réservation supprimée. Places libérées.');
        }

        return $this->redirectToRoute('app_reservations_index');
    }

    // ─────────────────────────────────────────────────────────────────
    //  CONFIRM / CANCEL
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/confirm', name: 'app_reservations_confirm', methods: ['POST'])]
    public function confirm(
        Request $request, Reservations $reservation, EntityManagerInterface $em
    ): Response {
        if ($this->isCsrfTokenValid('confirm'.$reservation->getId(), $request->request->get('_token'))) {
            $reservation->setStatut('confirmee');
            $em->flush();
            $this->addFlash('success', 'Réservation confirmée.');
        }
        return $this->redirectToRoute('app_reservations_index');
    }

    #[Route('/{id}/cancel', name: 'app_reservations_cancel', methods: ['POST'])]
    public function cancel(
        Request $request, Reservations $reservation, EntityManagerInterface $em
    ): Response {
        if ($this->isCsrfTokenValid('cancel'.$reservation->getId(), $request->request->get('_token'))) {
            $reservation->setStatut('annulee');
            $em->flush();
            $this->addFlash('success', 'Réservation annulée.');
        }
        return $this->redirectToRoute('app_reservations_index');
    }
}