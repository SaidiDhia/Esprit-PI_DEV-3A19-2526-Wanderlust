<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\TFAMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TwoFactorEnforcementSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '') {
            return;
        }

        if (in_array($route, ['app_login', 'app_logout', 'app_signup', 'app_forgot_password_request', 'app_reset_password', 'app_tfa_challenge', 'app_tfa_resend', 'app_tfa_face_reference_capture'], true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->getTfaMethod() === TFAMethod::NONE) {
            return;
        }

        $session = $request->getSession();
        if (!$session) {
            return;
        }

        $verifiedUserId = (string) $session->get('tfa.verified_user_id', '');
        if ($verifiedUserId === $user->getId()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_tfa_challenge')));
    }
}
