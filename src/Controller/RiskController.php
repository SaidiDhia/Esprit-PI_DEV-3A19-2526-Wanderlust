<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RiskController extends AbstractController
{
    #[Route('/risk/review-required', name: 'app_risk_review_required', methods: ['GET'])]
    public function reviewRequired(): Response
    {
        return $this->render('security/risk_review_required.html.twig');
    }
}
