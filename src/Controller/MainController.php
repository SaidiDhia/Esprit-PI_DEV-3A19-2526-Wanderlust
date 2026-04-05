<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\TFAMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class MainController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('main/home.html.twig');
    }

    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'update_profile') {
                $user->setFullName($request->request->get('fullName'));
                $user->setEmail($request->request->get('email'));
                $user->setPhoneNumber($request->request->get('phoneNumber'));
                
                $tfaValue = $request->request->get('tfaMethod');
                $user->setTfaMethod(TFAMethod::tryFrom($tfaValue) ?? TFAMethod::NONE);

                /** @var UploadedFile $profilePictureFile */
                $profilePictureFile = $request->files->get('profilePicture');
                if ($profilePictureFile) {
                    $originalFilename = pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    try {
                        $extension = $profilePictureFile->guessExtension();
                    } catch (\Throwable $e) {
                        $extension = null;
                    }

                    if (!$extension) {
                        $extension = strtolower(pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_EXTENSION));
                    }
                    if (!$extension) {
                        $extension = 'bin';
                    }

                    $newFilename = $safeFilename.'-'.uniqid().'.'.$extension;

                    try {
                        $profilePictureFile->move(
                            $this->getParameter('kernel.project_dir').'/public/uploads/profiles',
                            $newFilename
                        );
                        $user->setProfilePicture($newFilename);
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Could not upload profile picture.');
                    }
                }

                $entityManager->flush();
                $this->addFlash('success', 'Profile updated successfully.');
            }

            if ($action === 'change_password') {
                $currentPassword = $request->request->get('currentPassword');
                $newPassword = $request->request->get('newPassword');
                $confirmPassword = $request->request->get('confirmPassword');

                if (!$userPasswordHasher->isPasswordValid($user, $currentPassword)) {
                    $this->addFlash('error', 'Current password is incorrect.');
                } elseif ($newPassword !== $confirmPassword) {
                    $this->addFlash('error', 'New passwords do not match.');
                } else {
                    $user->setPassword($userPasswordHasher->hashPassword($user, $newPassword));
                    $entityManager->flush();
                    $this->addFlash('success', 'Password changed successfully.');
                }
            }

            return $this->redirectToRoute('app_settings');
        }

        return $this->render('main/settings.html.twig', [
            'user' => $user,
            'tfaMethods' => TFAMethod::cases(),
        ]);
    }
}
