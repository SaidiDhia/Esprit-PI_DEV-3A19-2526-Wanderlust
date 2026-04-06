<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class UserResolver
{
    private RequestStack $requestStack;
    private EntityManagerInterface $em;

    public function __construct(RequestStack $requestStack, EntityManagerInterface $em)
    {
        $this->requestStack = $requestStack;
        $this->em = $em;
    }

    public function getCurrentUser(): ?User
    {
        $session = $this->requestStack->getSession();
        $userId = $session->get('logged_user_id');
        if (!$userId) {
            return null;
        }
        return $this->em->getRepository(User::class)->find($userId);
    }

    public function isAdmin(): bool
    {
        $user = $this->getCurrentUser();
        return $user && $user->getId() === 1;
    }
}