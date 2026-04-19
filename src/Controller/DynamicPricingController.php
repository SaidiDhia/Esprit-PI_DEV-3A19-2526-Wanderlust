<?php

namespace App\Controller;

use App\Entity\DynamicPricingRule;
use App\Entity\Events;
use App\Entity\EventPriceHistory;
use App\Form\DynamicPricingRuleType;
use App\Service\DynamicPricingEngine;
use App\Repository\DynamicPricingRuleRepository;
use App\Repository\EventsRepository;
use App\Repository\EventPriceHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/pricing')]
#[IsGranted('ROLE_ADMIN')]
class DynamicPricingController extends AbstractController
{
    private DynamicPricingEngine $pricingEngine;
    private DynamicPricingRuleRepository $ruleRepository;
    private EventsRepository $eventsRepository;
    private EventPriceHistoryRepository $historyRepository;
    private EntityManagerInterface $entityManager;

    public function __construct(
        DynamicPricingEngine $pricingEngine,
        DynamicPricingRuleRepository $ruleRepository,
        EventsRepository $eventsRepository,
        EventPriceHistoryRepository $historyRepository,
        EntityManagerInterface $entityManager
    ) {
        $this->pricingEngine = $pricingEngine;
        $this->ruleRepository = $ruleRepository;
        $this->eventsRepository = $eventsRepository;
        $this->historyRepository = $historyRepository;
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'app_pricing_dashboard')]
    public function dashboard(): Response
    {
        $stats = $this->pricingEngine->getPricingStatistics();
        $recentChanges = $this->historyRepository->findRecentChanges(10);
        $eligibleEvents = $this->eventsRepository->findEligibleForDynamicPricing();
        $rules = $this->ruleRepository->findAll();

        return $this->render('pricing/dashboard.html.twig', [
            'stats' => $stats,
            'recent_changes' => $recentChanges,
            'eligible_events' => count($eligibleEvents),
            'rules' => $rules
        ]);
    }

    #[Route('/rules', name: 'app_pricing_rules')]
    public function rules(): Response
    {
        $rules = $this->ruleRepository->findAll();
        
        return $this->render('pricing/rules.html.twig', [
            'rules' => $rules
        ]);
    }

    #[Route('/rules/new', name: 'app_pricing_rules_new')]
    public function newRule(Request $request): Response
    {
        $rule = new DynamicPricingRule();
        $form = $this->createForm(DynamicPricingRuleType::class, $rule);
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($rule);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Règle de pricing créée avec succès');
            return $this->redirectToRoute('app_pricing_rules');
        }

        return $this->render('pricing/rules_form.html.twig', [
            'form' => $form->createView(),
            'rule' => $rule,
            'action' => 'create'
        ]);
    }

    #[Route('/rules/{id}/edit', name: 'app_pricing_rules_edit')]
    public function editRule(DynamicPricingRule $rule, Request $request): Response
    {
        $form = $this->createForm(DynamicPricingRuleType::class, $rule);
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Règle de pricing mise à jour avec succès');
            return $this->redirectToRoute('app_pricing_rules');
        }

        return $this->render('pricing/rules_form.html.twig', [
            'form' => $form->createView(),
            'rule' => $rule,
            'action' => 'edit'
        ]);
    }

    #[Route('/rules/{id}/delete', name: 'app_pricing_rules_delete', methods: ['POST'])]
    public function deleteRule(DynamicPricingRule $rule, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete'.$rule->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($rule);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Règle de pricing supprimée avec succès');
        }

        return $this->redirectToRoute('app_pricing_rules');
    }

    #[Route('/events', name: 'app_pricing_events')]
    public function events(): Response
    {
        $eligibleEvents = $this->eventsRepository->findEligibleForDynamicPricing();
        $lowOccupancyEvents = $this->eventsRepository->findLowOccupancyEvents(0.7);
        $upcomingEvents = $this->eventsRepository->findUpcomingEvents(72);

        return $this->render('pricing/events.html.twig', [
            'eligible_events' => $eligibleEvents,
            'low_occupancy_events' => $lowOccupancyEvents,
            'upcoming_events' => $upcomingEvents
        ]);
    }

    #[Route('/events/{id}/simulate', name: 'app_pricing_events_simulate')]
    public function simulateEvent(Events $event): Response
    {
        $scenarios = [
            ['name' => 'Situation actuelle', 'time_factor' => null, 'occupancy_factor' => null, 'popularity_factor' => null],
            ['name' => 'Urgence maximale', 'time_factor' => 1.0, 'occupancy_factor' => 0.8, 'popularity_factor' => 0.9],
            ['name' => 'Faible remplissage', 'time_factor' => 0.6, 'occupancy_factor' => 1.0, 'popularity_factor' => 0.5],
            ['name' => 'Popularité élevée', 'time_factor' => 0.4, 'occupancy_factor' => 0.3, 'popularity_factor' => 0.1],
            ['name' => 'Dernière minute', 'time_factor' => 0.9, 'occupancy_factor' => 0.7, 'popularity_factor' => 0.6]
        ];

        $simulations = $this->pricingEngine->simulatePricing($event, $scenarios);
        $currentPrice = $this->pricingEngine->getCurrentPrice($event);
        $occupancyStats = $this->eventsRepository->getOccupancyStats($event);

        return $this->render('pricing/simulate.html.twig', [
            'event' => $event,
            'current_price' => $currentPrice,
            'occupancy_stats' => $occupancyStats,
            'simulations' => $simulations
        ]);
    }

    #[Route('/events/{id}/apply', name: 'app_pricing_events_apply', methods: ['POST'])]
    public function applyPricing(Events $event, Request $request): Response
    {
        if ($this->isCsrfTokenValid('apply_pricing'.$event->getId(), $request->request->get('_token'))) {
            $history = $this->pricingEngine->applyDynamicPricing($event);
            
            if ($history) {
                $this->addFlash('success', sprintf(
                    'Prix mis à jour: %.2f$ -> %.2f$ (%.1f%% %s)',
                    (float)$history->getOldPrice(),
                    (float)$history->getNewPrice(),
                    abs($history->getDiscountPercentage() * 100),
                    $history->isPriceDecrease() ? 'réduction' : 'augmentation'
                ));
            } else {
                $this->addFlash('info', 'Aucun changement de prix nécessaire');
            }
        }

        return $this->redirectToRoute('app_pricing_events');
    }

    #[Route('/events/{id}/history', name: 'app_pricing_events_history')]
    public function eventHistory(Events $event): Response
    {
        $history = $this->pricingEngine->getEventPricingHistory($event, 30);
        
        return $this->render('pricing/history.html.twig', [
            'event' => $event,
            'history' => $history
        ]);
    }

    #[Route('/run', name: 'app_pricing_run', methods: ['POST'])]
    public function runPricing(Request $request): Response
    {
        if ($this->isCsrfTokenValid('run_pricing', $request->request->get('_token'))) {
            $results = $this->pricingEngine->runDynamicPricingBatch();
            
            $this->addFlash('success', sprintf(
                'Pricing dynamique exécuté: %d événements traités, %d prix mis à jour',
                $results['processed'],
                $results['updated']
            ));
            
            if ($results['errors'] > 0) {
                $this->addFlash('warning', sprintf('%d erreurs rencontrées', $results['errors']));
            }
        }

        return $this->redirectToRoute('app_pricing_dashboard');
    }

    #[Route('/history', name: 'app_pricing_history')]
    public function history(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = 20;
        
        $history = $this->historyRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            $limit,
            ($page - 1) * $limit
        );

        $total = $this->historyRepository->count([]);
        $pages = ceil($total / $limit);

        return $this->render('pricing/history_list.html.twig', [
            'history' => $history,
            'page' => $page,
            'pages' => $pages,
            'total' => $total
        ]);
    }

    #[Route('/stats', name: 'app_pricing_stats')]
    public function stats(): Response
    {
        $stats = $this->pricingEngine->getPricingStatistics();
        
        // Statistiques supplémentaires
        $recentChanges = $this->historyRepository->findRecentChanges(7);
        $priceChangesByReason = $this->historyRepository->getPriceChangesByReason(30);
        $averageDiscounts = $this->historyRepository->getAverageDiscountByDay(30);
        
        // Événements par statut
        $eligibleByStatut = $this->eventsRepository->countEligibleByStatut();
        
        return $this->render('pricing/stats.html.twig', [
            'stats' => $stats,
            'recent_changes' => $recentChanges,
            'price_changes_by_reason' => $priceChangesByReason,
            'average_discounts' => $averageDiscounts,
            'eligible_by_statut' => $eligibleByStatut
        ]);
    }

    #[Route('/api/events/{id}/price', name: 'app_pricing_api_current_price')]
    public function getCurrentPrice(Events $event): JsonResponse
    {
        $currentPrice = $this->pricingEngine->getCurrentPrice($event);
        $dynamicPrice = $this->pricingEngine->calculateDynamicPrice($event);
        
        return new JsonResponse([
            'event_id' => $event->getId(),
            'current_price' => $currentPrice,
            'dynamic_price' => $dynamicPrice,
            'price_difference' => $dynamicPrice ? $dynamicPrice - $currentPrice : 0,
            'occupancy_stats' => $this->eventsRepository->getOccupancyStats($event)
        ]);
    }

    #[Route('/api/run', name: 'app_pricing_api_run', methods: ['POST'])]
    public function apiRunPricing(): JsonResponse
    {
        try {
            $results = $this->pricingEngine->runDynamicPricingBatch();
            
            return new JsonResponse([
                'success' => true,
                'results' => $results,
                'message' => sprintf('Processed %d events, updated %d prices', 
                    $results['processed'], $results['updated'])
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/toggle-rule/{id}', name: 'app_pricing_toggle_rule', methods: ['POST'])]
    public function toggleRule(DynamicPricingRule $rule, Request $request): JsonResponse
    {
        if ($this->isCsrfTokenValid('toggle_rule'.$rule->getId(), $request->request->get('_token'))) {
            $rule->setActive(!$rule->isActive());
            $this->entityManager->flush();
            
            return new JsonResponse([
                'success' => true,
                'active' => $rule->isActive(),
                'message' => $rule->isActive() ? 'Règle activée' : 'Règle désactivée'
            ]);
        }

        return new JsonResponse(['success' => false], 403);
    }
}
