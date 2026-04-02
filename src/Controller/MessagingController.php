<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/messaging', name: 'app_messaging')]
class MessagingController extends AbstractController
{
    #[Route('', name: '')]
    public function index(): Response
    {
        return $this->render('messaging/index.html.twig');
    }
}
