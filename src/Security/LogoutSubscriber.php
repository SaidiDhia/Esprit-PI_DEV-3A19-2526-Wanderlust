<?php

namespace App\Security;

use App\Entity\User;
use App\Service\ActivityLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class LogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ActivityLogger $activityLogger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return;
        }

        $this->activityLogger->logAction($user, 'auth', 'logout', [
            'targetType' => 'session',
            'targetName' => 'User logout',
            'destination' => (string) $event->getRequest()->getPathInfo(),
        ]);
    }
}
