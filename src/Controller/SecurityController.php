<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\RoleEnum;
use App\Enum\TFAMethod;
use App\Service\ActivityLogger;
use App\Service\EmailRiskDetectorService;
use App\Service\FaceVerificationService;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    #[Route(path: '/login/face-id', name: 'app_login_face_id', methods: ['POST'])]
    public function faceIdLogin(Request $request, EntityManagerInterface $entityManager, FaceVerificationService $faceVerificationService, Security $security, ActivityLogger $activityLogger): JsonResponse
    {
        $csrfToken = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('face_login', $csrfToken)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid request token. Refresh the page and try again.',
            ], Response::HTTP_FORBIDDEN);
        }

        $selfieData = (string) $request->request->get('faceImageData', '');
        if ($selfieData === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'No captured face image was provided.',
            ], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<User> $candidates */
        $candidates = $entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.faceReferenceImage IS NOT NULL')
            ->andWhere("u.faceReferenceImage <> ''")
            ->getQuery()
            ->getResult();

        $profilesDir = rtrim((string) $this->getParameter('kernel.project_dir'), '/\\').DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'profiles'.DIRECTORY_SEPARATOR;

        foreach ($candidates as $candidate) {
            if (!$candidate->isIsActive()) {
                continue;
            }

            $referenceFile = (string) $candidate->getFaceReferenceImage();
            $referencePath = $profilesDir.$referenceFile;
            if (!is_file($referencePath)) {
                continue;
            }

            $isMatch = $faceVerificationService->verifyBase64SelfieAgainstReference($selfieData, $referencePath);
            if (!$isMatch) {
                continue;
            }

            $security->login($candidate, null, 'main');

            $session = $request->getSession();
            if ($session) {
                $session->set('tfa.verified_user_id', $candidate->getId());
            }

            $activityLogger->logAction($candidate, 'auth', 'face_id_login_match', [
                'targetType' => 'session',
                'targetName' => 'Face ID login',
                'destination' => '/login/face-id',
            ]);

            return new JsonResponse([
                'success' => true,
                'redirectUrl' => $this->generateUrl('app_home'),
            ]);
        }

        $activityLogger->logAction(null, 'auth', 'face_id_login_failed', [
            'targetType' => 'session',
            'targetName' => 'Face ID login failed',
            'destination' => '/login/face-id',
        ]);

        return new JsonResponse([
            'success' => false,
            'message' => 'No matching Face ID profile found. Try again with better lighting.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    #[Route(path: '/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger,
        ActivityLogger $activityLogger,
        EmailRiskDetectorService $emailRiskDetectorService
    ): Response
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

            $emailRisk = $emailRiskDetectorService->assess($email);
            $requiresEmailVerification = $emailRiskDetectorService->shouldRequireVerification($email, $roleValue === RoleEnum::ADMIN->value);
            $user->setTfaMethod($requiresEmailVerification ? TFAMethod::EMAIL : TFAMethod::NONE);

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

            $activityLogger->logAction($user, 'auth', 'signup_success', [
                'targetType' => 'user',
                'targetId' => $user->getId(),
                'targetName' => $user->getFullName() ?: $user->getEmail(),
                'targetImage' => $user->getProfilePicture(),
                'destination' => '/signup',
                'metadata' => [
                    'email' => $user->getEmail(),
                    'role' => $user->getRoleValue(),
                    'email_risk_score' => (int) ($emailRisk['score'] ?? 0),
                    'email_risk_reasons' => (array) ($emailRisk['reasons'] ?? []),
                    'forced_email_verification' => $requiresEmailVerification,
                ],
            ]);

            if ($requiresEmailVerification) {
                $this->addFlash('error', 'Your email looks temporary or high-risk. Email verification will be required at sign-in.');
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/signup.html.twig', [
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    #[Route(path: '/tfa/challenge', name: 'app_tfa_challenge', methods: ['GET', 'POST'])]
    public function tfaChallenge(Request $request, TwoFactorService $twoFactorService, FaceVerificationService $faceVerificationService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $session = $request->getSession();
        if (!$session) {
            return $this->redirectToRoute('app_login');
        }

        $method = $user->getTfaMethod();
        if ($method === TFAMethod::NONE) {
            $session->set('tfa.verified_user_id', $user->getId());
            return $this->redirectToRoute('app_home');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'verify');
            if ($action === 'resend' && in_array($method, [TFAMethod::EMAIL, TFAMethod::SMS, TFAMethod::WHATSAPP], true)) {
                if (!$twoFactorService->canResendCode($session)) {
                    $error = 'Please wait a few seconds before requesting another code.';
                } elseif ($twoFactorService->issueCodeForUser($user, $session)) {
                    $channelLabel = $method === TFAMethod::EMAIL ? 'Email' : ($method === TFAMethod::WHATSAPP ? 'WhatsApp' : 'SMS');
                    $this->addFlash('success', sprintf('%s verification code sent.', $channelLabel));
                } else {
                    $error = 'Could not send a verification code. Check your contact configuration.';
                }
            } else {
                $verified = false;
                if ($method === TFAMethod::EMAIL) {
                    $code = trim((string) $request->request->get('code', ''));
                    $verified = $twoFactorService->verifyCode($session, $code);
                    if (!$verified) {
                        $error = 'Invalid or expired verification code.';
                    }
                } elseif ($method === TFAMethod::SMS) {
                    $code = trim((string) $request->request->get('code', ''));
                    $verified = $twoFactorService->verifyMessageCode($user, $session, $code);
                    if (!$verified) {
                        $error = 'Invalid or expired SMS verification code.';
                    }
                } elseif ($method === TFAMethod::WHATSAPP) {
                    $code = trim((string) $request->request->get('code', ''));
                    $verified = $twoFactorService->verifyMessageCode($user, $session, $code);
                    if (!$verified) {
                        $error = 'Invalid or expired WhatsApp verification code.';
                    }
                } elseif ($method === TFAMethod::APP) {
                    $secret = (string) $user->getTfaSecret();
                    $code = trim((string) $request->request->get('code', ''));
                    if ($secret === '') {
                        $error = 'Authenticator app is not configured. Please configure it in settings.';
                    } else {
                        $verified = $twoFactorService->verifyTotpCode($secret, $code);
                        if (!$verified) {
                            $error = 'Invalid authenticator app code.';
                        }
                    }
                } elseif ($method === TFAMethod::FACE_ID) {
                    $selfieData = (string) $request->request->get('faceImageData', '');
                    $referenceFile = $user->getFaceReferenceImage();
                    if (!$referenceFile) {
                        $error = 'Face ID is not configured yet. Capture or upload your face reference in Security settings.';
                    } else {
                        $referencePath = $this->getParameter('kernel.project_dir').'/public/uploads/profiles/'.$referenceFile;
                        $verified = $faceVerificationService->verifyBase64SelfieAgainstReference($selfieData, $referencePath);
                        if (!$verified) {
                            $error = 'Face verification failed. Ensure good lighting and keep your face centered.';
                        }
                    }
                }

                if ($verified) {
                    $session->set('tfa.verified_user_id', $user->getId());
                    $twoFactorService->clearCode($session);
                    return $this->redirectToRoute('app_home');
                }
            }
        }

        if (in_array($method, [TFAMethod::EMAIL, TFAMethod::SMS, TFAMethod::WHATSAPP], true)) {
            $codeHash = (string) $session->get('tfa.login.code', '');
            $expiresAt = (int) $session->get('tfa.login.code_expires_at', 0);
            if ($codeHash === '' || $expiresAt < time()) {
                $twoFactorService->issueCodeForUser($user, $session);
            }
        }

        return $this->render('security/tfa_challenge.html.twig', [
            'method' => $method,
            'errorMessage' => $error,
            'otpAuthUri' => $method === TFAMethod::APP ? $twoFactorService->getOtpAuthUri($user) : null,
            'otpQrCodeUrl' => $method === TFAMethod::APP ? $twoFactorService->getOtpQrCodeUrl($user) : null,
            'hasFaceReference' => (bool) $user->getFaceReferenceImage(),
            'userPhoneNumber' => $user->getPhoneNumber(),
        ]);
    }

    #[Route(path: '/tfa/resend', name: 'app_tfa_resend', methods: ['POST'])]
    public function tfaResend(Request $request, TwoFactorService $twoFactorService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array($user->getTfaMethod(), [TFAMethod::EMAIL, TFAMethod::SMS, TFAMethod::WHATSAPP], true)) {
            return $this->redirectToRoute('app_tfa_challenge');
        }

        $session = $request->getSession();
        if ($session && $twoFactorService->canResendCode($session)) {
            $twoFactorService->issueCodeForUser($user, $session);
            $this->addFlash('success', 'Verification code resent successfully.');
        } else {
            $this->addFlash('error', 'Please wait before resending another code.');
        }

        return $this->redirectToRoute('app_tfa_challenge');
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
