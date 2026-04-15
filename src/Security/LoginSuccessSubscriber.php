<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginSuccessSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $session = $request->getSession();
        if (!$session) {
            return;
        }

        $session->remove('tfa.verified_user_id');
        $session->remove('tfa.login.code');
        $session->remove('tfa.login.code_expires_at');
        $session->remove('tfa.login.last_sent_at');
    }
}
