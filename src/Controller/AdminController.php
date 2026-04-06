<?php

namespace App\Controller;

use App\Entity\Commentaires;
use App\Entity\Posts;
use App\Entity\Reactions;
use App\Service\UserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    private UserResolver $userResolver;

    public function __construct(UserResolver $userResolver)
    {
        $this->userResolver = $userResolver;
    }

    #[Route('/', name: 'admin_dashboard')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        if (!$this->userResolver->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        $posts = $em->getRepository(Posts::class)->findAll();
        $comments = $em->getRepository(Commentaires::class)->findAll();
        $reactions = $em->getRepository(Reactions::class)->findAll();

        return $this->render('blog/dashboard.html.twig', [
            'posts' => $posts,
            'comments' => $comments,
            'reactions' => $reactions,
        ]);
    }

    #[Route('/post/{id}/delete', name: 'admin_post_delete', methods: ['POST'])]
    public function deletePost(Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->userResolver->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isCsrfTokenValid('admin_delete' . $post->getIdPost(), $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Post supprimé.');
        }
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/comment/{id}/delete', name: 'admin_comment_delete', methods: ['POST'])]
    public function deleteComment(Commentaires $comment, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->userResolver->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isCsrfTokenValid('admin_delete_comment' . $comment->getIdCommentaire(), $request->request->get('_token'))) {
            $em->remove($comment);
            $em->flush();
            $this->addFlash('success', 'Commentaire supprimé.');
        }
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/reaction/{id}/delete', name: 'admin_reaction_delete', methods: ['POST'])]
    public function deleteReaction(Reactions $reaction, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->userResolver->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isCsrfTokenValid('admin_delete_reaction' . $reaction->getIdReaction(), $request->request->get('_token'))) {
            $em->remove($reaction);
            $em->flush();
            $this->addFlash('success', 'Réaction supprimée.');
        }
        return $this->redirectToRoute('admin_dashboard');
    }
}