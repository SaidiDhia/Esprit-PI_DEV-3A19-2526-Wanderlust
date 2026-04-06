<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class LoginController extends AbstractController
{
    #[Route('/login-switch', name: 'login_switch')]
    public function switchUser(Request $request, EntityManagerInterface $em): Response
    {
        // Hardcoded user list (id => email)
        $users = [
            1 => ['email' => 'admin@wanderlust.com', 'role' => 'Admin'],
            2 => ['email' => 'user@wanderlust.com', 'role' => 'User'],
        ];

        $selectedId = $request->query->get('user_id');
        if ($selectedId && isset($users[$selectedId])) {
            $request->getSession()->set('logged_user_id', (int)$selectedId);
            $this->addFlash('success', 'Connecté en tant que ' . $users[$selectedId]['email']);
            return $this->redirectToRoute('blog_index');
        }

        return $this->render('blog/login/switch.html.twig', ['users' => $users]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('logged_user_id');
        $this->addFlash('success', 'Déconnecté.');
        return $this->redirectToRoute('login_switch');
    }
}