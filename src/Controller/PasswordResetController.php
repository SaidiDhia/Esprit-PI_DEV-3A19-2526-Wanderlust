<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class PasswordResetController extends AbstractController
{
    private ?string $resetTokenTable = null;

    #[Route('/forgot-password', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        EmailService $emailService,
        Connection $connection
    ): Response
    {
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            $user = $userRepository->findOneBy(['email' => $email]);
            $tokenTable = $this->getResetTokenTable($connection);

            if ($user instanceof User && $tokenTable !== null) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                $connection->delete($tokenTable, ['user_id' => (string) $user->getId()]);
                $connection->insert($tokenTable, [
                    'token_id' => $this->generateUuidV4(),
                    'user_id' => (string) $user->getId(),
                    'token' => $code,
                    'expires_at' => (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s'),
                ]);

                $emailService->sendPasswordResetCode($user, $code);
            }

            $this->addFlash('success', 'If the email exists in our system, a 6-digit reset code has been sent.');

            return $this->redirectToRoute('app_reset_password');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        Connection $connection
    ): Response {
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            $code = trim((string) $request->request->get('code', ''));
            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirmPassword', '');

            $user = $userRepository->findOneBy(['email' => $email]);
            $tokenTable = $this->getResetTokenTable($connection);

            $errors = [];

            if (!$user instanceof User) {
                $errors[] = 'Invalid email or reset code.';
            }

            if (!preg_match('/^\d{6}$/', $code)) {
                $errors[] = 'Reset code must be 6 digits.';
            }

            if ($tokenTable === null) {
                $errors[] = 'Password reset service is temporarily unavailable.';
            }

            if ($user instanceof User && $tokenTable !== null) {
                $quotedTokenTable = $connection->getDatabasePlatform()->quoteIdentifier($tokenTable);
                $tokenExists = $connection->fetchOne(
                    sprintf('SELECT token_id FROM %s WHERE user_id = :userId AND token = :token AND expires_at >= :now LIMIT 1', $quotedTokenTable),
                    [
                        'userId' => (string) $user->getId(),
                        'token' => $code,
                        'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]
                );

                if ($tokenExists === false) {
                    $errors[] = 'Invalid or expired reset code.';
                }
            }

            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
            if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
                $errors[] = 'Password must contain uppercase, lowercase, and a number.';
            }
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->render('security/reset_password.html.twig');
            }

            /** @var User $user */
            $user->setPassword($userPasswordHasher->hashPassword($user, $password));
            $entityManager->flush();

            if ($tokenTable !== null) {
                $connection->delete($tokenTable, ['user_id' => (string) $user->getId()]);
            }

            $this->addFlash('success', 'Your password has been updated. You can sign in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig');
    }

    private function getResetTokenTable(Connection $connection): ?string
    {
        if ($this->resetTokenTable !== null) {
            return $this->resetTokenTable;
        }

        $schemaManager = $connection->createSchemaManager();
        foreach ($schemaManager->listTableNames() as $tableName) {
            $columnNames = array_map(
                static fn (string $name): string => strtolower($name),
                array_keys($schemaManager->listTableColumns($tableName))
            );

            $hasRequiredColumns = in_array('token_id', $columnNames, true)
                && in_array('user_id', $columnNames, true)
                && in_array('token', $columnNames, true)
                && in_array('expires_at', $columnNames, true);

            if ($hasRequiredColumns) {
                $this->resetTokenTable = $tableName;
                return $tableName;
            }
        }

        return null;
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
