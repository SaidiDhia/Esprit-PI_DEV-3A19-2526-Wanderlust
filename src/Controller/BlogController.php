<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

#[Route('/blog')]
class BlogController extends AbstractController
{
    private UserResolver $userResolver;

    public function __construct(UserResolver $userResolver)
    {
        $this->userResolver = $userResolver;
    }

    /**
     * Verify current user is authenticated and return their ID
     */
    private function getCurrentUserId(): ?string
    {
        $user = $this->getUser();
        if (!$user) {
            return null;
        }

        if (method_exists($user, 'getId')) {
            $id = $user->getId();
            if ($id) {
                return (string) $id;
            }
        }

        if (method_exists($user, 'getUserId')) {
            $id = $user->getUserId();
            if ($id) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Check if current user owns the post, throw exception if not
     */
    private function verifyPostOwnership(Posts $post): void
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            throw $this->createAccessDeniedException('You must be logged in.');
        }

        $postUserId = $post->getUser()?->getId();
        $isAdmin = $this->userResolver->isAdmin();

        if ($postUserId !== $userId && !$isAdmin) {
            throw $this->createAccessDeniedException('You can only modify your own posts.');
        }
    }

    /**
     * Get file extension safely with fallback
     * @param UploadedFile $file
     * @return string
     */
    private function getFileExtension(UploadedFile $file): string
    {
        try {
            $ext = $file->guessExtension();
            if ($ext) {
                return $ext;
            }
        } catch (\Exception $e) {
            // fileinfo extension not available, fall back to original filename
        }
        
        // Fallback: extract from original filename
        $originalName = $file->getClientOriginalName();
        if (strpos($originalName, '.') !== false) {
            return strtolower(substr(strrchr($originalName, '.'), 1));
        }
        
        return 'bin'; // Default extension
    }

