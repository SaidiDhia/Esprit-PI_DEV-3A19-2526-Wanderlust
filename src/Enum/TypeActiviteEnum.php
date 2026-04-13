<?php

namespace App\Enum;

enum TypeActiviteEnum: string
{
    // Catégorie DÉSERT
    case RANDONNEE_SAHARA = 'Randonnée dans le Sahara';
    case TREKKING_DUNES = 'Trekking dans les dunes';
    case QUAD_BUGGY = 'Quad et buggy';
    case MOTO_CROSS_DESERT = 'Moto cross désert';
    case BALADE_DROMADAIRE = 'Balade à dos de dromadaire';
    case NUIT_CAMPEMENT = 'Nuit en campement saharien';
    case OBSERVATION_ETOILES = 'Observation des étoiles';
    
    // Catégorie MER
    case JET_SKI = 'Jet ski';
    case PARACHUTE_ASCENSIONNEL = 'Parachute ascensionnel';
    case PADDLE = 'Paddle';
    case KAYAK = 'Kayak';
    case PLANCHE_VOILE = 'Planche à voile';
    case PLONGEE_SOUS_MARINE = 'Plongée sous-marine';
    case SNORKELING = 'Snorkeling';
    case SORTIE_BATEAU = 'Sortie en bateau';
    case PECHE_TOURISTIQUE = 'Pêche touristique';
    
    // Catégorie AÉRIEN
    case PARACHUTISME = 'Parachutisme';
    case PARAPENTE = 'Parapente';
    case PARACHUTE_ASCENSIONNEL_MER = 'Parachute ascensionnel (mer)';
    case ULM = 'ULM (Ultra léger motorisé)';
    case MONTGOLFIERE = 'Montgolfière (occasionnellement dans le sud)';
    
    // Catégorie NATURE
    case RANDONNEE_FORET = 'Randonnée en forêt';
    case ESCALADE = 'Escalade';
    case CAMPING = 'Camping';
    case VTT = 'VTT';
    case SPELEOLOGIE = 'Spéléologie';
    
    // Catégorie CULTURE
    case VISITE_KSOUR = 'Visite des ksour de Tataouine';
    case DECORS_FILMS = 'Décors de films à Tozeur';
    case VISITE_ARCHEOLOGIQUE = 'Visite archéologique';
    case FESTIVALS = 'Festivals';
    case TOURISME_HISTORIQUE = 'Tourisme historique';
    case PHOTOGRAPHIE = 'Photographie';
    
    public function getIcone(): string
    {
        return match($this) {
            // Désert
            self::RANDONNEE_SAHARA => '🏜️',
            self::TREKKING_DUNES => '🥾',
            self::QUAD_BUGGY => '🏎️',
            self::MOTO_CROSS_DESERT => '🏍️',
            self::BALADE_DROMADAIRE => '🐪',
            self::NUIT_CAMPEMENT => '🏕️',
            self::OBSERVATION_ETOILES => '⭐',
            
            // Mer
            self::JET_SKI => '🏄‍♂️', // using male sign as requested or standard emojis
            self::PARACHUTE_ASCENSIONNEL => '🪂',
            self::PADDLE, self::KAYAK => '🛶',
            self::PLANCHE_VOILE => '⛵',
            self::PLONGEE_SOUS_MARINE => '🤿',
            self::SNORKELING => '🤽',
            self::SORTIE_BATEAU => '⛵',
            self::PECHE_TOURISTIQUE => '🎣',
            
            // Aérien
            self::PARACHUTISME => '🪂',
            self::PARAPENTE => '🪂',
            self::PARACHUTE_ASCENSIONNEL_MER => '🪂',
            self::ULM => '🪂',
            self::MONTGOLFIERE => '🎈',
            
            // Nature
            self::RANDONNEE_FORET => '🥾',
            self::ESCALADE => '🧗',
            self::CAMPING => '🏕️',
            self::VTT => '🚵',
            self::SPELEOLOGIE => '⛰️',
            
            // Culture
            self::VISITE_KSOUR, self::VISITE_ARCHEOLOGIQUE => '🏛️',
            self::DECORS_FILMS => '🎬',
            self::FESTIVALS => '🎉',
            self::TOURISME_HISTORIQUE => '📚',
            self::PHOTOGRAPHIE => '📷',
        };
    }
    
