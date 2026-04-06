<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use App\Entity\Commentaires;
use App\Entity\Posts;
use App\Entity\Reactions;
use App\Entity\User;
use App\Form\CommentType;
use App\Form\PostType;
use App\Service\UserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends AbstractController
{
    private UserResolver $userResolver;

    public function __construct(UserResolver $userResolver)
    {
        $this->userResolver = $userResolver;
    }

    // ─── LIST ─────────────────────────────────────────────────────────────────
    #[Route('/', name: 'blog_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $posts = $em->getRepository(Posts::class)->createQueryBuilder('p')
            ->where('p.statut = :public')
            ->setParameter('public', 'public')
            ->orderBy('p.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('blog/index.html.twig', ['posts' => $posts]);
    }

    // ─── NEW POST — must be BEFORE /post/{id} ─────────────────────────────────
    #[Route('/post/new', name: 'blog_post_new')]
    public function newPost(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('login_switch');
        }

        $post = new Posts();
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $post->setUser($user);
                $post->setDateCreation(new \DateTime());
                $post->setStatut('public');

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

                $postId = $post->getIdPost();
                if (!$postId) {
                    $postId = $em->getConnection()->lastInsertId();
                }

                $this->addFlash('success', 'Publication créée.');
                return $this->redirectToRoute('blog_post_show', ['id' => $postId]);
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
            }
        }

        return $this->render('blog/new.html.twig', ['form' => $form->createView()]);
    }

    // ─── SHOW ─────────────────────────────────────────────────────────────────
    #[Route('/post/{id}', name: 'blog_post_show', requirements: ['id' => '\d+'])]
    public function show(?Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        if (!$post) {
            throw $this->createNotFoundException('Ce post n\'existe pas.');
        }

        $user = $this->userResolver->getCurrentUser();

        $comment = new Commentaires();
        $form    = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$user) {
                $this->addFlash('error', 'Vous devez être connecté pour commenter.');
                return $this->redirectToRoute('login_switch');
            }
            $comment->setPost($post);
            $comment->setUser($user);
            $comment->setDate(new \DateTime());
            $em->persist($comment);
            $em->flush();
            $this->addFlash('success', 'Commentaire ajouté.');
            return $this->redirectToRoute('blog_post_show', ['id' => $post->getIdPost()]);
        }

        $comments = $em->getRepository(Commentaires::class)->findBy(
            ['post' => $post, 'parent' => null],
            ['date' => 'ASC']
        );

        $userReaction = $user
            ? $em->getRepository(Reactions::class)->findOneBy(['post' => $post, 'user' => $user])
            : null;

        $reactionsCount = $em->getRepository(Reactions::class)->count(['post' => $post]);

        $replyForms = [];
        foreach ($comments as $c) {
            $replyForms[$c->getIdCommentaire()] = $this->createForm(CommentType::class, new Commentaires())->createView();
        }

        return $this->render('blog/show.html.twig', [
            'post'           => $post,
            'comments'       => $comments,
            'commentForm'    => $form->createView(),
            'userReaction'   => $userReaction,
            'reactionsCount' => $reactionsCount,
            'replyForms'     => $replyForms,
            'currentUser'    => $user,
        ]);
    }

    // ─── EDIT ─────────────────────────────────────────────────────────────────
    #[Route('/post/{id}/edit', name: 'blog_post_edit', requirements: ['id' => '\d+'])]
    public function editPost(Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user || ($post->getUser() !== $user && !$this->userResolver->isAdmin())) {
            throw $this->createAccessDeniedException('Vous ne pouvez modifier que vos propres posts.');
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
            return $this->redirectToRoute('blog_post_show', ['id' => $post->getIdPost()]);
        }

        return $this->render('blog/edit.html.twig', [
            'form' => $form->createView(),
            'post' => $post,
        ]);
    }

    // ─── DELETE ───────────────────────────────────────────────────────────────
    #[Route('/post/{id}/delete', name: 'blog_post_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePost(Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user || ($post->getUser() !== $user && !$this->userResolver->isAdmin())) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete' . $post->getIdPost(), $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Publication supprimée.');
        }

        return $this->redirectToRoute('blog_index');
    }

    // ─── REACT TO POST ────────────────────────────────────────────────────────
    #[Route('/post/{id}/react/{type}', name: 'blog_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function react(Posts $post, string $type, EntityManagerInterface $em, Request $request): Response
{
    $userId = $request->getSession()->get('logged_user_id');
    if (!$userId) {
        return $this->json(['error' => 'Veuillez vous connecter'], 401);
    }

    $user = $em->getRepository(User::class)->find($userId);
    $existing = $em->getRepository(Reactions::class)->findOneBy([
        'post' => $post,
        'user' => $user,
    ]);

    if ($existing) {
        $em->remove($existing);
        $added = false;
    } else {
        $reaction = new Reactions();
        $reaction->setType($type);
        $reaction->setPost($post);
        $reaction->setUser($user);
        $reaction->setDate(new \DateTime());
        $em->persist($reaction);
        $added = true;
    }

    $em->flush(); // Save changes to the database

    // Recount after flush
    $count = $em->getRepository(Reactions::class)->count(['post' => $post]);

    return $this->json([
        'added' => $added,
        'count' => $count
    ]);
}

    // ─── REACT TO COMMENT ─────────────────────────────────────────────────────
    #[Route('/comment/{id}/react/{type}', name: 'blog_comment_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reactComment(Commentaires $comment, string $type, EntityManagerInterface $em, Request $request): Response
    {
        // ✅ Read session directly
        $userId = $request->getSession()->get('logged_user_id');

        if (!$userId) {
            return $this->json([
                'error' => 'Not authenticated',
                'added' => false,
                'count' => $em->getRepository(Reactions::class)->count(['commentaire' => $comment]),
            ]);
        }

        $user = $em->getRepository(User::class)->find($userId);
        if (!$user) {
            return $this->json([
                'error' => 'User not found',
                'added' => false,
                'count' => $em->getRepository(Reactions::class)->count(['commentaire' => $comment]),
            ]);
        }

        $existing = $em->getRepository(Reactions::class)->findOneBy([
            'commentaire' => $comment,
            'user'        => $user,
        ]);

        if ($existing) {
            $em->remove($existing);
            $em->flush();
            $added = false;
        } else {
            $reaction = new Reactions();
            $reaction->setType($type);
            $reaction->setCommentaire($comment);
            $reaction->setUser($user);
            $reaction->setDate(new \DateTime());
            $em->persist($reaction);
            $em->flush();
            $added = true;
        }

        $count = $em->getRepository(Reactions::class)->count(['commentaire' => $comment]);
        return $this->json(['added' => $added, 'count' => $count]);
    }

    // ─── REPLY TO COMMENT ─────────────────────────────────────────────────────
    #[Route('/comment/{id}/reply', name: 'blog_comment_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function replyComment(Commentaires $parent, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) {
            $this->addFlash('error', 'Vous devez être connecté pour répondre.');
            return $this->redirectToRoute('login_switch');
        }

        $content = $request->request->get('contenu');
        if (empty($content)) {
            $this->addFlash('error', 'Le contenu ne peut pas être vide.');
            return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
        }

        $reply = new Commentaires();
        $reply->setContenu($content);
        $reply->setParent($parent);
        $reply->setPost($parent->getPost());
        $reply->setUser($user);
        $reply->setDate(new \DateTime());
        $em->persist($reply);
        $em->flush();

        $this->addFlash('success', 'Réponse ajoutée.');
        return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
    }
}