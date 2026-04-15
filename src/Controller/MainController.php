<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\TFAMethod;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    public function settings(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, SluggerInterface $slugger, TwoFactorService $twoFactorService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            if ($request->request->has('generateAppSecret')) {
                $action = 'generate_app_secret';
            }

            if ($action === 'update_profile') {
                $user->setFullName($request->request->get('fullName'));
                $user->setEmail($request->request->get('email'));

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

            if ($action === 'update_tfa_settings') {
                $phoneNumber = trim((string) $request->request->get('phoneNumber', ''));
                $user->setPhoneNumber($phoneNumber !== '' ? $phoneNumber : null);

                $tfaValue = $request->request->get('tfaMethod');
                $selectedMethod = TFAMethod::tryFrom((string) $tfaValue) ?? TFAMethod::NONE;

                $faceReferenceFile = $request->files->get('faceReferenceImage');
                $faceReferenceImageData = trim((string) $request->request->get('faceReferenceImageData', ''));

                if (in_array($selectedMethod, [TFAMethod::SMS, TFAMethod::WHATSAPP], true) && !$user->getPhoneNumber()) {
                    $this->addFlash('error', 'Phone number is required to enable SMS or WhatsApp verification.');
                    return $this->redirectToRoute('app_settings');
                }

                if ($selectedMethod === TFAMethod::APP && !$user->getTfaSecret()) {
                    $user->setTfaSecret($twoFactorService->generateAppSecret());
                }

                if ($selectedMethod === TFAMethod::FACE_ID && $user->getFaceReferenceImage() === null && !$faceReferenceFile && $faceReferenceImageData === '') {
                    $this->addFlash('error', 'Face reference image is required to enable Face ID. Upload or capture a face first.');
                    return $this->redirectToRoute('app_settings');
                }

                $user->setTfaMethod($selectedMethod);

                /** @var UploadedFile $faceReferenceFile */
                if ($faceReferenceFile) {
                    $originalFilename = pathinfo($faceReferenceFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    try {
                        $extension = $faceReferenceFile->guessExtension();
                    } catch (\Throwable $e) {
                        $extension = null;
                    }

                    if (!$extension) {
                        $extension = strtolower(pathinfo($faceReferenceFile->getClientOriginalName(), PATHINFO_EXTENSION));
                    }
                    if (!$extension) {
                        $extension = 'jpg';
                    }

                    $newFilename = $safeFilename.'-face-'.uniqid().'.'.$extension;

                    try {
                        $faceReferenceFile->move(
                            $this->getParameter('kernel.project_dir').'/public/uploads/profiles',
                            $newFilename
                        );
                        $user->setFaceReferenceImage($newFilename);
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Could not upload face reference image.');
                    }
                }

                if ($faceReferenceImageData !== '') {
                    $capturedFile = $this->storeCapturedFaceReference($faceReferenceImageData);
                    if ($capturedFile !== null) {
                        $user->setFaceReferenceImage($capturedFile);
                    } else {
                        $this->addFlash('error', 'Could not save captured face reference image.');
                    }
                }

                $entityManager->flush();
                $this->addFlash('success', 'Security and 2FA settings updated successfully.');
            }

            if ($action === 'generate_app_secret') {
                $user->setTfaSecret($twoFactorService->generateAppSecret());
                if ($user->getTfaMethod() !== TFAMethod::APP) {
                    $user->setTfaMethod(TFAMethod::APP);
                }

                $entityManager->flush();
                $this->addFlash('success', 'Authenticator app secret regenerated. Update your app with the new code.');
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
            'otpAuthUri' => $twoFactorService->getOtpAuthUri($user),
            'otpQrCodeUrl' => $twoFactorService->getOtpQrCodeUrl($user),
        ]);
    }

    private function storeCapturedFaceReference(string $dataUri): ?string
    {
        if (!str_contains($dataUri, ',')) {
            return null;
        }

        [$meta, $payload] = explode(',', $dataUri, 2);
        if (!str_contains($meta, ';base64')) {
            return null;
        }

        $binary = base64_decode($payload, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $extension = 'jpg';
        if (preg_match('#^data:image/([a-zA-Z0-9]+);base64$#', $meta, $matches)) {
            $candidate = strtolower($matches[1]);
            if ($candidate === 'jpeg') {
                $candidate = 'jpg';
            }

            if (in_array($candidate, ['jpg', 'png', 'webp'], true)) {
                $extension = $candidate;
            }
        }

        $filename = 'face-capture-'.uniqid('', true).'.'.$extension;
        $target = $this->getParameter('kernel.project_dir').'/public/uploads/profiles/'.$filename;

        try {
            file_put_contents($target, $binary);
        } catch (\Throwable) {
            return null;
        }

        return $filename;
    }
}
