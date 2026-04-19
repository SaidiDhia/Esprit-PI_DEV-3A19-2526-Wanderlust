<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service IA utilisant l'API Groq (gratuit, 30 req/min, modèles Llama 3).
 * Clé gratuite : https://console.groq.com/keys
 */
class GeminiService
{
    // Groq API — format OpenAI-compatible
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODEL   = 'llama-3.1-8b-instant'; // Rapide, gratuit, très capable

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {}

    /**
     * Génère du texte via Groq à partir d'un prompt.
     */
    public function generate(string $prompt): string
    {
        if (empty($this->apiKey) || $this->apiKey === 'your_key_here') {
            throw new \RuntimeException('Clé API non configurée. Ajoutez GEMINI_API_KEY (clé Groq) dans .env.local');
        }

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'       => self::MODEL,
                'messages'    => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.7,
                'max_tokens'  => 600,
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 429) {
            throw new \RuntimeException('Limite de requêtes atteinte (429). Attendez quelques secondes.');
        }

        $data = $response->toArray();

        return $data['choices'][0]['message']['content']
            ?? throw new \RuntimeException('Réponse Groq invalide.');
    }

    /**
     * Génère une description enrichie pour une activité.
     */
    public function enhanceActivityDescription(string $titre, string $typeActivite, string $categorie): string
    {
        $prompt = <<<PROMPT
Tu es un expert en activités de plein air et de voyage en Tunisie.
Génère une description attractive et détaillée (3-4 phrases, maximum 200 mots) pour l'activité suivante :

- Titre : {$titre}
- Type : {$typeActivite}
- Catégorie : {$categorie}

La description doit :
- Être en français, claire et engageante
- Décrire l'expérience vécue par le participant
- Mentionner les sensations et l'environnement
- Être adaptée à une plateforme de réservation touristique
- Ne pas mentionner de prix

Réponds UNIQUEMENT avec la description, sans introduction ni explication.
PROMPT;

        return trim($this->generate($prompt));
    }

    /**
     * Génère la liste des équipements nécessaires pour un event selon ses activités.
     *
     * @param array<string> $activitiesInfo  Tableau de chaînes "titre - type - categorie"
     */
    public function enhanceEventEquipment(array $activitiesInfo): string
    {
        $activitiesText = implode("\n- ", $activitiesInfo);

        $prompt = <<<PROMPT
Tu es un conseiller expert en événements outdoor et de voyage aventure.
Pour un événement qui comprend ces activités :
- {$activitiesText}

Génère une liste complète et pratique des équipements et matériels nécessaires pour les participants.

Format souhaité :
- Commence par une phrase d'introduction courte
- Liste les équipements par catégories (ex: Vêtements, Équipements sportifs, Sécurité, Divers)
- Utilise des emojis pour chaque item
- Ajoute des instructions importantes ou conseils de sécurité à la fin
- Maximum 250 mots
- Langue : français

Réponds UNIQUEMENT avec le contenu formaté, sans introduction ni explication supplémentaire.
PROMPT;

        return trim($this->generate($prompt));
    }
}
