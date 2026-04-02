<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/blog', name: 'app_blog')]
class BlogController extends AbstractController
{
    #[Route('', name: '')]
    public function index(): Response
    {
        return $this->render('blog/index.html.twig');
    }
}
