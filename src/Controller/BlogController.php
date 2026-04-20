<?php

namespace App\Controller;

use App\Entity\BlogNotification;
use App\Entity\Commentaires;
use App\Entity\Posts;
use App\Entity\PostsSauvegardes;
use App\Entity\Reactions;
use App\Entity\User;
use App\Form\CommentType;
use App\Form\PostType;
use App\Service\ModerationService;
use App\Service\NotificationService;
use App\Service\TranslationService;
use App\Service\UserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/blog')]
class BlogController extends AbstractController
{
    private const MAX_COMMENTS_IN_WINDOW = 2;
    private const COMMENT_WINDOW_SECONDS = 120;

    public function __construct(
        private readonly UserResolver        $userResolver,
        private readonly ModerationService   $moderationService,
        private readonly TranslationService  $translationService,
        private readonly NotificationService $notificationService,
    ) {}

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getCurrentUserId(): ?string
    {
        $user = $this->getUser();
        if (!$user) return null;
        if (method_exists($user, 'getId') && $user->getId()) return (string) $user->getId();
        if (method_exists($user, 'getUserId') && $user->getUserId()) return (string) $user->getUserId();
        return null;
    }

    private function verifyPostOwnership(Posts $post): void
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) throw $this->createAccessDeniedException('Vous devez être connecté.');
        if ($post->getUser()?->getId() !== $userId && !$this->userResolver->isAdmin()) {
            throw $this->createAccessDeniedException('Vous ne pouvez modifier que vos propres posts.');
        }
    }

    private function verifyCommentOwnership(Commentaires $comment): void
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) throw $this->createAccessDeniedException('Vous devez être connecté.');
        if ($comment->getUser()?->getId() !== $userId && !$this->userResolver->isAdmin()) {
            throw $this->createAccessDeniedException('Vous ne pouvez modifier que vos propres commentaires.');
        }
    }

    private function getFileExtension(UploadedFile $file): string
    {
        try {
            $ext = $file->guessExtension();
            if ($ext) return $ext;
        } catch (\Exception) {}
        $original = $file->getClientOriginalName();
        if (str_contains($original, '.')) {
            return strtolower(substr(strrchr($original, '.'), 1));
        }
        return 'bin';
    }

    private function checkCommentSpam(Request $request): ?string
    {
        $session    = $request->getSession();
        $now        = time();
        $cutoff     = $now - self::COMMENT_WINDOW_SECONDS;
        $timestamps = array_values(array_filter(
            $session->get('comment_timestamps', []),
            fn($t) => $t >= $cutoff
        ));

        if (count($timestamps) >= self::MAX_COMMENTS_IN_WINDOW) {
            $oldest      = min($timestamps);
            $unblockAt   = $oldest + self::COMMENT_WINDOW_SECONDS;
            $secondsLeft = $unblockAt - $now;
            $minsLeft    = (int) floor($secondsLeft / 60);
            $secsLeft    = $secondsLeft % 60;
            $timeStr     = $minsLeft > 0 ? "{$minsLeft} min {$secsLeft} sec" : "{$secsLeft} sec";
            return "⚠ Trop de commentaires ! Attendez {$timeStr} avant de poster à nouveau.";
        }

        $timestamps[] = $now;
        $session->set('comment_timestamps', $timestamps);
        return null;
    }

    // ── LIST ──────────────────────────────────────────────────────────────────

    #[Route('', name: 'app_blog')]
    #[Route('/', name: 'blog_index')]
    public function index(EntityManagerInterface $em, Request $request, PaginatorInterface $paginator): Response
    {
        $user = $this->userResolver->getCurrentUser();
        $now  = new \DateTime();

        $qb = $em->getRepository(Posts::class)->createQueryBuilder('p');

        if ($user) {
            $qb->where($qb->expr()->orX(
                // Public posts whose scheduled time has passed (or has no schedule)
                $qb->expr()->andX(
                    $qb->expr()->eq('p.statut', ':public'),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('p.scheduledAt'),
                        $qb->expr()->lte('p.scheduledAt', ':now')
                    )
                ),
                // Author always sees all their own posts except hidden
                // ✅ FIX: use neq() with a plain string, NOT notIn() with an array parameter
                $qb->expr()->andX(
                    $qb->expr()->neq('p.statut', ':hidden'),
                    $qb->expr()->eq('p.user', ':user')
                )
            ))
            ->setParameter('public', 'public')
            ->setParameter('hidden', 'hidden')   // ✅ plain string, not an array
            ->setParameter('user', $user)
            ->setParameter('now', $now);
        } else {
            // Guests only see public posts past their scheduled time
            $qb->where('p.statut = :public')
               ->andWhere($qb->expr()->orX(
                   $qb->expr()->isNull('p.scheduledAt'),
                   $qb->expr()->lte('p.scheduledAt', ':now')
               ))
               ->setParameter('public', 'public')
               ->setParameter('now', $now);
        }

        $query      = $qb->orderBy('p.dateCreation', 'DESC')->getQuery();
        $pagination = $paginator->paginate($query, $request->query->getInt('page', 1), 6);

        $savedIds = [];
        if ($user) {
            $saves = $em->getRepository(PostsSauvegardes::class)->findBy(['user' => $user]);
            foreach ($saves as $s) {
                $savedIds[] = $s->getPost()->getIdPost();
            }
        }

        $unreadCount = $user ? $this->notificationService->countUnread($user->getId()) : 0;

        return $this->render('blog/index.html.twig', [
            'pagination'  => $pagination,
            'savedIds'    => $savedIds,
            'currentUser' => $user,
            'unreadCount' => $unreadCount,
        ]);
    }

    // ── NEW POST ──────────────────────────────────────────────────────────────

    #[Route('/post/new', name: 'blog_post_new')]
    public function newPost(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $post = new Posts();
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $modResult = $this->moderationService->moderate($post->getContenu());

            if ($modResult['shouldHide']) {
                $this->addFlash('error', sprintf(
                    '❌ Publication refusée par l\'IA (score: %.0f%%). Raison : %s',
                    $modResult['score'] * 100,
                    $modResult['reason']
                ));
                return $this->redirectToRoute('blog_post_new');
            }

            $post->setUser($user);
            $post->setDateCreation(new \DateTime());

            // ✅ Validate scheduled publishing
            if ($post->getStatut() === 'scheduled') {
                if ($post->getScheduledAt() === null || $post->getScheduledAt() <= new \DateTime()) {
                    $this->addFlash('error', '⚠ Veuillez choisir une date future pour programmer la publication.');
                    return $this->redirectToRoute('blog_post_new');
                }
                // statut stays 'scheduled' — cron/task-scheduler command will flip it to 'public'
            }

            /** @var UploadedFile|null $mediaFile */
            $mediaFile = $form->get('media')->getData();
            if ($mediaFile) {
                $newFilename = time() . '_' . uniqid() . '.' . $this->getFileExtension($mediaFile);
                $mediaFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/uploads',
                    $newFilename
                );
                $post->setMedia($newFilename);
            }

            $em->persist($post);
            $em->flush();

            $message = $post->getStatut() === 'scheduled'
                ? sprintf('📅 Publication programmée pour le %s !', $post->getScheduledAt()->format('d/m/Y à H:i'))
                : '✅ Publication créée avec succès !';

            $this->addFlash('success', $message);
            return $this->redirectToRoute('blog_post_show', ['id' => $post->getIdPost()]);
        }

        return $this->render('blog/new.html.twig', ['form' => $form->createView()]);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────────

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
                return $this->redirectToRoute('app_login');
            }

            $spamError = $this->checkCommentSpam($request);
            if ($spamError !== null) {
                $this->addFlash('error', $spamError);
                return $this->redirectToRoute('blog_post_show', ['id' => $post->getIdPost()]);
            }

            $comment->setPost($post);
            $comment->setUser($user);
            $comment->setDate(new \DateTime());
            $em->persist($comment);
            $em->flush();

            $postOwner = $post->getUser();
            if ($postOwner && $postOwner->getId() !== $user->getId()) {
                $this->notificationService->push(
                    'COMMENT',
                    $postOwner->getId(),
                    $user->getFullName() ?? $user->getEmail(),
                    substr($post->getContenu(), 0, 80),
                    substr($comment->getContenu(), 0, 80)
                );
            }

            $this->addFlash('success', 'Commentaire ajouté.');
            return $this->redirectToRoute('blog_post_show', ['id' => $post->getIdPost()]);
        }

        $comments       = $em->getRepository(Commentaires::class)->findBy(['post' => $post, 'parent' => null], ['date' => 'ASC']);
        $userReaction   = $user ? $em->getRepository(Reactions::class)->findOneBy(['post' => $post, 'user' => $user]) : null;
        $reactionsCount = $em->getRepository(Reactions::class)->count(['post' => $post]);

        $isSaved = false;
        if ($user) {
            $isSaved = (bool) $em->getRepository(PostsSauvegardes::class)->findOneBy(['user' => $user, 'post' => $post]);
        }

        $replyForms = $editCommentForms = [];
        foreach ($comments as $c) {
            $replyForms[$c->getIdCommentaire()]       = $this->createForm(CommentType::class, new Commentaires())->createView();
            $editCommentForms[$c->getIdCommentaire()] = $this->createForm(CommentType::class, $c)->createView();
            foreach ($c->getReplies() as $reply) {
                $replyForms[$reply->getIdCommentaire()]       = $this->createForm(CommentType::class, new Commentaires())->createView();
                $editCommentForms[$reply->getIdCommentaire()] = $this->createForm(CommentType::class, $reply)->createView();
            }
        }

        return $this->render('blog/show.html.twig', [
            'post'             => $post,
            'comments'         => $comments,
            'commentForm'      => $form->createView(),
            'userReaction'     => $userReaction,
            'reactionsCount'   => $reactionsCount,
            'replyForms'       => $replyForms,
            'editCommentForms' => $editCommentForms,
            'currentUser'      => $user,
            'isSaved'          => $isSaved,
        ]);
    }

    // ── EDIT POST ─────────────────────────────────────────────────────────────

    #[Route('/post/{id}/edit', name: 'blog_post_edit', requirements: ['id' => '\d+'])]
    public function editPost(Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifyPostOwnership($post);

        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $modResult = $this->moderationService->moderate($post->getContenu());

            if ($modResult['shouldHide']) {
                $this->addFlash('error', sprintf(
                    '❌ Modification refusée par l\'IA (score: %.0f%%). Raison : %s',
                    $modResult['score'] * 100,
                    $modResult['reason']
                ));
                return $this->redirectToRoute('blog_post_edit', ['id' => $post->getIdPost()]);
            }

            // ✅ Validate scheduled publishing
            if ($post->getStatut() === 'scheduled') {
                if ($post->getScheduledAt() === null || $post->getScheduledAt() <= new \DateTime()) {
                    $this->addFlash('error', '⚠ Veuillez choisir une date future pour programmer la publication.');
                    return $this->redirectToRoute('blog_post_edit', ['id' => $post->getIdPost()]);
                }
            }

            /** @var UploadedFile|null $mediaFile */
            $mediaFile = $form->get('media')->getData();
            if ($mediaFile) {
                $oldMedia = $post->getMedia();
                if ($oldMedia) {
                    $oldPath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $oldMedia;
                    if (file_exists($oldPath)) unlink($oldPath);
                }
                $newFilename = time() . '_' . uniqid() . '.' . $this->getFileExtension($mediaFile);
                $mediaFile->move($this->getParameter('kernel.project_dir') . '/public/uploads', $newFilename);
                $post->setMedia($newFilename);
            }

            $em->flush();

            $message = $post->getStatut() === 'scheduled'
                ? sprintf('📅 Publication re-programmée pour le %s !', $post->getScheduledAt()->format('d/m/Y à H:i'))
                : '✅ Publication modifiée avec succès !';

            $this->addFlash('success', $message);
            return $this->redirectToRoute('blog_post_show', ['id' => $post->getIdPost()]);
        }

        return $this->render('blog/edit.html.twig', ['form' => $form->createView(), 'post' => $post]);
    }

    // ── DELETE POST ───────────────────────────────────────────────────────────

    #[Route('/post/{id}/delete', name: 'blog_post_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePost(Posts $post, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifyPostOwnership($post);

        if ($this->isCsrfTokenValid('delete' . $post->getIdPost(), $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Publication supprimée.');
        }

        return $this->redirectToRoute('app_blog');
    }

    // ── SAVE / UNSAVE POST ────────────────────────────────────────────────────

    #[Route('/post/{id}/save', name: 'blog_post_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function savePost(Posts $post, Request $request, EntityManagerInterface $em): JsonResponse|Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['error' => 'Non connecté'], 401);
            }
            $this->addFlash('error', 'Vous devez être connecté.');
            return $this->redirectToRoute('app_login');
        }

        $existing = $em->getRepository(PostsSauvegardes::class)->findOneBy(['user' => $user, 'post' => $post]);

        if ($existing) {
            $em->remove($existing);
            $saved   = false;
            $message = 'Retiré des sauvegardés';
        } else {
            $save = new PostsSauvegardes();
            $save->setUser($user);
            $save->setPost($post);
            $em->persist($save);
            $saved   = true;
            $message = 'Publication sauvegardée ✓';
        }
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->json(['saved' => $saved, 'message' => $message]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('blog_saved');
    }

    // ── SAVED POSTS PAGE ──────────────────────────────────────────────────────

    #[Route('/saved', name: 'blog_saved')]
    public function savedPosts(EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $saves = $em->getRepository(PostsSauvegardes::class)->findBy(['user' => $user]);
        $posts = array_map(fn($s) => $s->getPost(), $saves);

        return $this->render('blog/saved.html.twig', [
            'posts'       => $posts,
            'currentUser' => $user,
        ]);
    }

    // ── TRANSLATE POST ────────────────────────────────────────────────────────

    #[Route('/post/{id}/translate', name: 'blog_post_translate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function translatePost(Posts $post): JsonResponse
    {
        $result = $this->translationService->translateAuto($post->getContenu());
        return $this->json($result);
    }

    // ── EDIT COMMENT ──────────────────────────────────────────────────────────

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

    // ── DELETE COMMENT ────────────────────────────────────────────────────────

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

    // ── REPLY TO COMMENT ──────────────────────────────────────────────────────

    #[Route('/comment/{id}/reply', name: 'blog_comment_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function replyComment(Commentaires $parent, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) {
            $this->addFlash('error', 'Vous devez être connecté pour répondre.');
            return $this->redirectToRoute('app_login');
        }

        $spamError = $this->checkCommentSpam($request);
        if ($spamError !== null) {
            $this->addFlash('error', $spamError);
            return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
        }

        $content = trim($request->request->get('contenu', ''));
        if (strlen($content) < 2) {
            $this->addFlash('error', 'Minimum 2 caractères.');
            return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
        }
        if (strlen($content) > 500) {
            $this->addFlash('error', 'Maximum 500 caractères.');
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

        $commentOwner = $parent->getUser();
        if ($commentOwner && $commentOwner->getId() !== $user->getId()) {
            $this->notificationService->push(
                'REPLY_TO_COMMENT',
                $commentOwner->getId(),
                $user->getFullName() ?? $user->getEmail(),
                substr($parent->getPost()->getContenu(), 0, 80),
                substr($content, 0, 80)
            );
        }

        $this->addFlash('success', 'Réponse ajoutée.');
        return $this->redirectToRoute('blog_post_show', ['id' => $parent->getPost()->getIdPost()]);
    }

    // ── REACT TO POST ─────────────────────────────────────────────────────────

    #[Route('/post/{id}/react/{type}', name: 'blog_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function react(Posts $post, string $type, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) {
            return $this->json(['error' => 'Veuillez vous connecter'], 401);
        }

        $existing = $em->getRepository(Reactions::class)->findOneBy(['post' => $post, 'user' => $user]);

        if ($existing) {
            $em->remove($existing);
            $added = false;
        } else {
            $reaction = new Reactions();
            $reaction->setType($type)->setPost($post)->setUser($user)->setDate(new \DateTime());
            $em->persist($reaction);
            $added = true;

            $postOwner = $post->getUser();
            if ($postOwner && $postOwner->getId() !== $user->getId()) {
                $this->notificationService->push(
                    'REACTION_POST',
                    $postOwner->getId(),
                    $user->getFullName() ?? $user->getEmail(),
                    substr($post->getContenu(), 0, 80),
                    null
                );
            }
        }

        $em->flush();
        $count = $em->getRepository(Reactions::class)->count(['post' => $post]);

        return $this->json(['added' => $added, 'count' => $count]);
    }

    // ── REACT TO COMMENT ──────────────────────────────────────────────────────

    #[Route('/comment/{id}/react/{type}', name: 'blog_comment_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reactComment(Commentaires $comment, string $type, EntityManagerInterface $em): JsonResponse
    {
        $count = $em->getRepository(Reactions::class)->count(['commentaire' => $comment]);
        $user  = $this->userResolver->getCurrentUser();

        if (!$user) {
            return $this->json(['error' => 'Non authentifié', 'added' => false, 'count' => $count]);
        }

        $existing = $em->getRepository(Reactions::class)->findOneBy(['commentaire' => $comment, 'user' => $user]);

        if ($existing) {
            $em->remove($existing);
            $added = false;
        } else {
            $reaction = new Reactions();
            $reaction->setType($type)->setCommentaire($comment)->setUser($user)->setDate(new \DateTime());
            $em->persist($reaction);
            $added = true;

            $commentOwner = $comment->getUser();
            if ($commentOwner && $commentOwner->getId() !== $user->getId()) {
                $this->notificationService->push(
                    'REACTION_COMMENT',
                    $commentOwner->getId(),
                    $user->getFullName() ?? $user->getEmail(),
                    null,
                    substr($comment->getContenu(), 0, 80)
                );
            }
        }

        $em->flush();
        $count = $em->getRepository(Reactions::class)->count(['commentaire' => $comment]);

        return $this->json(['added' => $added, 'count' => $count]);
    }

    // ── NOTIFICATIONS ─────────────────────────────────────────────────────────

    #[Route('/notifications', name: 'blog_notifications')]
    public function notifications(): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if (!$user) return $this->redirectToRoute('app_login');

        $notifs = $this->notificationService->getForUser($user->getId());

        return $this->render('blog/notifications.html.twig', [
            'notifications' => $notifs,
            'currentUser'   => $user,
        ]);
    }

    #[Route('/notifications/mark-all-read', name: 'blog_notifications_read_all', methods: ['POST'])]
    public function markAllRead(): Response
    {
        $user = $this->userResolver->getCurrentUser();
        if ($user) $this->notificationService->markAllRead($user->getId());
        return $this->redirectToRoute('blog_notifications');
    }

    #[Route('/notifications/{id}/read', name: 'blog_notification_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markRead(int $id): JsonResponse
    {
        $this->notificationService->markRead($id);
        return $this->json(['ok' => true]);
    }
}