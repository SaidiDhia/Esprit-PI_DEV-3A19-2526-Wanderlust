<?php

namespace App\Controller;

use App\Enum\CategorieActiviteEnum;
use App\Repository\EventsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class EventSuggestionsController extends AbstractController
{
    #[Route('/events/suggestions', name: 'app_events_suggestions', methods: ['GET'])]
    public function suggestions(Request $request, EventsRepository $eventsRepository): Response
    {
        $suggestionType  = $request->query->get('suggestion_type', '');
        $category        = $request->query->get('category', '');
        $participantCount = (int) $request->query->get('participant_count', 0);

        // Paramètres mode enfants
        $childrenFriendly = $request->query->get('children_friendly', '') === '1';
        $minAge           = $request->query->has('min_age') ? (int) $request->query->get('min_age') : null;
        $nbrEnfants       = $request->query->has('nbr_enfants') ? (int) $request->query->get('nbr_enfants') : null;

        // Paramètres localisation
        $city = trim($request->query->get('city', ''));

        $suggestions = [];

        switch ($suggestionType) {
            case 'category':
                $suggestions = $eventsRepository->findByCategorySuggestion(
                    $category ?: null,
                    $participantCount > 0 ? $participantCount : null
                );
                break;

            case 'age':
                $suggestions = $eventsRepository->findByAgeSuggestion(
                    $childrenFriendly,
                    $minAge,
                    $nbrEnfants
                );
                break;

            case 'location':
                $suggestions = $eventsRepository->findByLocationSuggestion(
                    $city ?: null,
                    $participantCount > 0 ? $participantCount : null
                );
                break;

            default:
                $suggestions = $eventsRepository->findGeneralSuggestions(
                    $participantCount > 0 ? $participantCount : null
                );
                break;
        }

        // Passer toutes les catégories disponibles à la vue
        $categories = CategorieActiviteEnum::cases();

        return $this->render('events/suggestions.html.twig', [
            'suggestions'      => $suggestions,
            'categories'       => $categories,
            'suggestion_type'  => $suggestionType,
            'selected_category'=> $category,
            'participant_count'=> $participantCount,
            'children_friendly'=> $childrenFriendly,
            'min_age'          => $minAge,
            'nbr_enfants'      => $nbrEnfants,
            'city'             => $city,
        ]);
    }
}