    public function getCategorie(): CategorieActiviteEnum
    {
        return match($this) {
            self::RANDONNEE_SAHARA, self::TREKKING_DUNES, self::QUAD_BUGGY,
            self::MOTO_CROSS_DESERT, self::BALADE_DROMADAIRE,
            self::NUIT_CAMPEMENT, self::OBSERVATION_ETOILES 
                => CategorieActiviteEnum::DESERT,
            
            self::JET_SKI, self::PARACHUTE_ASCENSIONNEL, self::PADDLE,
            self::KAYAK, self::PLANCHE_VOILE, self::PLONGEE_SOUS_MARINE,
            self::SNORKELING, self::SORTIE_BATEAU, self::PECHE_TOURISTIQUE 
                => CategorieActiviteEnum::MER,
            
            self::PARACHUTISME, self::PARAPENTE, self::PARACHUTE_ASCENSIONNEL_MER, 
            self::ULM, self::MONTGOLFIERE 
                => CategorieActiviteEnum::AERIEN,
            
            self::RANDONNEE_FORET, self::ESCALADE, self::CAMPING,
            self::VTT, self::SPELEOLOGIE 
                => CategorieActiviteEnum::NATURE,
            
            self::VISITE_KSOUR, self::DECORS_FILMS, self::VISITE_ARCHEOLOGIQUE,
            self::FESTIVALS, self::TOURISME_HISTORIQUE, self::PHOTOGRAPHIE 
                => CategorieActiviteEnum::CULTURE,
        };
    }
    
    public function getShortLabel(): string
    {
        return match($this) {
            self::RANDONNEE_SAHARA => 'Randonnée Sahara',
            self::TREKKING_DUNES => 'Trekking Dunes',
            self::QUAD_BUGGY => 'Quad/Buggy',
            self::MOTO_CROSS_DESERT => 'Moto Cross Désert',
            self::BALADE_DROMADAIRE => 'Balade Dromadaire',
            self::NUIT_CAMPEMENT => 'Nuit Campement',
            self::OBSERVATION_ETOILES => 'Observation Étoiles',
            self::JET_SKI => 'Jet Ski',
            self::PARACHUTE_ASCENSIONNEL => 'Parachute Ascensionnel',
            self::PARACHUTE_ASCENSIONNEL_MER => 'Parachute Ascensionnel Mer',
            self::PADDLE => 'Paddle',
            self::KAYAK => 'Kayak',
            self::PLANCHE_VOILE => 'Planche à Voile',
            self::PLONGEE_SOUS_MARINE => 'Plongée Sous-Marine',
            self::SNORKELING => 'Snorkeling',
            self::SORTIE_BATEAU => 'Sortie Bateau',
            self::PECHE_TOURISTIQUE => 'Pêche Touristique',
            self::PARACHUTISME => 'Parachutisme',
            self::PARAPENTE => 'Parapente',
            self::ULM => 'ULM',
            self::MONTGOLFIERE => 'Montgolfière',
            self::RANDONNEE_FORET => 'Randonnée Forêt',
            self::ESCALADE => 'Escalade',
            self::CAMPING => 'Camping',
            self::VTT => 'VTT',
            self::SPELEOLOGIE => 'Spéléologie',
            self::VISITE_KSOUR => 'Visite Ksour',
            self::DECORS_FILMS => 'Décors Films',
            self::VISITE_ARCHEOLOGIQUE => 'Visite Archéologique',
            self::FESTIVALS => 'Festivals',
            self::TOURISME_HISTORIQUE => 'Tourisme Historique',
            self::PHOTOGRAPHIE => 'Photographie',
            default => $this->value, // Fallback si un cas manque
        };
    }
}
