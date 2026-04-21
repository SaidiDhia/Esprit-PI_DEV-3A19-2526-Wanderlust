<?php

namespace App\Controller;

use App\Entity\Events;
use App\Entity\Activities;
use App\Entity\Reservations;
use App\Repository\EventsRepository;
use App\Repository\ActivitiesRepository;
use App\Repository\ReservationsRepository;
use App\Service\GeminiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/ai')]
class AiEnhanceController extends AbstractController
{
    private string $groqApiKey;
    /** @var string[] */
    private array $visionApiKeys = [];

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly HttpClientInterface $httpClient,
        private readonly EventsRepository $eventsRepo,
        private readonly ActivitiesRepository $activitiesRepo,
        private readonly ReservationsRepository $reservationsRepo,
    ) {
        // Keep these keys optional so missing env vars never break controller instantiation.
        $this->groqApiKey = trim((string) ($_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?: ''));

        $this->visionApiKeys = $this->collectVisionApiKeys([
            (string) ($_ENV['GOOGLE_VISION_API_KEY'] ?? getenv('GOOGLE_VISION_API_KEY') ?: ''),
            (string) ($_ENV['GEMINI_API_KEY1'] ?? getenv('GEMINI_API_KEY1') ?: ''),
            (string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: ''),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Modération d'image — Analyse sécurité (violence / contenu adulte / enfants)
    //  POST /api/ai/moderate-image
    //  Body: multipart/form-data avec champ "image" (fichier) OU JSON { imageBase64, mimeType }
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/moderate-image', name: 'api_ai_moderate_image', methods: ['POST'])]
    public function moderateImage(Request $request): JsonResponse
    {
        try {
            // ── Récupérer l'image ────────────────────────────────────────────
            $imageBase64 = null;
            $mimeType = 'image/jpeg';

            // Cas 1 : fichier uploadé (multipart)
            $uploadedFile = $request->files->get('image');
            if ($uploadedFile) {
                $mimeType = $this->resolveUploadedImageMimeType($uploadedFile);
                $rawImage = @file_get_contents($uploadedFile->getPathname());
                if (!is_string($rawImage) || $rawImage === '') {
                    return $this->json(['error' => 'Impossible de lire le fichier image telecharge.'], 400);
                }

                $imageBase64 = base64_encode($rawImage);
            } else {
                // Cas 2 : JSON { imageBase64, mimeType }
                $data = json_decode($request->getContent(), true);
                if (is_array($data)) {
                    $imageBase64 = isset($data['imageBase64']) ? (string) $data['imageBase64'] : null;
                    $mimeType = isset($data['mimeType']) && is_string($data['mimeType']) && $data['mimeType'] !== ''
                        ? $data['mimeType']
                        : 'image/jpeg';
                }
            }

            if (!is_string($imageBase64) || trim($imageBase64) === '') {
                return $this->json(['error' => 'Aucune image fournie.'], 400);
            }

            // ── Prompt d'analyse de modération ──────────────────────────────
            $prompt = <<<PROMPT
Analyse cette image pour une plateforme de tourisme familiale.
Réponds UNIQUEMENT avec ce JSON sur UNE SEULE LIGNE, sans markdown :
{"violent":false,"adultContent":false,"suitableForKids":true,"safe":true,"confidence":95,"reason":"courte raison en francais max 20 mots"}

Critères :
- violent: true si violence/armes/sang/chocs
- adultContent: true si contenu sexuel explicite
- suitableForKids: true si approprié pour enfants <12 ans
- safe: true si globalement sûr pour plateforme touristique
- confidence: 0-100
- reason: max 20 mots en français
PROMPT;

            // ── Appel Gemini Vision (gemini-1.5-flash) ───────────────────────
            if ($this->visionApiKeys === []) {
                throw new \RuntimeException('Clé API Gemini Vision non configurée.');
            }

            // Essayer plusieurs modèles (fallback si quota dépassé)
            $models = ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-2.0-flash-lite'];
            $lastError = null;
            $responseData = null;

            foreach ($this->visionApiKeys as $apiKey) {
                foreach ($models as $model) {
                    try {
                        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;

                        $response = $this->httpClient->request('POST', $apiUrl, [
                            'headers' => ['Content-Type' => 'application/json'],
                            'json'    => [
                                'contents' => [[
                                    'parts' => [
                                        ['text' => $prompt],
                                        ['inline_data' => [
                                            'mime_type' => $mimeType,
                                            'data'      => $imageBase64,
                                        ]],
                                    ]
                                ]],
                                'generationConfig' => [
                                    'temperature'     => 0.1,
                                    'maxOutputTokens' => 512,  // Assez pour tout le JSON
                                ],
                            ],
                        ]);

                        $statusCode = $response->getStatusCode();
                        if ($statusCode === 429) {
                            $lastError = "Quota dépassé pour {$model}, tentative suivante…";
                            continue; // Essayer le modèle suivant
                        }

                        $responseData = $response->toArray();
                        break 2; // Succès
                    } catch (\Throwable $e) {
                        if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), '403')) {
                            $lastError = $e->getMessage();
                            continue;
                        }

                        throw $e;
                    }
                }
            }

            if (!$responseData) {
                // Tous les modèles ont répondu 429 → quota épuisé
                return $this->json([
                    'status'         => 'quota',
                    'safe'           => false,
                    'violent'        => false,
                    'suitableForKids'=> false,
                    'confidence'     => 0,
                    'reason'         => 'Quota API Gemini Vision épuisé (429). Réessayez dans 1 minute.',
                ], 200);
            }

            $rawText = $responseData['candidates'][0]['content']['parts'][0]['text']
                ?? throw new \RuntimeException('Réponse Gemini Vision vide.');

            // ── Extraction robuste du JSON ─────────────────────────────────
            // 1. Supprimer les blocs markdown ```json ... ```
            $cleanText = preg_replace('/```(?:json)?\s*/i', '', $rawText);
            $cleanText = preg_replace('/```/', '', $cleanText);
            $cleanText = trim($cleanText);

            // 2. Extraire le premier objet JSON valide avec regex
            $result = null;
            if (preg_match('/\{[^{}]+\}/s', $cleanText, $matches)) {
                $result = json_decode($matches[0], true);
            }

            // 3. Fallback: essayer le texte entier
            if (!is_array($result)) {
                $result = json_decode($cleanText, true);
            }

            // 4. Toujours pas? → extraire champ par champ avec regex
            if (!is_array($result)) {
                preg_match('/"violent"\s*:\s*(true|false)/i',      $rawText, $m1);
                preg_match('/"adultContent"\s*:\s*(true|false)/i', $rawText, $m2);
                preg_match('/"suitableForKids"\s*:\s*(true|false)/i', $rawText, $m3);
                preg_match('/"safe"\s*:\s*(true|false)/i',         $rawText, $m4);
                preg_match('/"confidence"\s*:\s*(\d+)/i',          $rawText, $m5);
                preg_match('/"reason"\s*:\s*"([^"]+)"/i',          $rawText, $m6);

                $result = [
                    'violent'         => ($m1[1] ?? 'false') === 'true',
                    'adultContent'    => ($m2[1] ?? 'false') === 'true',
                    'suitableForKids' => ($m3[1] ?? 'true')  === 'true',
                    'safe'            => ($m4[1] ?? 'true')  === 'true',
                    'confidence'      => (int)($m5[1] ?? 85),
                    'reason'          => $m6[1] ?? 'Analyse partielle effectuée.',
                ];
                // Si toujours incohérent, lever une exception
                if (!isset($m1[1]) && !isset($m4[1])) {
                    throw new \RuntimeException('JSON invalide reçu : ' . mb_substr($rawText, 0, 200));
                }
            }

            // Garantir les champs attendus
            $isSafe    = (bool)($result['safe']            ?? true);
            $isViolent = (bool)($result['violent']         ?? false);
            $isAdult   = (bool)($result['adultContent']    ?? false);
            $isKidSafe = (bool)($result['suitableForKids'] ?? true);

            return $this->json([
                'status'         => $isSafe ? 'safe' : 'unsafe',
                'safe'           => $isSafe,
                'violent'        => $isViolent,
                'adultContent'   => $isAdult,
                'suitableForKids'=> $isKidSafe,
                'confidence'     => (int)($result['confidence'] ?? 90),
                'reason'         => (string)($result['reason']  ?? 'Analyse effectuée.'),
            ]);

        } catch (\Throwable $e) {
            $msg = $this->sanitizeExternalErrorMessage($e->getMessage());
            // Déterminer le type d'erreur
            if (str_contains($msg, '429') || str_contains($msg, 'Too Many')) {
                $status = 'quota';
                $reason = '⏳ Quota API dépassé. Réessayez dans 1 minute.';
            } elseif (str_contains($msg, '403') || str_contains(mb_strtolower($msg), 'forbidden')) {
                $status = 'unavailable';
                $reason = '⚠️ Analyse IA indisponible pour le moment (clé API Google Vision non autorisée).';
            } elseif (str_contains($msg, '400')) {
                $status = 'error';
                $reason = '⚠️ Image non lisible par l\'IA (format non supporté).';
            } else {
                $status = 'error';
                $reason = '⚠️ Service d\'analyse indisponible. Réessayez plus tard.';
            }
            return $this->json([
                'status'         => $status,
                'safe'           => false,
                'violent'        => false,
                'suitableForKids'=> false,
                'confidence'     => 0,
                'reason'         => $reason,
                'debug'          => $msg,
            ], 200);
        }
    }

    private function sanitizeExternalErrorMessage(string $message): string
    {
        $sanitized = preg_replace('/([?&]key=)[^&\s\"]+/i', '$1[REDACTED]', $message);
        return is_string($sanitized) ? $sanitized : $message;
    }

    /**
     * @param string[] $rawKeys
     * @return string[]
     */
    private function collectVisionApiKeys(array $rawKeys): array
    {
        $keys = [];
        foreach ($rawKeys as $rawKey) {
            $trimmed = trim($rawKey);
            if ($trimmed === '') {
                continue;
            }

            if (!in_array($trimmed, $keys, true)) {
                $keys[] = $trimmed;
            }
        }

        return $keys;
    }

    private function resolveUploadedImageMimeType(object $uploadedFile): string
    {
        // 1) Try server-side MIME guesser (requires php_fileinfo in many setups).
        try {
            if (method_exists($uploadedFile, 'getMimeType')) {
                $serverMime = (string) $uploadedFile->getMimeType();
                if ($serverMime !== '' && str_starts_with($serverMime, 'image/')) {
                    return $serverMime;
                }
            }
        } catch (\Throwable) {
        }

        // 2) Fallback to browser-provided MIME type.
        try {
            if (method_exists($uploadedFile, 'getClientMimeType')) {
                $clientMime = (string) $uploadedFile->getClientMimeType();
                if ($clientMime !== '' && str_starts_with($clientMime, 'image/')) {
                    return $clientMime;
                }
            }
        } catch (\Throwable) {
        }

        // 3) Final fallback based on extension.
        $extension = '';
        try {
            if (method_exists($uploadedFile, 'getClientOriginalExtension')) {
                $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
            }
        } catch (\Throwable) {
        }

        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/jpeg',
        };
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Description d'activité
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/activity-description', name: 'api_ai_activity_description', methods: ['POST'])]
    public function enhanceActivityDescription(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps de requete JSON invalide.'], 400);
        }

        $titre     = trim($data['titre']        ?? '');
        $type      = trim($data['type_activite'] ?? '');
        $categorie = trim($data['categorie']     ?? '');

        if (!$titre && !$type && !$categorie) {
            return $this->json(['error' => 'Veuillez remplir au moins le titre, le type ou la catégorie.'], 400);
        }

        try {
            $description = $this->gemini->enhanceActivityDescription(
                $titre     ?: 'Activité aventure',
                $type      ?: 'Activité outdoor',
                $categorie ?: 'Nature'
            );
            return $this->json(['description' => $description]);
        } catch (\Throwable $e) {
            $msg    = $e->getMessage();
            $status = str_contains($msg, '429') ? 429 : 500;
            $human  = $status === 429
                ? '⏳ Limite de requêtes atteinte. Attendez quelques secondes et réessayez.'
                : $msg;
            return $this->json(['error' => $human], $status);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Équipements d'événement
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/event-equipment', name: 'api_ai_event_equipment', methods: ['POST'])]
    public function enhanceEventEquipment(Request $request): JsonResponse
    {
        $data       = json_decode($request->getContent(), true);
        $activities = $data['activities'] ?? [];

        if (empty($activities)) {
            return $this->json(['error' => "Aucune activité associée. Sélectionnez d'abord des activités."], 400);
        }

        $activitiesInfo = [];
        foreach ($activities as $act) {
            $titre     = $act['titre']        ?? 'Activité';
            $type      = $act['type_activite'] ?? '';
            $categorie = $act['categorie']     ?? '';
            $activitiesInfo[] = implode(' - ', array_filter([$titre, $type, $categorie]));
        }

        try {
            $equipment = $this->gemini->enhanceEventEquipment($activitiesInfo);
            return $this->json(['equipment' => $equipment]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  WanderBot — Chatbot RAG (données réelles de la BDD)
    //  POST /api/ai/chat
    //  Body: { message: string, context: string, history: [{role, content}] }
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/chat', name: 'api_ai_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $data    = json_decode($request->getContent(), true);
        $message = trim($data['message'] ?? '');
        $context = trim($data['context'] ?? 'general');
        $history = $data['history'] ?? [];

        if ($message === '') {
            return $this->json(['error' => 'Message vide.'], 400);
        }

        // ── 1. Récupérer les données réelles de la BDD ─────────────────
        $dbContext = $this->buildDatabaseContext($context, $message);

        // ── 2. Prompt système avec données BDD injectées ───────────────
        $systemPrompt = $this->buildSystemPrompt($context, $dbContext);

        // ── 3. Construire l'historique de conversation ─────────────────
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice($history, -6) as $h) {
            if (in_array($h['role'] ?? '', ['user', 'assistant'], true)) {
                $messages[] = ['role' => $h['role'], 'content' => (string)($h['content'] ?? '')];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        // ── 4. Appel LLM ───────────────────────────────────────────────
        try {
            if ($this->groqApiKey === '') {
                throw new \RuntimeException('Clé API Groq non configurée (GROQ_API_KEY dans .env.local).');
            }

            $response = $this->httpClient->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->groqApiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => 'llama-3.1-8b-instant',
                    'messages'    => $messages,
                    'temperature' => 0.3,   // Plus faible = plus factuel sur les données BDD
                    'max_tokens'  => 400,
                ],
            ]);

            $responseData = $response->toArray();
            $reply = $responseData['choices'][0]['message']['content']
                ?? throw new \RuntimeException('Réponse invalide de Groq.');

            return $this->json(['reply' => trim($reply)]);

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '429')) {
                return $this->json(['reply' => '⏳ Je suis un peu débordé ! Réessayez dans quelques secondes.'], 200);
            }
            return $this->json(['reply' => '⚠️ Erreur : ' . $msg], 200);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  MÉTHODES PRIVÉES RAG
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Récupère et formate les données BDD selon le contexte et le message.
     */
    private function buildDatabaseContext(string $context, string $message): string
    {
        $lowerMsg = mb_strtolower($message);

        return match ($context) {
            'events'       => $this->buildEventsContext($lowerMsg),
            'activities'   => $this->buildActivitiesContext($lowerMsg),
            'reservations' => $this->buildReservationsContext(),
            default        => $this->buildEventsContext($lowerMsg) . "\n" . $this->buildActivitiesContext($lowerMsg),
        };
    }

    /**
     * Données événements de la BDD formatées en texte pour le LLM.
     */
    private function buildEventsContext(string $lowerMsg): string
    {
        try {
            // Charger tous les events à venir avec des places disponibles
            $events = $this->eventsRepo->createQueryBuilder('e')
                ->leftJoin('e.activities', 'a')
                ->addSelect('a')
                ->where('e.dateDebut >= :now')
                ->setParameter('now', new \DateTime())
                ->orderBy('e.dateDebut', 'ASC')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();

            if (empty($events)) {
                return "Aucun événement à venir trouvé dans la base de données.";
            }

            $lines = ["=== DONNÉES RÉELLES DES ÉVÉNEMENTS (base de données Wanderlust) ===\n"];
            foreach ($events as $event) {
                /** @var Events $event */
                $activitesTitres = [];
                foreach ($event->getActivities() as $act) {
                    $activitesTitres[] = $act->getTitre();
                }

                $status = $event->getStatus() ? $event->getStatus()->getLabel() : 'Inconnu';
                $dispo  = $event->getPlacesDisponibles();
                $cap    = $event->getCapaciteMax();
                $complet = ($dispo <= 0) ? 'COMPLET' : "{$dispo}/{$cap} places disponibles";

                $lines[] = sprintf(
                    "- ID:%d | Lieu: %s | Organisateur: %s | Prix: %.2f TND | Date: %s | %s | Statut: %s | Activités: %s",
                    $event->getId() ?? 0,
                    $event->getLieu() ?? 'N/A',
                    $event->getOrganisateur() ?? 'N/A',
                    (float)($event->getPrix() ?? 0),
                    $event->getDateDebut()?->format('d/m/Y') ?? 'N/A',
                    $complet,
                    $status,
                    implode(', ', $activitesTitres) ?: 'Aucune'
                );
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            return "Erreur lors de la récupération des événements : " . $e->getMessage();
        }
    }

    /**
     * Données activités de la BDD formatées pour le LLM.
     */
    private function buildActivitiesContext(string $lowerMsg): string
    {
        try {
            $activities = $this->activitiesRepo->findAll();

            if (empty($activities)) {
                return "Aucune activité trouvée dans la base de données.";
            }

            $lines = ["=== DONNÉES RÉELLES DES ACTIVITÉS (base de données Wanderlust) ===\n"];
            foreach ($activities as $act) {
                /** @var \App\Entity\Activities $act */
                $categorie = $act->getCategorie() ? $act->getCategorie()->getLabel() : 'N/A';
                $status    = $act->getStatus() ? $act->getStatus()->getLabel() : 'Inconnu';
                $ageMin    = $act->getAgeMinimum() ? "Âge min: {$act->getAgeMinimum()} ans" : "Tous âges";

                $lines[] = sprintf(
                    "- ID:%d | Titre: %s | Type: %s | Catégorie: %s | %s | Statut: %s | Description: %s",
                    $act->getId() ?? 0,
                    $act->getTitre() ?? 'N/A',
                    $act->getTypeActivite() ?? 'N/A',
                    $categorie,
                    $ageMin,
                    $status,
                    mb_substr(strip_tags($act->getDescription() ?? ''), 0, 80) . '...'
                );
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            return "Erreur lors de la récupération des activités : " . $e->getMessage();
        }
    }

    /**
     * Statistiques des réservations pour le LLM.
     */
    private function buildReservationsContext(): string
    {
        try {
            $user = $this->getUser();

            // Stats globales
            $totalEnAttente  = $this->reservationsRepo->countByStatut('en_attente');
            $totalConfirmees = $this->reservationsRepo->countByStatut('confirmee');
            $totalAnnulees   = $this->reservationsRepo->countByStatut('annulee');

            $lines = [
                "=== DONNÉES RÉELLES DES RÉSERVATIONS (base de données Wanderlust) ===\n",
                "Statistiques globales :",
                "- Réservations en attente : {$totalEnAttente}",
                "- Réservations confirmées : {$totalConfirmees}",
                "- Réservations annulées   : {$totalAnnulees}",
                "",
                "Processus de réservation Wanderlust :",
                "- Pour réserver : aller sur la page d'un événement et cliquer 'Réserver cet événement'",
                "- Statut 'en_attente' : réservation soumise, en cours de traitement",
                "- Statut 'confirmee' : réservation validée, le ticket e-ticket est disponible",
                "- Statut 'annulee' : réservation annulée",
                "- Pour annuler : aller dans Mes Réservations et supprimer",
            ];

            // Si l'utilisateur est connecté, afficher ses réservations
            if ($user) {
                $userEmail = method_exists($user, 'getEmail') ? $user->getEmail() : null;
                if ($userEmail) {
                    $userReservations = $this->reservationsRepo->findByEmail($userEmail);
                    if (!empty($userReservations)) {
                        $lines[] = "\nVos réservations personnelles :";
                        foreach (array_slice($userReservations, 0, 10) as $res) {
                            /** @var Reservations $res */
                            $eventLieu = $res->getEvent()?->getLieu() ?? 'N/A';
                            $eventDate = $res->getEvent()?->getDateDebut()?->format('d/m/Y') ?? 'N/A';
                            $lines[] = sprintf(
                                "- #%d | Événement: %s (%s) | %d personnes | %.2f TND | Statut: %s",
                                $res->getId() ?? 0,
                                $eventLieu,
                                $eventDate,
                                $res->getNombrePersonnes(),
                                (float)($res->getPrixTotal() ?? 0),
                                $res->getStatut()
                            );
                        }
                    } else {
                        $lines[] = "\nVous n'avez aucune réservation pour le moment.";
                    }
                }
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            return "Erreur lors de la récupération des réservations : " . $e->getMessage();
        }
    }

    /**
     * Construit le prompt système complet avec les données BDD injectées.
     */
    private function buildSystemPrompt(string $context, string $dbContext): string
    {
        $contextLabels = [
            'activities'   => 'les activités de plein air et d\'aventure',
            'events'       => 'les événements touristiques',
            'reservations' => 'les réservations',
        ];
        $contextLabel = $contextLabels[$context] ?? 'la plateforme Wanderlust';

        return <<<PROMPT
Tu es WanderBot 🤖, l'assistant intelligent de la plateforme Wanderlust (tourisme d'aventure en Tunisie).
Tu réponds aux questions sur {$contextLabel}.

RÈGLES IMPORTANTES :
1. Utilise EXCLUSIVEMENT les données ci-dessous pour répondre. Ne jamais inventer de données.
2. Si les données ne permettent pas de répondre, dis-le clairement.
3. Tes réponses : claires, structurées, en français, maximum 200 mots.
4. Pour les listes, utilise des puces ou numéros.
5. Mentionne les prix, dates et places disponibles quand c'est pertinent.

{$dbContext}

=== FIN DES DONNÉES ===
Réponds maintenant à la question de l'utilisateur en te basant UNIQUEMENT sur ces données réelles.
PROMPT;
    }
}
