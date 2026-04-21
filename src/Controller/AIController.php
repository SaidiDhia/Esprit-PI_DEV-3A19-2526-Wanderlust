<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIController extends AbstractController
{
    private HttpClientInterface $httpClient;
    private string $geminiApiKey;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        $this->geminiApiKey = $_ENV['GEMINI_API_KEY2'] ?? '';
    }

    #[Route('/api/ai/activity-description', name: 'api_ai_activity_description', methods: ['POST'])]
    public function generateActivityDescription(Request $request): JsonResponse
    {
        try {
            $content = $request->getContent();
            $data = json_decode($content, true);
            
            $titre = $data['titre'] ?? '';
            $type = $data['type_activite'] ?? '';
            $categorie = $data['categorie'] ?? '';

            if (empty($titre)) {
                return $this->json(['error' => 'Le titre est requis'], 400);
            }

            // Si pas de clé API, retourner une description de base
            if (empty($this->geminiApiKey)) {
                $description = $this->generateBasicDescription($titre, $type, $categorie);
                return $this->json(['description' => $description]);
            }

            // Appel à l'API Gemini
            $description = $this->callGeminiAPI($titre, $type, $categorie);
            
            if ($description) {
                return $this->json(['description' => $description]);
            }

            return $this->json(['error' => 'Impossible de générer la description'], 500);

        } catch (\Exception $e) {
            // Fallback en cas d'erreur
            $data = json_decode($request->getContent(), true);
            $titre = $data['titre'] ?? '';
            $type = $data['type_activite'] ?? '';
            $categorie = $data['categorie'] ?? '';
            $description = $this->generateBasicDescription($titre, $type, $categorie);
            return $this->json(['description' => $description]);
        }
    }

    #[Route('/api/ai/activity-equipment', name: 'api_ai_activity_equipment', methods: ['POST'])]
    public function generateActivityEquipment(Request $request): JsonResponse
    {
        try {
            $content = $request->getContent();
            $data = json_decode($content, true);
            
            $titre = $data['titre'] ?? '';
            $type_activite = $data['type_activite'] ?? '';
            $categorie = $data['categorie'] ?? '';

            if (empty($titre)) {
                return $this->json(['error' => 'Le titre de l\'activité est requis'], 400);
            }

            // Créer un tableau d'activités au format attendu par les méthodes existantes
            $activities = [[
                'titre' => $titre,
                'type_activite' => $type_activite,
                'categorie' => $categorie
            ]];

            // Si pas de clé API, retourner des équipements de base
            if (empty($this->geminiApiKey)) {
                $equipment = $this->generateBasicEquipment($activities);
                return $this->json(['equipment' => $equipment]);
            }

            // Appel à l'API Gemini
            $equipment = $this->callGeminiEquipmentAPI($activities);
            
            if ($equipment) {
                return $this->json(['equipment' => $equipment]);
            }

            return $this->json(['error' => 'Impossible de générer les équipements'], 500);

        } catch (\Exception $e) {
            // Fallback en cas d'erreur
            $data = json_decode($request->getContent(), true);
            $titre = $data['titre'] ?? '';
            $type_activite = $data['type_activite'] ?? '';
            $categorie = $data['categorie'] ?? '';
            
            $activities = [[
                'titre' => $titre,
                'type_activite' => $type_activite,
                'categorie' => $categorie
            ]];
            
            $equipment = $this->generateBasicEquipment($activities);
            return $this->json(['equipment' => $equipment]);
        }
    }

    #[Route('/api/ai/event-equipment', name: 'api_ai_event_equipment', methods: ['POST'])]
    public function generateEventEquipment(Request $request): JsonResponse
    {
        try {
            $content = $request->getContent();
            $data = json_decode($content, true);
            
            $activities = $data['activities'] ?? [];

            if (empty($activities)) {
                return $this->json(['error' => 'Les activités sont requises'], 400);
            }

            // Si pas de clé API, retourner des équipements de base
            if (empty($this->geminiApiKey)) {
                $equipment = $this->generateBasicEquipment($activities);
                return $this->json(['equipment' => $equipment]);
            }

            // Appel à l'API Gemini
            $equipment = $this->callGeminiEquipmentAPI($activities);
            
            if ($equipment) {
                return $this->json(['equipment' => $equipment]);
            }

            return $this->json(['error' => 'Impossible de générer les équipements'], 500);

        } catch (\Exception $e) {
            // Fallback en cas d'erreur
            $data = json_decode($request->getContent(), true);
            $activities = $data['activities'] ?? [];
            $equipment = $this->generateBasicEquipment($activities);
            return $this->json(['equipment' => $equipment]);
        }
    }

    private function generateBasicDescription(string $titre, string $type, string $categorie): string
    {
        $templates = [
            'desert' => "Découvrez une expérience inoubliable dans le désert tunisien avec {$titre}. Cette activité vous fera vivre des moments uniques au cœur des dunes dorées, alliant aventure et découverte culturelle. Profitez de paysages spectaculaires et d'une immersion totale dans l'authentique vie saharienne.",
            'Mer' => "Vivez une aventure aquatique exceptionnelle avec {$titre}. Cette activité maritime vous offre l'opportunité de découvrir les magnifiques côtes tunisiennes dans une atmosphère de plaisir et d'adrénaline. Parfait pour les amateurs de sports nautiques et de découvertes marines.",
            'Aérien' => "Envolez-vous pour une expérience unique avec {$titre}. Cette activité aérienne vous offre une perspective spectaculaire sur les paysages tunisiens, mêlant sensations fortes et vues à couper le souffle. Une aventure inoubliable au-dessus des plus beaux sites du pays.",
            'nature' => "Connectez-vous à la nature avec {$titre}. Cette activité vous permet d'explorer les richesses naturelles de la Tunisie dans une approche respectueuse de l'environnement. Idéal pour les amoureux de la faune, de la flore et des paysages préservés.",
            'Culture' => "Plongez dans l'histoire et la culture tunisienne avec {$titre}. Cette activité culturelle vous offre une immersion fascinante dans le patrimoine riche et diversifié de la Tunisie. Découvrez des traditions séculaires et des sites d'une valeur historique inestimable."
        ];

        $template = $templates[$categorie] ?? $templates['nature'];
        
        if (!empty($type)) {
            $template .= " Spécialement conçue pour les passionnés de {$type}, cette activité promet des souvenirs impérissables.";
        }

        return $template;
    }

    private function generateBasicEquipment(array $activities): string
    {
        $equipment = "Équipements recommandés pour votre événement :\n\n";
        
        $equipment .= "🎒 **Équipement Personnel :**\n";
        $equipment .= "- Vêtements confortables et adaptés à l'activité\n";
        $equipment .= "- Chaussures fermées et antidérapantes\n";
        $equipment .= "- Lunettes de soleil et crème solaire\n";
        $equipment .= "- Chapeau ou casquette\n\n";
        
        $equipment .= "🥤 **Ravitaillement :**\n";
        $equipment .= "- Eau en quantité suffisante\n";
        $equipment .= "- Encas et barres énergétiques\n";
        $equipment .= "- Repas légers si activité prolongée\n\n";
        
        $equipment .= "📱 **Équipement Électronique :**\n";
        $equipment .= "- Téléphone portable chargé\n";
        $equipment .= "- Power bank ou batterie externe\n";
        $equipment .= "- Appareil photo pour immortaliser les moments\n\n";
        
        $equipment .= "🏥 **Sécurité :**\n";
        $equipment .= "- Trousse de premiers secours\n";
        $equipment .= "- Matériel de sécurité spécifique à chaque activité\n";
        $equipment .= "- Informations d'urgence et contacts\n\n";
        
        $equipment .= "📋 **Documents :**\n";
        $equipment .= "- Pièce d'identité\n";
        $equipment .= "- Autorisations et permis si nécessaire\n";
        $equipment .= "- Informations de réservation\n\n";
        
        $equipment .= "*Cette liste est une recommandation de base. Adaptez l'équipement selon les activités spécifiques et les conditions météorologiques.*";

        return $equipment;
    }

    private function callGeminiAPI(string $titre, string $type, string $categorie): ?string
    {
        if (empty($this->geminiApiKey)) {
            return null;
        }

        $prompt = "Génère une description attrayante et professionnelle (150-250 mots) pour une activité touristique en Tunisie.

Titre: {$titre}
Type: {$type}
Catégorie: {$categorie}

La description doit:
- Être écrite dans un ton engageant et professionnel
- Mettre en valeur l'expérience unique en Tunisie
- Inclure des éléments sensoriels et émotionnels
- Être optimisée pour attirer les touristes
- Avoir entre 150 et 250 mots maximum
- Être en français";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$this->geminiApiKey}";
        
        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]
        ]);

        if ($response->getStatusCode() === 200) {
            $data = $response->toArray();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return trim($text);
        }

        return null;
    }

    private function callGeminiEquipmentAPI(array $activities): ?string
    {
        if (empty($this->geminiApiKey)) {
            return null;
        }

        $activitiesText = '';
        foreach ($activities as $activity) {
            $activitiesText .= "- {$activity['titre']}: {$activity['type_activite']}\n";
        }

        $prompt = "Génère une liste complète et structurée d'équipements nécessaires pour un événement combinant les activités suivantes:

{$activitiesText}

La liste doit:
- Être organisée par catégories (Équipement Personnel, Sécurité, Technique, etc.)
- Inclure des quantités recommandées
- Distinguer entre équipements obligatoires et optionnels
- Être pratique et facile à utiliser
- Être en français
- Utiliser des émojis pour organiser les catégories";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$this->geminiApiKey}";
        
        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]
        ]);

        if ($response->getStatusCode() === 200) {
            $data = $response->toArray();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return trim($text);
        }

        return null;
    }
}
