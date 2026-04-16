<?php

namespace App\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ReactionsExtension extends AbstractExtension
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_message_reactions', [$this, 'getMessageReactions']),
        ];
    }

    public function getMessageReactions(int $messageId): array
    {
        $conn = $this->em->getConnection();
        $sql = "SELECT reaction, COUNT(*) as count FROM message_reactions WHERE message_id = :messageId GROUP BY reaction";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('messageId', $messageId);
        $result = $stmt->executeQuery();
        return $result->fetchAllAssociative();
    }
}
