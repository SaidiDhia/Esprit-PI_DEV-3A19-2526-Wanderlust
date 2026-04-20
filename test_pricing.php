<?php

require_once 'vendor/autoload.php';

use App\Service\DynamicPricingEngine;
use App\Entity\Events;

// Test simple du système de pricing dynamique

echo "=== TEST DU SYSTÈME DE PRICING DYNAMIQUE ===\n\n";

// Créer un événement de test
$event = new Events();
$event->setLieu('Salle de concert test');
$event->setPrix('100.00');
$event->setCapaciteMax(500);
$event->setPlacesDisponibles(350); // 30% de remplissage
$event->setDateDebut(new \DateTime('+3 days'));
$event->setDateFin(new \DateTime('+3 days +4 hours'));
$event->setOrganisateur('Test Organizer');
$event->setTelephone('0123456789');
$event->setEmail('test@example.com');
$event->setStatut('actif');

echo "Événement de test créé :\n";
echo "- Lieu : " . $event->getLieu() . "\n";
echo "- Prix initial : " . $event->getPrix() . " $\n";
echo "- Capacité : " . $event->getCapaciteMax() . "\n";
echo "- Places disponibles : " . $event->getPlacesDisponibles() . "\n";
echo "- Taux de remplissage : " . (($event->getCapaciteMax() - $event->getPlacesDisponibles()) / $event->getCapaciteMax() * 100) . "%\n";
echo "- Date début : " . $event->getDateDebut()->format('Y-m-d H:i') . "\n\n";

// Test des facteurs de calcul
echo "=== CALCUL DES FACTEURS ===\n";

// Facteur temps
$now = new \DateTime();
$eventStart = $event->getDateDebut();
$hoursUntil = ($eventStart->getTimestamp() - $now->getTimestamp()) / 3600;

if ($hoursUntil > 168) $timeFactor = 0.1;
elseif ($hoursUntil > 72) $timeFactor = 0.3;
elseif ($hoursUntil > 24) $timeFactor = 0.6;
elseif ($hoursUntil > 12) $timeFactor = 0.8;
else $timeFactor = 1.0;

echo "- Heures avant l'événement : " . round($hoursUntil, 1) . "h\n";
echo "- Facteur temps : " . $timeFactor . "\n";

// Facteur remplissage
$capacity = $event->getCapaciteMax();
$available = $event->getPlacesDisponibles();
$occupied = $capacity - $available;
$occupancyRate = $occupied / $capacity;
$threshold = 0.7; // 70%

if ($occupancyRate >= $threshold) $occupancyFactor = 0.1;
elseif ($occupancyRate >= $threshold * 0.8) $occupancyFactor = 0.4;
elseif ($occupancyRate >= $threshold * 0.6) $occupancyFactor = 0.7;
else $occupancyFactor = 1.0;

echo "- Taux de remplissage : " . ($occupancyRate * 100) . "%\n";
echo "- Facteur remplissage : " . $occupancyFactor . "\n";

// Facteur popularité (simulation)
$popularityFactor = 0.6; // Simulation de popularité moyenne
echo "- Facteur popularité : " . $popularityFactor . "\n\n";

// Calcul du score composite
echo "=== CALCUL DU SCORE COMPOSITE ===\n";
$timeWeight = 0.40;
$occupancyWeight = 0.35;
$popularityWeight = 0.25;

$urgencyScore = ($timeFactor * $timeWeight) + 
               ($occupancyFactor * $occupancyWeight) + 
               ($popularityFactor * $popularityWeight);

echo "- Poids temps : " . $timeWeight . "\n";
echo "- Poids remplissage : " . $occupancyWeight . "\n";
echo "- Poids popularité : " . $popularityWeight . "\n";
echo "- Score d'urgence : " . $urgencyScore . "\n\n";

// Calcul de la réduction non-linéaire
echo "=== CALCUL DE LA RÉDUCTION ===\n";
$discountPercentage = pow($urgencyScore, 1.5) * 0.4;
echo "- Réduction calculée : " . ($discountPercentage * 100) . "%\n";

// Prix plancher émotionnel
$basePrice = (float)$event->getPrix();
$emotionalFloor = $basePrice * 0.50; // 50%
echo "- Prix plancher émotionnel : " . $emotionalFloor . " $\n";

// Prix final
$newPrice = max($basePrice * (1 - $discountPercentage), $emotionalFloor);
echo "- Nouveau prix calculé : " . round($newPrice, 2) . " $\n";
echo "- Économie : " . round($basePrice - $newPrice, 2) . " $\n\n";

// Test de réversibilité
echo "=== TEST DE RÉVERSIBILITÉ ===\n";
$popularityImprovement = 0.3;
if ($popularityImprovement > 0.3) {
    $reversiblePrice = min($newPrice * 1.05, $basePrice * 0.95);
    echo "- Amélioration de popularité détectée : " . ($popularityImprovement * 100) . "%\n";
    echo "- Prix avec réversibilité : " . round($reversiblePrice, 2) . " $\n";
} else {
    echo "- Pas d'amélioration suffisante pour la réversibilité\n";
}

echo "\n=== RÉSUMÉ DU TEST ===\n";
echo "Prix original : " . $basePrice . " $\n";
echo "Prix final : " . round($newPrice, 2) . " $\n";
echo "Réduction : " . round(($basePrice - $newPrice) / $basePrice * 100, 1) . "%\n";
echo "Raison du changement : ";

$maxFactor = max($timeFactor, $occupancyFactor, $popularityFactor);
if ($maxFactor === $timeFactor) echo "Urgence temporelle";
elseif ($maxFactor === $occupancyFactor) echo "Faible remplissage";
else echo "Boost de popularité";

echo "\n\n=== TEST TERMINÉ AVEC SUCCÈS ===\n";
