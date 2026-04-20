<?php

namespace App\Service;

use App\Entity\BlogNotification;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function push(string $type, string $recipientUserId, string $actorUsername, ?string $postPreview, ?string $contentPreview): void
    {
        $notif = new BlogNotification();
        $notif->setType($type);
        $notif->setRecipientUserId($recipientUserId);
        $notif->setActorUsername($actorUsername);
        $notif->setPostPreview($postPreview);
        $notif->setContentPreview($contentPreview);
        $notif->setCreatedAt(new \DateTime());
        $notif->setIsRead(false);

        $this->em->persist($notif);
        $this->em->flush();
    }

    public function getForUser(string $userId, int $limit = 50): array
    {
        return $this->em->getRepository(BlogNotification::class)
            ->findBy(['recipientUserId' => $userId], ['createdAt' => 'DESC'], $limit);
    }

    public function markRead(int $notifId): void
    {
        $notif = $this->em->getRepository(BlogNotification::class)->find($notifId);
        if ($notif) {
            $notif->setIsRead(true);
            $this->em->flush();
        }
    }

    public function markAllRead(string $userId): void
    {
        $this->em->createQuery(
            'UPDATE App\Entity\BlogNotification n SET n.isRead = true WHERE n.recipientUserId = :uid'
        )->setParameter('uid', $userId)->execute();
    }

    public function countUnread(string $userId): int
    {
        return $this->em->getRepository(BlogNotification::class)
            ->count(['recipientUserId' => $userId, 'isRead' => false]);
    }
}