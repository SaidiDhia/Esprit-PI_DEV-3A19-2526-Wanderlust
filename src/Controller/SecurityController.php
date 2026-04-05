<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\RoleEnum;
use App\Enum\TFAMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\String\Slugger\SluggerInterface;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route(path: '/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $old = [
            'email' => '',
            'fullName' => '',
            'phoneNumber' => '',
            'role' => 'PARTICIPANT',
        ];

        $errors = [];

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirmPassword', '');
            $fullName = trim((string) $request->request->get('fullName', ''));
            $phoneNumber = trim((string) $request->request->get('phoneNumber', ''));
            $roleValue = strtoupper((string) $request->request->get('role', 'PARTICIPANT'));

            $old = [
                'email' => $email,
                'fullName' => $fullName,
                'phoneNumber' => $phoneNumber,
                'role' => $roleValue,
            ];

            if ($fullName === '') {
                $errors['fullName'] = 'Full name is required.';
            } elseif (mb_strlen($fullName) < 2) {
                $errors['fullName'] = 'Full name must be at least 2 characters.';
            }

            if (!in_array($roleValue, [RoleEnum::HOST->value, RoleEnum::PARTICIPANT->value], true)) {
                $errors['role'] = 'Invalid role selected.';
            }

            if ($phoneNumber !== '' && !preg_match('/^\+?[0-9\s\-]{8,20}$/', $phoneNumber)) {
                $errors['phoneNumber'] = 'Phone number must contain only digits, spaces, or dashes.';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }

            if (strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
                $errors['password'] = 'Password must contain uppercase, lowercase, and a number.';
            }

            if ($confirmPassword === '') {
                $errors['confirmPassword'] = 'Please confirm your password.';
            } elseif ($password !== $confirmPassword) {
                $errors['confirmPassword'] = 'Passwords do not match.';
            }

            // Check if user exists
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                $errors['email'] = 'Email already registered.';
            }

            if ($errors !== []) {
                return $this->render('security/signup.html.twig', [
                    'errors' => $errors,
                    'old' => $old,
                ]);
            }

            $user = new User();
            $user->setId($this->generateUuidV4());
            $user->setEmail($email);
            $user->setFullName($fullName);
            $user->setPhoneNumber($phoneNumber === '' ? null : $phoneNumber);
            
            $user->setRole(RoleEnum::tryFrom($roleValue) ?? RoleEnum::PARTICIPANT);
            
            $user->setTfaMethod(TFAMethod::NONE);

            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $password
                )
            );

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
                } catch (FileException $e) {
                    // ... handle exception if something happens during file upload
                }

                $user->setProfilePicture($newFilename);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/signup.html.twig', [
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
