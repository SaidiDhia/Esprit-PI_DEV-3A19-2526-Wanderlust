<?php

namespace App\Command;

use App\Service\DynamicPricingEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:dynamic-pricing',
    description: 'Exécute le moteur de pricing dynamique pour les événements'
)]
class DynamicPricingCommand extends Command
{
    private DynamicPricingEngine $pricingEngine;

    public function __construct(DynamicPricingEngine $pricingEngine)
    {
        $this->pricingEngine = $pricingEngine;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action à exécuter (run|simulate|stats)')
            ->addOption('event-id', 'i', InputOption::VALUE_OPTIONAL, 'ID de l\'événement spécifique')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer l\'exécution même si déjà récemment exécuté')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Mode simulation (pas de modification)')
            ->setHelp('Cette commande permet de gérer le pricing dynamique des événements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $eventId = $input->getOption('event-id');
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        switch ($action) {
            case 'run':
                return $this->runPricing($io, $eventId, $force, $dryRun);
            
            case 'simulate':
                return $this->simulatePricing($io, $eventId);
            
            case 'stats':
                return $this->showStats($io);
            
            default:
                $io->error('Action invalide. Actions disponibles: run, simulate, stats');
                return Command::FAILURE;
        }
    }

    private function runPricing(SymfonyStyle $io, ?int $eventId, bool $force, bool $dryRun): int
    {
        $io->title('Exécution du Pricing Dynamique');

        if ($dryRun) {
            $io->warning('MODE SIMULATION - Aucune modification ne sera appliquée');
        }

        if ($eventId) {
            $io->section("Traitement de l'événement #{$eventId}");
            // Implémenter le traitement d'un événement spécifique
            $io->success('Fonctionnalité à implémenter');
        } else {
            $io->section('Traitement par lot des événements éligibles');
            
            $results = $this->pricingEngine->runDynamicPricingBatch();
            
            $io->table(
                ['Statut', 'Nombre'],
                [
                    ['Événements traités', $results['processed']],
                    ['Prix mis à jour', $results['updated']],
                    ['Erreurs', $results['errors']]
                ]
            );

            if ($results['errors'] > 0) {
                $io->warning(sprintf('%d erreurs rencontrées', $results['errors']));
            }

            $io->success(sprintf('Traitement terminé: %d événements traités, %d prix mis à jour', 
                $results['processed'], $results['updated']));
        }

        return Command::SUCCESS;
    }

    private function simulatePricing(SymfonyStyle $io, ?int $eventId): int
    {
        $io->title('Simulation du Pricing Dynamique');

        if (!$eventId) {
            $io->error('L\'option --event-id est obligatoire pour la simulation');
            return Command::FAILURE;
        }

        // Scénarios de simulation
        $scenarios = [
            ['name' => 'Urgence maximale', 'time_factor' => 1.0, 'occupancy_factor' => 0.8, 'popularity_factor' => 0.9],
            ['name' => 'Faible remplissage', 'time_factor' => 0.6, 'occupancy_factor' => 1.0, 'popularity_factor' => 0.5],
            ['name' => 'Popularité élevée', 'time_factor' => 0.4, 'occupancy_factor' => 0.3, 'popularity_factor' => 0.1],
            ['name' => 'Situation normale', 'time_factor' => 0.5, 'occupancy_factor' => 0.5, 'popularity_factor' => 0.5]
        ];

        // Implémenter la simulation (nécessite l'accès à l'entité Events)
        $io->section('Scénarios de simulation');
        
        foreach ($scenarios as $scenario) {
            $io->text(sprintf('Scénario: %s', $scenario['name']));
            $io->text(sprintf('  - Facteur temps: %.2f', $scenario['time_factor']));
            $io->text(sprintf('  - Facteur remplissage: %.2f', $scenario['occupancy_factor']));
            $io->text(sprintf('  - Facteur popularité: %.2f', $scenario['popularity_factor']));
            $io->newLine();
        }

        $io->info('Fonctionnalité de simulation complète à implémenter avec l\'entité Events');

        return Command::SUCCESS;
    }

    private function showStats(SymfonyStyle $io): int
    {
        $io->title('Statistiques du Pricing Dynamique');

        $stats = $this->pricingEngine->getPricingStatistics();

        $io->section('Statistiques générales');
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Total événements', $stats['total_events']],
                ['Événements éligibles', $stats['eligible_events']],
                ['Règles actives', $stats['active_rules']],
                ['Changements récents (24h)', $stats['recent_changes']],
                ['Réduction moyenne (7j)', $stats['average_discount'] ? sprintf('%.2f%%', $stats['average_discount'] * 100) : 'N/A']
            ]
        );

        $io->section('Informations système');
        $io->text(sprintf('Date/Heure: %s', (new \DateTime())->format('Y-m-d H:i:s')));
        $io->text(sprintf('Mémoire utilisée: %s', number_format(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'));
        $io->text(sprintf('Pic mémoire: %s', number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'));

        return Command::SUCCESS;
    }
}
