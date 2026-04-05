<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\RoleEnum;
use App\Enum\TFAMethod;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class AdminController extends AbstractController
{
    #[Route('/admin/dashboard', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $q = trim((string) $request->query->get('q', ''));
        $role = strtoupper(trim((string) $request->query->get('role', '')));
        $status = strtolower(trim((string) $request->query->get('status', '')));

        $qb = $userRepository->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC');

        if ($q !== '') {
            $qb->andWhere('LOWER(u.fullName) LIKE :search OR LOWER(u.email) LIKE :search OR LOWER(COALESCE(u.phoneNumber, \'\')) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($q) . '%');
        }

        if (in_array($role, array_map(static fn (RoleEnum $item) => $item->value, RoleEnum::cases()), true)) {
            $qb->andWhere('u.role = :role')->setParameter('role', RoleEnum::from($role));
        }

        if ($status === 'active') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', true);
        }

        if ($status === 'banned') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', false);
        }

        $users = $qb->getQuery()->getResult();

        $startOfMonth = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);

        $newThisMonth = (int) $userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :startMonth')
            ->setParameter('startMonth', $startOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        $stats = [
            'total' => $userRepository->count([]),
            'active' => $userRepository->count(['isActive' => true]),
            'banned' => $userRepository->count(['isActive' => false]),
            'admins' => $userRepository->count(['role' => RoleEnum::ADMIN]),
            'hosts' => $userRepository->count(['role' => RoleEnum::HOST]),
            'participants' => $userRepository->count(['role' => RoleEnum::PARTICIPANT]),
            'newThisMonth' => $newThisMonth,
        ];

        return $this->render('admin/dashboard.html.twig', [
            'users' => $users,
            'roles' => array_map(static fn (RoleEnum $role): array => [
                'value' => $role->value,
                'label' => $role->getLabel(),
            ], RoleEnum::cases()),
            'tfaMethods' => array_map(static fn (TFAMethod $method): array => [
                'value' => $method->value,
                'label' => $method->getLabel(),
            ], TFAMethod::cases()),
            'filters' => [
                'q' => $q,
                'role' => $role,
                'status' => $status,
            ],
            'stats' => $stats,
        ]);
    }

    #[Route('/admin/users/create', name: 'app_admin_user_create', methods: ['POST'])]
    public function createUser(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordHasherInterface $userPasswordHasher,
        SluggerInterface $slugger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $fullName = trim((string) $request->request->get('fullName', ''));
        $email = trim((string) $request->request->get('email', ''));
        $phoneNumber = trim((string) $request->request->get('phoneNumber', ''));
        $roleValue = strtoupper((string) $request->request->get('role', RoleEnum::PARTICIPANT->value));
        $tfaValue = strtoupper((string) $request->request->get('tfaMethod', TFAMethod::NONE->value));
        $password = (string) $request->request->get('password', '');

        $errors = $this->validateUserInput($fullName, $email, $phoneNumber, $roleValue, $tfaValue, $password, null, $userRepository);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_admin_dashboard');
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setPhoneNumber($phoneNumber === '' ? null : $phoneNumber);
        $user->setRole(RoleEnum::from($roleValue));
        $user->setTfaMethod(TFAMethod::from($tfaValue));
        $user->setIsActive(true);
        $user->setPassword($userPasswordHasher->hashPassword($user, $password));

        $profilePictureFile = $request->files->get('profilePicture');
        if ($profilePictureFile instanceof UploadedFile) {
            $profilePicture = $this->uploadProfilePicture($profilePictureFile, $slugger);
            if ($profilePicture !== null) {
                $user->setProfilePicture($profilePicture);
            }
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'User created successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/update', name: 'app_admin_user_update', methods: ['POST'])]
    public function updateUser(
        User $target,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordHasherInterface $userPasswordHasher,
        SluggerInterface $slugger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_update_' . $target->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $fullName = trim((string) $request->request->get('fullName', ''));
        $email = trim((string) $request->request->get('email', ''));
        $phoneNumber = trim((string) $request->request->get('phoneNumber', ''));
        $roleValue = strtoupper((string) $request->request->get('role', $target->getRole()->value));
        $tfaValue = strtoupper((string) $request->request->get('tfaMethod', $target->getTfaMethod()->value));
        $newPassword = (string) $request->request->get('password', '');

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $target->getId() && $roleValue !== RoleEnum::ADMIN->value) {
            $this->addFlash('error', 'You cannot change your own role from admin.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $errors = $this->validateUserInput($fullName, $email, $phoneNumber, $roleValue, $tfaValue, $newPassword, $target, $userRepository);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_admin_dashboard');
        }

        $target->setFullName($fullName);
        $target->setEmail($email);
        $target->setPhoneNumber($phoneNumber === '' ? null : $phoneNumber);
        $target->setRole(RoleEnum::from($roleValue));
        $target->setTfaMethod(TFAMethod::from($tfaValue));

        if ($newPassword !== '') {
            $target->setPassword($userPasswordHasher->hashPassword($target, $newPassword));
        }

        $profilePictureFile = $request->files->get('profilePicture');
        if ($profilePictureFile instanceof UploadedFile) {
            $profilePicture = $this->uploadProfilePicture($profilePictureFile, $slugger);
            if ($profilePicture !== null) {
                $target->setProfilePicture($profilePicture);
            }
        }

        $entityManager->flush();

        $this->addFlash('success', 'User updated successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/toggle-ban', name: 'app_admin_user_toggle_ban', methods: ['POST'])]
    public function toggleBan(User $target, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_ban_' . $target->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $target->getId()) {
            $this->addFlash('error', 'You cannot ban your own account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if ($target->getRole() === RoleEnum::ADMIN) {
            $this->addFlash('error', 'You cannot ban another admin account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $target->setIsActive(!$target->isIsActive());
        $entityManager->flush();

        $this->addFlash('success', $target->isIsActive() ? 'User unbanned successfully.' : 'User banned successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $target, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_delete_' . $target->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid form token. Please try again.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $target->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if ($target->getRole() === RoleEnum::ADMIN) {
            $this->addFlash('error', 'You cannot delete another admin account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $entityManager->remove($target);
        $entityManager->flush();

        $this->addFlash('success', 'User deleted successfully.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    /**
     * @return string[]
     */
    private function validateUserInput(
        string $fullName,
        string $email,
        string $phoneNumber,
        string $roleValue,
        string $tfaValue,
        string $password,
        ?User $currentTarget,
        UserRepository $userRepository
    ): array {
        $errors = [];

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        } elseif (mb_strlen($fullName) < 2) {
            $errors[] = 'Full name must be at least 2 characters.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        if ($phoneNumber !== '' && !preg_match('/^\+?[0-9\s\-]{8,20}$/', $phoneNumber)) {
            $errors[] = 'Phone number format is invalid.';
        }

        if (!in_array($roleValue, array_map(static fn (RoleEnum $item) => $item->value, RoleEnum::cases()), true)) {
            $errors[] = 'Invalid role selected.';
        }

        if (!in_array($tfaValue, array_map(static fn (TFAMethod $item) => $item->value, TFAMethod::cases()), true)) {
            $errors[] = 'Invalid 2FA method selected.';
        }

        $existingUser = $userRepository->findOneBy(['email' => $email]);
        if ($existingUser !== null && ($currentTarget === null || $existingUser->getId() !== $currentTarget->getId())) {
            $errors[] = 'Email is already used by another user.';
        }

        if ($currentTarget === null && $password === '') {
            $errors[] = 'Password is required for new users.';
        }

        if ($password !== '') {
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
                $errors[] = 'Password must contain uppercase, lowercase, and a number.';
            }
        }

        return $errors;
    }

    private function uploadProfilePicture(UploadedFile $profilePictureFile, SluggerInterface $slugger): ?string
    {
        $originalFilename = pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename);

        try {
            $extension = $profilePictureFile->guessExtension();
        } catch (\Throwable) {
            $extension = null;
        }

        if (!$extension) {
            $extension = strtolower(pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_EXTENSION));
        }

        if (!$extension) {
            $extension = 'bin';
        }

        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        try {
            $profilePictureFile->move(
                $this->getParameter('kernel.project_dir') . '/public/uploads/profiles',
                $newFilename
            );
        } catch (FileException) {
            return null;
        }

        return $newFilename;
    }
}
