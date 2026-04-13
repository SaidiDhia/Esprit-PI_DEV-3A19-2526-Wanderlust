<?php

namespace App\Controller\Api;

use App\Enum\CategorieActiviteEnum;
use App\Service\TypeActiviteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class TypeActiviteController extends AbstractController
{
    public function __construct(
        private TypeActiviteService $typeService
    ) {}

    #[Route('/types-activite/{categorie}', name: 'api_types_activite', methods: ['GET'])]
    public function getTypesByCategorie(string $categorie): JsonResponse
    {
        try {
            // Nettoyer et normaliser la catégorie
            $categorie = trim(strtolower($categorie));
            
            // Mapping des valeurs URL vers les valeurs de l'enum
            $categorieMapping = [
                'desert' => CategorieActiviteEnum::DESERT,
                'mer' => CategorieActiviteEnum::MER,
                'aerien' => CategorieActiviteEnum::AERIEN,
                'nature' => CategorieActiviteEnum::NATURE,
                'culture' => CategorieActiviteEnum::CULTURE,
            ];
            
            $categorieEnum = $categorieMapping[$categorie] ?? null;
            
            if (!$categorieEnum) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Catégorie non valide. Valeurs acceptées: desert, mer, aerien, nature, culture',
                    'data' => []
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Récupérer les types depuis le service
            $types = $this->typeService->getTypesByCategorie($categorieEnum);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Types récupérés avec succès',
                'data' => $types
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur serveur: ' . $e->getMessage(),
                'data' => []
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}