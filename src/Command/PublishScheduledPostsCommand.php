<?php

namespace App\Command;

use App\Entity\Posts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publishes posts whose scheduled_at <= NOW() and statut = 'scheduled'.
 *
 * Run manually:
 *   php bin/console app:publish-scheduled-posts
 *
 * Cron (every minute):
 *   * * * * * /usr/bin/php /var/www/html/bin/console app:publish-scheduled-posts >> /var/log/wanderlust_scheduler.log 2>&1
 */
#[AsCommand(
    name: 'app:publish-scheduled-posts',
    description: 'Publishes posts whose scheduled publication time has arrived.',
)]
class PublishScheduledPostsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = new \DateTime();

        /** @var Posts[] $posts */
        $posts = $this->em->getRepository(Posts::class)
            ->createQueryBuilder('p')
            ->where('p.statut = :scheduled')
            ->andWhere('p.scheduledAt IS NOT NULL')
            ->andWhere('p.scheduledAt <= :now')
            ->setParameter('scheduled', 'scheduled')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        if (empty($posts)) {
            $io->success(sprintf('[%s] No scheduled posts to publish.', $now->format('Y-m-d H:i')));
            return Command::SUCCESS;
        }

        foreach ($posts as $post) {
            $post->setStatut('public');
            $io->writeln(sprintf(
                '  ✅ Published post #%d (scheduled for %s)',
                $post->getIdPost(),
                $post->getScheduledAt()->format('Y-m-d H:i')
            ));
        }

        $this->em->flush();
        $io->success(sprintf('[%s] Published %d post(s).', $now->format('Y-m-d H:i'), count($posts)));

        return Command::SUCCESS;
    }
}