<?php

namespace App\Security;

use App\Service\ActivityLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

class LoginFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ActivityLogger $activityLogger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $email = trim((string) $request->request->get('email', ''));
        $clientIp = (string) ($request->getClientIp() ?? 'unknown');
        $userAgent = substr((string) $request->headers->get('User-Agent', ''), 0, 255);

        $this->activityLogger->logAction(null, 'auth', 'login_failed', [
            'targetType' => 'session',
            'targetName' => 'Login failure',
            'content' => $email !== '' ? sprintf('Failed login attempt for %s', $email) : 'Failed login attempt',
            'destination' => $request->getPathInfo(),
            'metadata' => [
                'email' => $email !== '' ? $email : null,
                'ip' => $clientIp,
                'user_agent' => $userAgent,
            ],
        ]);
    }
}
