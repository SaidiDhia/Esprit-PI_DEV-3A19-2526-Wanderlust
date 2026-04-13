<?php

namespace App\Service;

use App\Enum\CategorieActiviteEnum;
use App\Enum\TypeActiviteEnum;

class TypeActiviteService
{
    // Types par catégorie (comme dans ton code JavaFX)
    private array $typesByCategorie = [];

    public function __construct()
    {
        $this->typesByCategorie = [
            CategorieActiviteEnum::DESERT->value => [
                ['nom' => TypeActiviteEnum::RANDONNEE_SAHARA->value, 'emoji' => TypeActiviteEnum::RANDONNEE_SAHARA->getIcone()],
                ['nom' => TypeActiviteEnum::TREKKING_DUNES->value, 'emoji' => TypeActiviteEnum::TREKKING_DUNES->getIcone()],
                ['nom' => TypeActiviteEnum::QUAD_BUGGY->value, 'emoji' => TypeActiviteEnum::QUAD_BUGGY->getIcone()],
                ['nom' => TypeActiviteEnum::MOTO_CROSS_DESERT->value, 'emoji' => TypeActiviteEnum::MOTO_CROSS_DESERT->getIcone()],
                ['nom' => TypeActiviteEnum::BALADE_DROMADAIRE->value, 'emoji' => TypeActiviteEnum::BALADE_DROMADAIRE->getIcone()],
                ['nom' => TypeActiviteEnum::NUIT_CAMPEMENT->value, 'emoji' => TypeActiviteEnum::NUIT_CAMPEMENT->getIcone()],
                ['nom' => TypeActiviteEnum::OBSERVATION_ETOILES->value, 'emoji' => TypeActiviteEnum::OBSERVATION_ETOILES->getIcone()],
            ],
            CategorieActiviteEnum::MER->value => [
                ['nom' => TypeActiviteEnum::JET_SKI->value, 'emoji' => TypeActiviteEnum::JET_SKI->getIcone()],
                ['nom' => TypeActiviteEnum::PARACHUTE_ASCENSIONNEL->value, 'emoji' => TypeActiviteEnum::PARACHUTE_ASCENSIONNEL->getIcone()],
                ['nom' => TypeActiviteEnum::PADDLE->value, 'emoji' => TypeActiviteEnum::PADDLE->getIcone()],
                ['nom' => TypeActiviteEnum::KAYAK->value, 'emoji' => TypeActiviteEnum::KAYAK->getIcone()],
                ['nom' => TypeActiviteEnum::PLANCHE_VOILE->value, 'emoji' => TypeActiviteEnum::PLANCHE_VOILE->getIcone()],
                ['nom' => TypeActiviteEnum::PLONGEE_SOUS_MARINE->value, 'emoji' => TypeActiviteEnum::PLONGEE_SOUS_MARINE->getIcone()],
                ['nom' => TypeActiviteEnum::SNORKELING->value, 'emoji' => TypeActiviteEnum::SNORKELING->getIcone()],
                ['nom' => TypeActiviteEnum::SORTIE_BATEAU->value, 'emoji' => TypeActiviteEnum::SORTIE_BATEAU->getIcone()],
                ['nom' => TypeActiviteEnum::PECHE_TOURISTIQUE->value, 'emoji' => TypeActiviteEnum::PECHE_TOURISTIQUE->getIcone()],
            ],
            CategorieActiviteEnum::AERIEN->value => [
                ['nom' => TypeActiviteEnum::PARACHUTISME->value, 'emoji' => TypeActiviteEnum::PARACHUTISME->getIcone()],
                ['nom' => TypeActiviteEnum::PARAPENTE->value, 'emoji' => TypeActiviteEnum::PARAPENTE->getIcone()],
                ['nom' => TypeActiviteEnum::ULM->value, 'emoji' => TypeActiviteEnum::ULM->getIcone()],
                ['nom' => TypeActiviteEnum::MONTGOLFIERE->value, 'emoji' => TypeActiviteEnum::MONTGOLFIERE->getIcone()],
            ],
            CategorieActiviteEnum::NATURE->value => [
                ['nom' => TypeActiviteEnum::RANDONNEE_FORET->value, 'emoji' => TypeActiviteEnum::RANDONNEE_FORET->getIcone()],
                ['nom' => TypeActiviteEnum::ESCALADE->value, 'emoji' => TypeActiviteEnum::ESCALADE->getIcone()],
                ['nom' => TypeActiviteEnum::CAMPING->value, 'emoji' => TypeActiviteEnum::CAMPING->getIcone()],
                ['nom' => TypeActiviteEnum::VTT->value, 'emoji' => TypeActiviteEnum::VTT->getIcone()],
                ['nom' => TypeActiviteEnum::SPELEOLOGIE->value, 'emoji' => TypeActiviteEnum::SPELEOLOGIE->getIcone()],
            ],
            CategorieActiviteEnum::CULTURE->value => [
                ['nom' => TypeActiviteEnum::VISITE_KSOUR->value, 'emoji' => TypeActiviteEnum::VISITE_KSOUR->getIcone()],
                ['nom' => TypeActiviteEnum::DECORS_FILMS->value, 'emoji' => TypeActiviteEnum::DECORS_FILMS->getIcone()],
                ['nom' => TypeActiviteEnum::VISITE_ARCHEOLOGIQUE->value, 'emoji' => TypeActiviteEnum::VISITE_ARCHEOLOGIQUE->getIcone()],
                ['nom' => TypeActiviteEnum::FESTIVALS->value, 'emoji' => TypeActiviteEnum::FESTIVALS->getIcone()],
                ['nom' => TypeActiviteEnum::TOURISME_HISTORIQUE->value, 'emoji' => TypeActiviteEnum::TOURISME_HISTORIQUE->getIcone()],
                ['nom' => TypeActiviteEnum::PHOTOGRAPHIE->value, 'emoji' => TypeActiviteEnum::PHOTOGRAPHIE->getIcone()],
            ],
        ];
    }

    public function getTypesByCategorie(?CategorieActiviteEnum $categorie): array
    {
        if (!$categorie) {
            return [];
        }
        
        return $this->typesByCategorie[$categorie->value] ?? [];
    }
    
    public function getTypesByCategorieFromString(string $categorieValue): array
    {
        // Normaliser: minuscules + sans accents
        $mapping = [
            'desert' => CategorieActiviteEnum::DESERT,
            'mer' => CategorieActiviteEnum::MER,
            'aerien' => CategorieActiviteEnum::AERIEN,
            'nature' => CategorieActiviteEnum::NATURE,
            'culture' => CategorieActiviteEnum::CULTURE,
        ];
        
        $categorie = $mapping[$categorieValue] ?? null;
        
        if (!$categorie) {
            return [];
        }
        
        return $this->typesByCategorie[$categorie->value] ?? [];
    }

    public function getAllCategoriesWithTypes(): array
    {
        $result = [];
        foreach (CategorieActiviteEnum::cases() as $categorie) {
            $result[$categorie->value] = $this->getTypesByCategorie($categorie);
        }
        return $result;
    }
}