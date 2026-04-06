<?php

namespace App\Controller;

use App\Repository\ActivitiesRepository;
use App\Repository\EventsRepository;
use App\Repository\ReservationsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(
        ActivitiesRepository $activitiesRepo,
        EventsRepository $eventsRepo,
        ReservationsRepository $reservationsRepo
    ): Response {
        $activities = $activitiesRepo->findAll();
        $events = $eventsRepo->findAll();

        $totalActivities = count($activities);
        $totalEvents = count($events);

        $eventsByStatus = [
            'en_attente' => 0,
            'accepte' => 0,
            'refuse' => 0,
        ];

        foreach ($events as $event) {
            $status = $event->getStatut();
            if (isset($eventsByStatus[$status])) {
                $eventsByStatus[$status]++;
            }
        }

        $activitiesByCategory = [];
        foreach ($activities as $activity) {
            $category = $activity->getCategorie()?->value ?? 'Non classé';
            if (!isset($activitiesByCategory[$category])) {
                $activitiesByCategory[$category] = 0;
            }
            $activitiesByCategory[$category]++;
        }

        $upcomingEvents = $eventsRepo->findUpcomingEvents(30);
        $recentReservations = $reservationsRepo->findRecent(5);
        $totalReservations = count($reservationsRepo->findAll());

        $totalRevenue = 0;
        foreach ($reservationsRepo->findAll() as $reservation) {
            if ($reservation->getStatut() === 'confirmee') {
                $totalRevenue += (float) $reservation->getPrixTotal();
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'totalActivities' => $totalActivities,
            'totalEvents' => $totalEvents,
            'totalReservations' => $totalReservations,
            'totalRevenue' => $totalRevenue,
            'eventsByStatus' => $eventsByStatus,
            'activitiesByCategory' => $activitiesByCategory,
            'upcomingEvents' => $upcomingEvents,
            'recentReservations' => $recentReservations,
            'activities' => $activities,
            'events' => $events,
        ]);
    }

    #[Route('/activities', name: 'admin_activities', methods: ['GET'])]
    public function manageActivities(ActivitiesRepository $repo): Response
    {
        return $this->render('admin/activities.html.twig', [
            'activities' => $repo->findAll(),
        ]);
    }

    #[Route('/events', name: 'admin_events', methods: ['GET'])]
    public function manageEvents(EventsRepository $repo): Response
    {
        return $this->render('admin/events.html.twig', [
            'events' => $repo->findAll(),
        ]);
    }

    #[Route('/event/{id}/status/{status}', name: 'admin_event_change_status', methods: ['POST'])]
    public function changeEventStatus(
        int $id,
        string $status,
        Request $request,
        EventsRepository $repo,
        EntityManagerInterface $em
    ): Response {
        $event = $repo->find($id);
        if (!$event) {
            $this->addFlash('error', 'Événement non trouvé');
            return $this->redirectToRoute('admin_events');
        }

        if (!$this->isCsrfTokenValid('change_status'.$event->getId(), $request->request->get('_token'))){
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_events');
        }

        if (!in_array($status, ['en_attente', 'accepte', 'refuse'], true)) {
            $this->addFlash('error', 'Statut invalide');
            return $this->redirectToRoute('admin_events');
        }

        $event->setStatut($status);
        $em->flush();

        $this->addFlash('success', sprintf('Statut de l\'événement "%s" changé en "%s"', $event->getTitre(), $event->getStatutLabel()));

        return $this->redirectToRoute('admin_events');
    }
}
