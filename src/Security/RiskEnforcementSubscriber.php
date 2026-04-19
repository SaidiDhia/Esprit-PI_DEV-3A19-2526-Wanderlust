<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class RiskEnforcementSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if ($this->isIgnoredPath($path)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $tokenUser = $token?->getUser();
        if (!$tokenUser instanceof User) {
            return;
        }

        if ($tokenUser->isAdmin()) {
            return;
        }

        $riskRow = $this->fetchRiskRow($tokenUser->getId());
        if ($riskRow === null) {
            return;
        }

        $recommendedAction = (string) ($riskRow['recommended_action'] ?? 'allow');
        $riskScore = (float) ($riskRow['risk_score'] ?? 0.0);

        if (!in_array($recommendedAction, ['temporary_block', 'manual_review_or_ban'], true)) {
            return;
        }

        $session = $request->getSession();
        if ($session) {
            $session->getFlashBag()->add(
                'error',
                sprintf('Access is restricted by risk policy (score %.2f). Please contact support.', $riskScore)
            );
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_risk_review_required')));
    }

    private function fetchRiskRow(?string $userId): ?array
    {
        if ($userId === null || trim($userId) === '') {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT risk_score, recommended_action FROM risk_assessment WHERE user_id = ?',
                [$userId]
            );

            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isIgnoredPath(string $path): bool
    {
        $allowedPrefixes = [
            '/login',
            '/logout',
            '/signup',
            '/forgot-password',
            '/reset-password',
            '/risk/review-required',
            '/_profiler',
            '/_wdt',
            '/css',
            '/js',
            '/images',
            '/uploads',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
