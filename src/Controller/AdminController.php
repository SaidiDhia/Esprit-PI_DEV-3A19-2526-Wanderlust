<?php

namespace App\Controller;

use App\Entity\Commentaires;
use App\Entity\Posts;
use App\Entity\Reactions;
use App\Form\PostType;
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

    private function requireAdmin(): void
    {
        if (!$this->userResolver->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
    }

    #[Route('/', name: 'admin_dashboard')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $this->requireAdmin();

        $posts     = $em->getRepository(Posts::class)->findAll();
        $comments  = $em->getRepository(Commentaires::class)->findAll();
        $reactions = $em->getRepository(Reactions::class)->findAll();

        return $this->render('blog/dashboard.html.twig', [
            'posts'     => $posts,
            'comments'  => $comments,
            'reactions' => $reactions,
        ]);
    }

    // ─── ADMIN: CREATE POST ───────────────────────────────────────────────────
    #[Route('/post/new', name: 'admin_post_new')]
    public function newPost(Request $request, EntityManagerInterface $em): Response
    {
        $this->requireAdmin();

        $user = $this->userResolver->getCurrentUser();
        $post = new Posts();
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $post->setUser($user);
            $post->setDateCreation(new \DateTime());

            $mediaFile = $form->get('media')->getData();
            if ($mediaFile) {
                $newFilename = uniqid() . '.' . $mediaFile->guessExtension();
                $mediaFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/uploads',
                    $newFilename
                );
                $post->setMedia($newFilename);
            }

            $em->persist($post);
            $em->flush();

            $this->addFlash('success', 'Publication créée avec succès.');
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('blog/admin_post_form.html.twig', [
            'form'  => $form->createView(),
            'post'  => null,
            'title' => 'Nouvelle publication',
        ]);
    }

    // ─── ADMIN: EDIT POST ─────────────────────────────────────────────────────
    #[Route('/post/{id}/edit', name: 'admin_post_edit')]
    public function editPost(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireAdmin();

        // FIX: manual lookup because PK is idPost not id
        $post = $em->getRepository(Posts::class)->find($id);
        if (!$post) {
            $this->addFlash('error', 'Publication introuvable.');
            return $this->redirectToRoute('admin_dashboard');
        }

        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mediaFile = $form->get('media')->getData();
            if ($mediaFile) {
                $oldMedia = $post->getMedia();
                if ($oldMedia) {
                    $oldPath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $oldMedia;
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $newFilename = uniqid() . '.' . $mediaFile->guessExtension();
                $mediaFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/uploads',
                    $newFilename
                );
                $post->setMedia($newFilename);
            }

            $em->flush();
            $this->addFlash('success', 'Publication modifiée.');
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('blog/admin_post_form.html.twig', [
            'form'  => $form->createView(),
            'post'  => $post,
            'title' => 'Modifier la publication',
        ]);
    }

    // ─── ADMIN: DELETE POST ───────────────────────────────────────────────────
    #[Route('/post/{id}/delete', name: 'admin_post_delete', methods: ['POST'])]
    public function deletePost(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireAdmin();

        // FIX: manual lookup because PK is idPost not id
        $post = $em->getRepository(Posts::class)->find($id);
        if (!$post) {
            $this->addFlash('error', 'Publication introuvable.');
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->isCsrfTokenValid('admin_delete' . $post->getIdPost(), $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Post supprimé.');
        }
        return $this->redirectToRoute('admin_dashboard');
    }

    // ─── ADMIN: DELETE COMMENT ────────────────────────────────────────────────
    #[Route('/comment/{id}/delete', name: 'admin_comment_delete', methods: ['POST'])]
    public function deleteComment(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireAdmin();

        // FIX: manual lookup because PK is idCommentaire not id
        $comment = $em->getRepository(Commentaires::class)->find($id);
        if (!$comment) {
            $this->addFlash('error', 'Commentaire introuvable.');
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->isCsrfTokenValid('admin_delete_comment' . $comment->getIdCommentaire(), $request->request->get('_token'))) {
            $em->remove($comment);
            $em->flush();
            $this->addFlash('success', 'Commentaire supprimé.');
        }
        return $this->redirectToRoute('admin_dashboard');
    }

    // ─── ADMIN: DELETE REACTION ───────────────────────────────────────────────
    #[Route('/reaction/{id}/delete', name: 'admin_reaction_delete', methods: ['POST'])]
    public function deleteReaction(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireAdmin();

        // FIX: manual lookup because PK is idReaction not id
        $reaction = $em->getRepository(Reactions::class)->find($id);
        if (!$reaction) {
            $this->addFlash('error', 'Réaction introuvable.');
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->isCsrfTokenValid('admin_delete_reaction' . $reaction->getIdReaction(), $request->request->get('_token'))) {
            $em->remove($reaction);
            $em->flush();
            $this->addFlash('success', 'Réaction supprimée.');
        }
        return $this->redirectToRoute('admin_dashboard');
    }
}