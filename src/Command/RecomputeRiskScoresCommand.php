<?php

namespace App\Command;

use App\Service\UserRiskAssessmentService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:risk:recompute', description: 'Recompute abuse risk scores for all users')]
class RecomputeRiskScoresCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRiskAssessmentService $userRiskAssessmentService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $rows = $this->connection->executeQuery('SELECT id FROM users')->fetchAllAssociative();
        } catch (\Throwable $exception) {
            $io->error('Unable to load users: ' . $exception->getMessage());
            return Command::FAILURE;
        }

        $processed = 0;
        foreach ($rows as $row) {
            $userId = trim((string) ($row['id'] ?? ''));
            if ($userId === '') {
                continue;
            }

            $this->userRiskAssessmentService->assessByActorId($userId);
            ++$processed;
        }

        $io->success(sprintf('Risk scores recomputed for %d users.', $processed));

        return Command::SUCCESS;
    }
}
