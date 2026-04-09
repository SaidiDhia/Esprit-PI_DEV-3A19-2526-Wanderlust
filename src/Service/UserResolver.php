<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class UserResolver
{
    public function __construct(private readonly Security $security)
    {
    }

    public function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function isAdmin(): bool
    {
        $user = $this->getCurrentUser();

        return $user?->isAdmin() ?? false;
    }
}