    /**
     * Validate file type based on extension
     */
    private function validateFileType(UploadedFile $file): bool
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'avi', 'mov', 'quicktime'];
        $ext = strtolower($this->getFileExtension($file));
        
        // Handle quicktime file type
        if ($ext === 'quicktime' || $ext === 'mov') {
            $ext = 'mov';
        }
        
        return in_array($ext, $allowedExtensions, true);
    }

    // ─── LIST ─────────────────────────────────────────────────────────────────
    #[Route('', name: 'app_blog')]
    #[Route('/', name: 'blog_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();

        $qb = $em->getRepository(Posts::class)->createQueryBuilder('p');

        if ($user) {
            // Show all public posts + the current user's own private posts
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('p.statut', ':public'),
                    $qb->expr()->andX(
                        $qb->expr()->eq('p.statut', ':private'),
                        $qb->expr()->eq('p.user', ':user')
                    )
                )
            )
            ->setParameter('public', 'public')
            ->setParameter('private', 'private')
            ->setParameter('user', $user);
        } else {
            $qb->where('p.statut = :public')
               ->setParameter('public', 'public');
        }

        $posts = $qb->orderBy('p.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('blog/index.html.twig', ['posts' => $posts]);
    }

    // ─── NEW POST ─────────────────────────────────────────────────────────────
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
                // FIX: Do NOT hardcode 'public' — respect what the user chose in the form
                // $post->setStatut() is already set by form->handleRequest via the radio buttons

                $mediaFile = $form->get('media')->getData();
                if ($mediaFile) {
                    if (!$this->validateFileType($mediaFile)) {
                        $this->addFlash('error', 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp, mp4, avi, mov');
                        return $this->render('blog/new.html.twig', [
                            'form' => $form->createView(),
                        ]);
                    }
                    $newFilename = uniqid() . '.' . $this->getFileExtension($mediaFile);
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

                $this->addFlash('success', 'Publication créée avec succès !');
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

        // Build edit forms for comments
        $editCommentForms = [];
        foreach ($comments as $c) {
            $editCommentForms[$c->getIdCommentaire()] = $this->createForm(CommentType::class, $c)->createView();
            foreach ($c->getReplies() as $reply) {
                $editCommentForms[$reply->getIdCommentaire()] = $this->createForm(CommentType::class, $reply)->createView();
            }
        }

        return $this->render('blog/show.html.twig', [
            'post'               => $post,
            'comments'           => $comments,
            'commentForm'        => $form->createView(),
            'userReaction'       => $userReaction,
            'reactionsCount'     => $reactionsCount,
            'replyForms'         => $replyForms,
            'editCommentForms'   => $editCommentForms,
            'currentUser'        => $user,
        ]);
    }

    // ─── EDIT POST ────────────────────────────────────────────────────────────
    #[Route('/post/{id}/edit', name: 'blog_post_edit', requirements: ['id' => '\d+'])]
    public function editPost(Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifyPostOwnership($post);

        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mediaFile = $form->get('media')->getData();
            if ($mediaFile) {
                if (!$this->validateFileType($mediaFile)) {
                    $this->addFlash('error', 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp, mp4, avi, mov');
                    return $this->render('blog/edit.html.twig', [
                        'form' => $form->createView(),
                        'post' => $post,
                    ]);
                }
                $oldMedia = $post->getMedia();
                if ($oldMedia) {
                    $oldPath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $oldMedia;
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $newFilename = uniqid() . '.' . $this->getFileExtension($mediaFile);
                $mediaFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/uploads',
                    $newFilename
                );
                $post->setMedia($newFilename);
            }

            $em->flush();
            $this->addFlash('success', 'Publication modifiée avec succès !');
            return $this->redirectToRoute('blog_post_show', ['id' => $post->getIdPost()]);
        }

        return $this->render('blog/edit.html.twig', [
            'form' => $form->createView(),
            'post' => $post,
        ]);
    }

    // ─── DELETE POST ──────────────────────────────────────────────────────────
    #[Route('/post/{id}/delete', name: 'blog_post_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePost(Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifyPostOwnership($post);

        if ($this->isCsrfTokenValid('delete' . $post->getIdPost(), $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Publication supprimée.');
        }

        return $this->redirectToRoute('blog_index');
    }

    // ─── EDIT COMMENT ─────────────────────────────────────────────────────────
    #[Route('/comment/{id}/edit', name: 'blog_comment_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editComment(Commentaires $comment, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifyCommentOwnership($comment);

        $content = trim($request->request->get('contenu', ''));
        if (strlen($content) < 2) {
            $this->addFlash('error', 'Le commentaire doit contenir au moins 2 caractères.');
            return $this->redirectToRoute('blog_post_show', ['id' => $comment->getPost()->getIdPost()]);
        }
        if (strlen($content) > 500) {
            $this->addFlash('error', 'Le commentaire ne peut pas dépasser 500 caractères.');
            return $this->redirectToRoute('blog_post_show', ['id' => $comment->getPost()->getIdPost()]);
        }

        $comment->setContenu($content);
        $em->flush();

        $this->addFlash('success', 'Commentaire modifié.');
        return $this->redirectToRoute('blog_post_show', ['id' => $comment->getPost()->getIdPost()]);
    }

    // ─── DELETE COMMENT ───────────────────────────────────────────────────────
    #[Route('/comment/{id}/delete', name: 'blog_comment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteComment(Commentaires $comment, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifyCommentOwnership($comment);

        $postId = $comment->getPost()->getIdPost();
        if ($this->isCsrfTokenValid('delete_comment' . $comment->getIdCommentaire(), $request->request->get('_token'))) {
            $em->remove($comment);
            $em->flush();
            $this->addFlash('success', 'Commentaire supprimé.');
        }

        return $this->redirectToRoute('blog_post_show', ['id' => $postId]);
    }

    // ─── REACT TO POST ────────────────────────────────────────────────────────
    #[Route('/post/{id}/react/{type}', name: 'blog_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function react(Posts $post, string $type, EntityManagerInterface $em, Request $request): Response
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return $this->json(['error' => 'Please log in'], 401);
        }

        $user = $em->getRepository(User::class)->find($userId);
        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

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

        $em->flush();
        $count = $em->getRepository(Reactions::class)->count(['post' => $post]);

        return $this->json(['added' => $added, 'count' => $count]);
    }

    // ─── REACT TO COMMENT ─────────────────────────────────────────────────────
    #[Route('/comment/{id}/react/{type}', name: 'blog_comment_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reactComment(Commentaires $comment, string $type, EntityManagerInterface $em, Request $request): Response
    {
        $userId = $this->getCurrentUserId();

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
            $added = false;
        } else {
            $reaction = new Reactions();
            $reaction->setType($type);
            $reaction->setCommentaire($comment);
            $reaction->setUser($user);
            $reaction->setDate(new \DateTime());
            $em->persist($reaction);
            $added = true;
        }

        $em->flush();
        $count = $em->getRepository(Reactions::class)->count(['commentaire' => $comment]);
        return $this->json(['added' => $added, 'count' => $count]);
    }

    // ─── REPLY TO COMMENT ─────────────────────────────────────────────────────
    #[Route('/comment/{id}/reply', name: 'blog_comment_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function replyComment(Commentaires $parent, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            $this->addFlash('error', 'You must be logged in to reply.');
            return $this->redirectToRoute('login_switch');
        }

        $content = trim($request->request->get('contenu', ''));
        if (empty($content)) {
            $this->addFlash('error', 'Content cannot be empty.');
            return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
        }
        if (strlen($content) < 2) {
            $this->addFlash('error', 'Minimum 2 characters required.');
            return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
        }
        if (strlen($content) > 500) {
            $this->addFlash('error', 'Maximum 500 characters allowed.');
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

        $this->addFlash('success', 'Reply added.');
        return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
    }
}