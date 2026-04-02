<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/marketplace', name: 'app_marketplace')]
class MarketplaceController extends AbstractController
{
    #[Route('', name: '')]
    public function index(): Response
    {
        return $this->render('marketplace/index.html.twig');
    }
}
