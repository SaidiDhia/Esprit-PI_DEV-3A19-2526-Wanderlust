<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/booking', name: 'app_booking')]
class BookingController extends AbstractController
{
    #[Route('', name: '')]
    public function index(): Response
    {
        return $this->render('booking/index.html.twig');
    }
}
