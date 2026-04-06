<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/booking')]
class BookingController extends AbstractController
{
    #[Route('', name: 'app_booking')]
    public function index(): Response
    {
        return $this->render('booking/index.html.twig');
    }
}
