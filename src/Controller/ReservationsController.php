<?php

namespace App\Controller;

use App\Entity\Reservations;
use App\Entity\Events;
use App\Entity\User;
use App\Enum\StatusEventEnum;
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
    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication required.');
        }

        return $user;
    }

    private function denyUnlessReservationOwner(Reservations $reservation): void
    {
        $user = $this->getAuthenticatedUser();
        $ownerId = $reservation->getUser()?->getId();

        if ($ownerId === null || $ownerId !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only access your own reservations.');
        }
    }

    private function getReservationBlockingReason(Events $event): ?string
    {
        $now = new \DateTimeImmutable();

        if ($event->getStatus() !== StatusEventEnum::ACCEPTE) {
            return 'Les réservations sont ouvertes uniquement pour les événements acceptés.';
        }

        if ($event->getStatus() === StatusEventEnum::TERMINE || $event->getDateFin() <= $now) {
            return 'Cet événement est terminé.';
        }

        $deadline = $event->getDateLimiteInscription();
        if ($deadline !== null && $deadline <= $now) {
            return "La date limite d'inscription est dépassée.";
        }

        if ($event->getPlacesDisponibles() <= 0) {
            return 'Cet événement est complet.';
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────────
    #[Route('/', name: 'app_reservations_index', methods: ['GET'])]
    public function index(ReservationsRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $currentUser = $this->getAuthenticatedUser();

        return $this->render('reservations/index.html.twig', [
            'reservations' => $repository->findBy(['user' => $currentUser], ['dateCreation' => 'DESC']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  NEW
    // ─────────────────────────────────────────────────────────────────
    #[Route('/new', name: 'app_reservations_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $currentUser = $this->getAuthenticatedUser();

        $eventId = $request->query->get('event_id');
        $event   = $em->find(Events::class, $eventId);

        if (!$event) {
            $this->addFlash('error', 'Événement introuvable.');
            return $this->redirectToRoute('app_events_index');
        }

        $blockingReason = $this->getReservationBlockingReason($event);
        if ($blockingReason !== null) {
            $this->addFlash('error', $blockingReason);
            return $this->redirectToRoute('app_events_show', ['id' => $event->getId()]);
        }

        $reservation = new Reservations();
        $reservation->setEvent($event);
        $reservation->setUser($currentUser);

        $form = $this->createForm(ReservationsType::class, $reservation, [
            'max_places' => $event->getPlacesDisponibles(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->refresh($event);

            $blockingReason = $this->getReservationBlockingReason($event);
            if ($blockingReason !== null) {
                $this->addFlash('error', $blockingReason);
                return $this->redirectToRoute('app_events_show', ['id' => $event->getId()]);
            }
            
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

            // ── Calculs automatiques selon la structure de table ───────────────────────────────
            $reservation->setNombrePersonnes($total); // nombre_personnes = nombre_adultes + nombre_enfants
            $reservation->setPrixTotal(
                number_format((float) $event->getPrix() * $total, 2, '.', '') // prix_total = (nombre_enfants + nombre_adultes) × prix_event
            );
            $reservation->setStatut('en_attente'); // statut par défaut
            // date_creation est déjà défini dans le constructeur de l'entité

            $em->persist($reservation);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Réservation créée en attente ! %d personne(s) — %s TND.',
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
    public function show(?Reservations $reservation): Response
    {
        if (!$reservation instanceof Reservations) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirectToRoute('app_reservations_index');
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyUnlessReservationOwner($reservation);

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
        ?Reservations $reservation,
        EntityManagerInterface $em
    ): Response {
        if (!$reservation instanceof Reservations) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirectToRoute('app_reservations_index');
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyUnlessReservationOwner($reservation);

        $event = $reservation->getEvent();
        $originalTotal = $reservation->getNombrePersonnes();
        $originalStatus = $reservation->getStatut();

        $isConfirmedStatus = in_array($reservation->getStatut(), ['confirmee', 'accepte'], true);

        $form = $this->createForm(ReservationsType::class, $reservation, [
            'max_places' => $event
                ? $event->getPlacesDisponibles() + ($isConfirmedStatus ? $reservation->getNombrePersonnes() : 0)
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

                if (in_array($originalStatus, ['confirmee', 'accepte'], true)) {
                    $delta = $total - $originalTotal;

                    if ($delta > 0 && $delta > $event->getPlacesDisponibles()) {
                        $this->addFlash('error', sprintf(
                            'Seulement %d place(s) disponible(s).',
                            $event->getPlacesDisponibles()
                        ));

                        return $this->render('reservations/edit.html.twig', [
                            'form'        => $form->createView(),
                            'reservation' => $reservation,
                            'event'       => $event,
                        ]);
                    }

                    if ($delta !== 0) {
                        $event->setPlacesDisponibles($event->getPlacesDisponibles() - $delta);
                        $em->persist($event);
                    }
                }
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
        ?Reservations $reservation,
        EntityManagerInterface $em
    ): Response {
        if (!$reservation instanceof Reservations) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirectToRoute('app_reservations_index');
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyUnlessReservationOwner($reservation);

        if ($this->isCsrfTokenValid('delete'.$reservation->getId(), $request->request->get('_token'))) {
            $event = $reservation->getEvent();
            if ($event && in_array($reservation->getStatut(), ['confirmee', 'accepte'], true)) {
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
        Request $request, ?Reservations $reservation, EntityManagerInterface $em
    ): Response {
        if (!$reservation instanceof Reservations) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirectToRoute('app_reservations_index');
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyUnlessReservationOwner($reservation);

        if ($this->isCsrfTokenValid('confirm'.$reservation->getId(), $request->request->get('_token'))) {
            $event = $reservation->getEvent();

            if (in_array($reservation->getStatut(), ['confirmee', 'accepte'], true)) {
                $this->addFlash('info', 'Cette réservation est déjà confirmée.');
                return $this->redirectToRoute('app_reservations_index');
            }

            if (!$event) {
                $this->addFlash('error', 'Événement introuvable pour cette réservation.');
                return $this->redirectToRoute('app_reservations_index');
            }

            if ($reservation->getNombrePersonnes() > $event->getPlacesDisponibles()) {
                $this->addFlash('error', sprintf(
                    'Impossible de confirmer: seulement %d place(s) disponible(s).',
                    $event->getPlacesDisponibles()
                ));
                return $this->redirectToRoute('app_reservations_index');
            }

            $event->setPlacesDisponibles($event->getPlacesDisponibles() - $reservation->getNombrePersonnes());
            $reservation->setStatut('confirmee');
            $em->persist($event);
            $em->flush(); // Va déclencher le ReservationStatusListener (Envoi email !)
            $this->addFlash('success', 'Réservation confirmée. Le ticket virtuel part par email !');
        }
        return $this->redirectToRoute('app_reservations_index');
    }

    #[Route('/{id}/cancel', name: 'app_reservations_cancel', methods: ['POST'])]
    public function cancel(
        Request $request, ?Reservations $reservation, EntityManagerInterface $em
    ): Response {
        if (!$reservation instanceof Reservations) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirectToRoute('app_reservations_index');
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyUnlessReservationOwner($reservation);

        if ($this->isCsrfTokenValid('cancel'.$reservation->getId(), $request->request->get('_token'))) {
            $event = $reservation->getEvent();

            if (in_array($reservation->getStatut(), ['confirmee', 'accepte'], true) && $event) {
                $event->setPlacesDisponibles($event->getPlacesDisponibles() + $reservation->getNombrePersonnes());
                $em->persist($event);
            }

            $reservation->setStatut('annulee');
            $em->flush(); // Va déclencher le ReservationStatusListener (Envoi email !)
            $this->addFlash('success', 'Réservation annulée.');
        }
        return $this->redirectToRoute('app_reservations_index');
    }
}