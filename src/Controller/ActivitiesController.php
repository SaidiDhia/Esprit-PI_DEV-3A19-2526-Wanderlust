<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/activities', name: 'app_activities')]
class ActivitiesController extends AbstractController
{
    #[Route('', name: '')]
    public function index(): Response
    {
        return $this->render('activities/index.html.twig');
    }
}
