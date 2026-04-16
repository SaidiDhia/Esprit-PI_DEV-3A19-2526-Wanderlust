<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\ConversationUser;
use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

#[Route('/messaging')]
class MessagingController extends AbstractController
{
    private string $uploadDir;

    public function __construct()
    {
        $this->uploadDir = __DIR__ . '/../../public/uploads/';

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    private function getCurrentUserId(): string
    {
        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException('You must be logged in to use messaging.');
        }

        if (method_exists($user, 'getId')) {
            $id = $user->getId();
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        if (method_exists($user, 'getUserId')) {
            $id = $user->getUserId();
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        throw $this->createAccessDeniedException('Authenticated user ID is unavailable.');
    }

    private function getPostString(Request $request, string $key, string $default = ''): string
    {
        $payload = $request->request->all();
        $value = $payload[$key] ?? $default;

        if (is_scalar($value) || $value instanceof \Stringable) {
            return trim((string) $value);
        }

        return $default;
    }

    /**
     * @return string[]
     */
    private function getPostStringArray(Request $request, string $key): array
    {
        $payload = $request->request->all();
        $value = $payload[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $entry) {
            if (is_scalar($entry) || $entry instanceof \Stringable) {
                $stringValue = trim((string) $entry);
                if ($stringValue !== '') {
                    $items[] = $stringValue;
                }
            }
        }

        return $items;
    }

    private function parseIniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf('%.1f %s', $value, $units[$power]);
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        $maxUpload = $this->formatBytes($this->parseIniSizeToBytes((string) ini_get('upload_max_filesize')));

        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large. Maximum allowed size is ' . $maxUpload . '.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a file before uploading.',
            UPLOAD_ERR_NO_TMP_DIR => 'Upload failed: temporary folder is missing on server.',
            UPLOAD_ERR_CANT_WRITE => 'Upload failed: server cannot write file to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.',
            default => 'Upload failed with code: ' . $errorCode,
        };
    }

    private function getMimeTypeFromExtension(string $filename): string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'wmv' => 'video/x-ms-wmv',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'aac' => 'audio/aac',
            'm4a' => 'audio/mp4',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

#[Route('/', name: 'app_messaging')]
public function index(Request $request, EntityManagerInterface $em): Response
{
    $currentUserId = $this->getCurrentUserId();
    $searchQuery = trim($request->query->get('search', ''));
    $conn = $em->getConnection();

    // Build base query
    $where = "cu.user_id = :userId AND cu.is_active = 1 AND c.is_archived = 0";
    $orderBy = "c.is_pinned DESC, c.last_activity DESC, c.id DESC";

    // Add search filter
    if (!empty($searchQuery)) {
        $where .= " AND (c.name LIKE :query OR c.type LIKE :query)";
    }

    $sql = "
        SELECT c.*, MAX(cu.role) as user_role
        FROM conversation c
        JOIN conversation_user cu ON c.id = cu.conversation_id
        WHERE $where
        GROUP BY c.id
        ORDER BY $orderBy
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue('userId', $currentUserId);
    if (!empty($searchQuery)) {
        $stmt->bindValue('query', '%' . $searchQuery . '%');
    }
    $result = $stmt->executeQuery();
    $conversations = $result->fetchAllAssociative();

    // Get archived conversations
    $sqlArchived = "
        SELECT c.*, MAX(cu.role) as user_role
        FROM conversation c
        JOIN conversation_user cu ON c.id = cu.conversation_id
        WHERE cu.user_id = :userId AND cu.is_active = 1 AND c.is_archived = 1
        GROUP BY c.id
        ORDER BY c.last_activity DESC, c.id DESC
    ";

    $stmtArchived = $conn->prepare($sqlArchived);
    $stmtArchived->bindValue('userId', $currentUserId);
    $resultArchived = $stmtArchived->executeQuery();
    $archivedConversations = $resultArchived->fetchAllAssociative();

    return $this->render('messaging/index.html.twig', [
        'conversations' => $conversations,
        'archivedConversations' => $archivedConversations,
    ]);
}

    #[Route('/conversation/{id}', name: 'messaging_conversation_show', requirements: ['id' => '\d+'])]
    public function showConversation(int $id, EntityManagerInterface $em): Response
    {
        $currentUserId = $this->getCurrentUserId();

        // Get conversation details
        $conn = $em->getConnection();

        $sql = "SELECT * FROM conversation WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $result = $stmt->executeQuery();
        $conversation = $result->fetchAssociative();

        if (!$conversation) {
            throw $this->createNotFoundException('Conversation not found');
        }

        // Get messages
        $sql = "
            SELECT m.*, u.full_name as sender_name
            FROM message m
            LEFT JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = :conversationId
            ORDER BY m.created_at ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('conversationId', $id);
        $result = $stmt->executeQuery();
        $messages = $result->fetchAllAssociative();

        // Get all conversations for forward modal
        $sqlConv = "
            SELECT c.*, MAX(cu.role) as user_role
            FROM conversation c
            JOIN conversation_user cu ON c.id = cu.conversation_id
            WHERE cu.user_id = :userId AND cu.is_active = 1 AND c.is_archived = 0
            GROUP BY c.id
            ORDER BY c.is_pinned DESC, c.last_activity DESC, c.id DESC
        ";
        $stmtConv = $conn->prepare($sqlConv);
        $stmtConv->bindValue('userId', $currentUserId);
        $resultConv = $stmtConv->executeQuery();
        $conversations = $resultConv->fetchAllAssociative();

        return $this->render('messaging/show.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages,
            'conversations' => $conversations,
            'currentUserId' => $currentUserId,
        ]);
    }

    #[Route('/conversation/{id}/search', name: 'messaging_search', requirements: ['id' => '\d+'])]
    public function searchMessages(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $query = trim($request->query->get('q', ''));
        $currentUserId = $this->getCurrentUserId();
        $conn = $em->getConnection();

        // Get conversation details
        $sql = "SELECT * FROM conversation WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $result = $stmt->executeQuery();
        $conversation = $result->fetchAssociative();

        if (!$conversation) {
            throw $this->createNotFoundException('Conversation not found');
        }

        // Search messages
        $sql = "
            SELECT m.*, u.full_name as sender_name
            FROM message m
            LEFT JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = :id
            AND (
                m.content LIKE :query
                OR u.full_name LIKE :query
                OR m.message_type LIKE :query
                OR m.file_name LIKE :query
            )
            ORDER BY m.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->bindValue('query', '%' . $query . '%');
        $result = $stmt->executeQuery();
        $messages = $result->fetchAllAssociative();

        return $this->render('messaging/search_results.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages,
            'query' => $query,
            'currentUserId' => $currentUserId,
        ]);
    }

    #[Route('/conversation/new', name: 'messaging_conversation_new', methods: ['GET', 'POST'])]
    public function newConversation(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $name = $this->getPostString($request, 'name');
            $type = strtoupper($this->getPostString($request, 'type'));
            $participantEmails = array_values(array_unique(array_filter(array_map(
                static fn (string $email): string => trim(mb_strtolower($email)),
                $this->getPostStringArray($request, 'participant_emails')
            ))));
            $currentUserId = $this->getCurrentUserId();
            
            // Validate: name cannot be empty
            if (empty($name)) {
                $this->addFlash('error', 'Conversation name cannot be empty!');
                return $this->redirectToRoute('app_messaging');
            }
            
            // For GROUP conversations, need at least 2 participants (excluding current user)
            if ($type === 'GROUP' && count($participantEmails) < 2) {
                $this->addFlash('error', 'Group conversation needs at least 2 participants!');
                return $this->redirectToRoute('app_messaging');
            }
            
            // For PERSONAL, need exactly 1 participant
            if ($type === 'PERSONAL' && count($participantEmails) != 1) {
                $this->addFlash('error', 'Personal conversation needs exactly 1 participant!');
                return $this->redirectToRoute('app_messaging');
            }
            
            // Find all participants
            $conn = $em->getConnection();
            $participantIds = [];
            $participantIdMap = [];
            
            foreach ($participantEmails as $email) {
                $email = trim($email);
                if (empty($email)) continue;
                
                $sql = "SELECT id FROM users WHERE email = :email";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('email', $email);
                $result = $stmt->executeQuery();
                $participant = $result->fetchAssociative();
                
                if (!$participant) {
                    $this->addFlash('error', 'User not found with email: ' . $email);
                    return $this->redirectToRoute('app_messaging');
                }

                $participantId = (string) $participant['id'];
                // Don't skip current user - they will be added as CREATOR
                // Just add all participants found
                if (!isset($participantIdMap[$participantId])) {
                    $participantIdMap[$participantId] = true;
                    $participantIds[] = $participantId;
                }
            }

            if ($type === 'GROUP' && count($participantIds) < 2) {
                $this->addFlash('error', 'Group conversation needs at least 2 other participants.');
                return $this->redirectToRoute('app_messaging');
            }

            if ($type === 'PERSONAL' && count($participantIds) !== 1) {
                $this->addFlash('error', 'Personal conversation needs exactly 1 other participant.');
                return $this->redirectToRoute('app_messaging');
            }
            
            // Create conversation
            $conversation = new Conversation();
            $conversation->setName($name);
            $conversation->setType($type);
            $conversation->setCreatedAt(new \DateTime());
            
            $em->persist($conversation);
            $em->flush();
            
            // Add current user as CREATOR
            $cu = new ConversationUser();
            $cu->setConversationId($conversation->getId());
            $cu->setUserId($currentUserId);
            $cu->setRole('CREATOR');
            $em->persist($cu);
            
            // Add participants as MEMBERS
            foreach ($participantIds as $userId) {
                $cu2 = new ConversationUser();
                $cu2->setConversationId($conversation->getId());
                $cu2->setUserId($userId);
                $cu2->setRole('MEMBER');
                $em->persist($cu2);
            }
            
            $em->flush();
            
            $this->addFlash('success', 'Conversation created successfully!');
            return $this->redirectToRoute('app_messaging');
        }
        
        return $this->render('messaging/new.html.twig');
    }

    #[Route('/conversation/{id}/rename', name: 'messaging_conversation_rename', methods: ['POST'])]
    public function renameConversation(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $newName = $this->getPostString($request, 'name');
        
        // Validate: name cannot be empty
        if (empty($newName)) {
            $this->addFlash('error', 'Conversation name cannot be empty!');
            return $this->redirectToRoute('app_messaging');
        }
        
        $conn = $em->getConnection();
        $sql = "UPDATE conversation SET name = :name WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('name', $newName);
        $stmt->bindValue('id', $id);
        $stmt->executeStatement();
        
        $this->addFlash('success', 'Conversation renamed!');
        return $this->redirectToRoute('app_messaging');
    }

    #[Route('/conversation/{id}/delete', name: 'messaging_conversation_delete', methods: ['POST'])]
    public function deleteConversation(int $id, EntityManagerInterface $em): Response
    {
        // Check if user is creator
        $conn = $em->getConnection();
        $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('convId', $id);
        $stmt->bindValue('userId', $this->getCurrentUserId());
        $result = $stmt->executeQuery();
        $role = $result->fetchOne();
        
        if ($role !== 'CREATOR') {
            $this->addFlash('error', 'Only the conversation creator can delete it.');
            return $this->redirectToRoute('app_messaging');
        }
        
        // Delete messages, participants, then conversation
        $sql = "DELETE FROM message WHERE conversation_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->executeStatement();
        
        $sql = "DELETE FROM conversation_user WHERE conversation_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->executeStatement();
        
        $sql = "DELETE FROM conversation WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->executeStatement();
        
        $this->addFlash('success', 'Conversation deleted!');
        return $this->redirectToRoute('app_messaging');
    }

    #[Route('/message/send', name: 'messaging_message_send', methods: ['POST'])]
    public function sendMessage(Request $request, EntityManagerInterface $em): Response
    {
        $conversationId = (int) $this->getPostString($request, 'conversation_id', '0');
        $content = $this->getPostString($request, 'content');

        if ($conversationId <= 0 || $content === '') {
            $this->addFlash('error', 'Conversation and message content are required.');
            return $this->redirectToRoute('app_messaging');
        }
        
        $message = new Message();
        $message->setConversationId($conversationId);
        $message->setSenderId($this->getCurrentUserId());
        $message->setContent($content);
        $message->setMessageType('TEXT');
        $message->setCreatedAt(new \DateTime());
        
        $em->persist($message);
        $em->flush();
        
        // Update conversation last activity
        $conn = $em->getConnection();
        $sql = "UPDATE conversation SET last_activity = NOW() WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $conversationId);
        $stmt->executeStatement();
        
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }

    #[Route('/message/{id}/edit', name: 'messaging_message_edit', methods: ['POST'])]
public function editMessage(int $id, Request $request, EntityManagerInterface $em): Response
{
    $newContent = $this->getPostString($request, 'content');
    
    // Validate: content cannot be empty
    if (empty($newContent)) {
        $this->addFlash('error', 'Message cannot be empty!');
        
        // Get conversation ID to redirect back
        $conn = $em->getConnection();
        $sql = "SELECT conversation_id FROM message WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $result = $stmt->executeQuery();
        $convId = $result->fetchOne();
        
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $convId]);
    }
    
    $conn = $em->getConnection();
    $sql = "UPDATE message SET content = :content, edited_at = NOW() WHERE id = :id AND sender_id = :userId";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('content', $newContent);
    $stmt->bindValue('id', $id);
    $stmt->bindValue('userId', $this->getCurrentUserId());
    $stmt->executeStatement();
    
    // Get conversation ID to redirect back
    $sql = "SELECT conversation_id FROM message WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $id);
    $result = $stmt->executeQuery();
    $convId = $result->fetchOne();
    
    $this->addFlash('success', 'Message edited!');
    return $this->redirectToRoute('messaging_conversation_show', ['id' => $convId]);
}

    #[Route('/message/{id}/delete', name: 'messaging_message_delete', methods: ['POST'])]
    public function deleteMessage(int $id, EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();
        
        // Get conversation ID first
        $sql = "SELECT conversation_id FROM message WHERE id = :id AND sender_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->bindValue('userId', $this->getCurrentUserId());
        $result = $stmt->executeQuery();
        $convId = $result->fetchOne();
        
        if (!$convId) {
            $this->addFlash('error', 'Message not found or you cannot delete it');
            return $this->redirectToRoute('app_messaging');
        }
        
        $sql = "DELETE FROM message WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->executeStatement();
        
        $this->addFlash('success', 'Message deleted!');
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $convId]);
    }

#[Route('/message/upload', name: 'messaging_message_upload', methods: ['POST'])]
public function uploadFile(Request $request, EntityManagerInterface $em): Response
{
    $conversationId = (int) $this->getPostString($request, 'conversation_id', '0');

    if ($conversationId <= 0) {
        $this->addFlash('error', 'Conversation is required.');
        return $this->redirectToRoute('app_messaging');
    }
    
    $fileInput = $request->files->get('file');
    if (!$fileInput instanceof UploadedFile) {
        if (is_array($fileInput)) {
            $this->addFlash('error', 'Please upload exactly one file at a time.');
            return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
        }

        // No file sent or post body exceeded limits before PHP could build UploadedFile.
        $this->addFlash('error', 'Please choose a file before uploading.');
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }

    if (!$fileInput->isValid()) {
        $this->addFlash('error', $this->uploadErrorMessage($fileInput->getError()));
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }

    $originalName = $fileInput->getClientOriginalName();
    $fileSize = (int) ($fileInput->getSize() ?? 0);
    
    // Generate unique filename
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $newName = time() . '_' . uniqid() . '.' . $extension;

    try {
        $fileInput->move($this->uploadDir, $newName);
    } catch (FileException) {
        $this->addFlash('error', 'Failed to store uploaded file on server.');
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }
    
    // Determine message type from extension
    $messageType = 'FILE';
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $videoExtensions = ['mp4', 'avi', 'mov', 'mkv', 'wmv'];
    $audioExtensions = ['mp3', 'wav', 'aac', 'ogg', 'm4a'];
    
    $ext = strtolower($extension);
    if (in_array($ext, $imageExtensions)) {
        $messageType = 'IMAGE';
    } elseif (in_array($ext, $videoExtensions)) {
        $messageType = 'VIDEO';
    } elseif (in_array($ext, $audioExtensions)) {
        $messageType = 'AUDIO';
    }
    
    $message = new Message();
    $message->setConversationId($conversationId);
    $message->setSenderId($this->getCurrentUserId());
    $message->setContent('[' . $messageType . '] ' . $originalName);
    $message->setMessageType($messageType);
    $message->setFileUrl('/uploads/' . $newName);
    $message->setFileName($originalName);
    $message->setFileSize($fileSize);
    $message->setCreatedAt(new \DateTime());
    
    $em->persist($message);
    $em->flush();
    
    $this->addFlash('success', 'File uploaded!');
    return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
}
#[Route('/uploads/{filename}', name: 'serve_upload', methods: ['GET'])]
public function serveUpload(string $filename): Response
{
    // Get the correct file path
    $filePath = $this->uploadDir . $filename;
    
    if (!file_exists($filePath)) {
        // Try alternative path
        $altPath = __DIR__ . '/../../public/uploads/' . $filename;
        if (file_exists($altPath)) {
            $filePath = $altPath;
        } else {
            throw $this->createNotFoundException('File not found: ' . $filename);
        }
    }
    
    // Avoid MIME guesser dependency (php_fileinfo) by setting Content-Type from extension.
    $response = new BinaryFileResponse($filePath);
    $response->headers->set('Content-Type', $this->getMimeTypeFromExtension($filename));
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);

    return $response;
}
#[Route('/conversation/{id}/pin', name: 'messaging_conversation_pin', methods: ['POST'])]
public function pinConversation(int $id, EntityManagerInterface $em): Response
{
    $conn = $em->getConnection();
    $sql = "UPDATE conversation SET is_pinned = NOT is_pinned WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $id);
    $stmt->executeStatement();
    
    $this->addFlash('success', 'Conversation pin status updated!');
    return $this->redirectToRoute('app_messaging');
}

#[Route('/conversation/{id}/archive', name: 'messaging_conversation_archive', methods: ['POST'])]
public function archiveConversation(int $id, EntityManagerInterface $em): Response
{
    $conn = $em->getConnection();
    $sql = "UPDATE conversation SET is_archived = NOT is_archived WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $id);
    $stmt->executeStatement();
    
    $this->addFlash('success', 'Conversation archived/unarchived!');
    return $this->redirectToRoute('app_messaging');
}

#[Route('/conversation/{id}/participants', name: 'messaging_conversation_participants', methods: ['GET'])]
public function getParticipants(int $id, EntityManagerInterface $em): Response
{
    $currentUserId = $this->getCurrentUserId();
    $conn = $em->getConnection();
    
    // Check if user is in conversation
    $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $this->getCurrentUserId());
    $result = $stmt->executeQuery();
    $userRole = $result->fetchOne();
    
    if (!$userRole) {
        $this->addFlash('error', 'You are not in this conversation');
        return $this->redirectToRoute('app_messaging');
    }
    
    // Get all participants
    $sql = "SELECT cu.user_id, cu.role, cu.joined_at, cu.added_by, u.email, u.full_name 
            FROM conversation_user cu
            JOIN users u ON cu.user_id = u.id
            WHERE cu.conversation_id = :convId AND cu.is_active = 1
            ORDER BY 
                CASE cu.role 
                    WHEN 'CREATOR' THEN 1
                    WHEN 'ADMIN' THEN 2
                    ELSE 3
                END, u.full_name";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $result = $stmt->executeQuery();
    $participants = $result->fetchAllAssociative();
    
    return $this->render('messaging/participants.html.twig', [
        'conversationId' => $id,
        'participants' => $participants,
        'userRole' => $userRole,
        'currentUserId' => $currentUserId,
    ]);
}

#[Route('/conversation/{id}/participant/add', name: 'messaging_conversation_add_participant', methods: ['POST'])]
public function addParticipant(int $id, Request $request, EntityManagerInterface $em): Response
{
    $email = $this->getPostString($request, 'email');

    if ($email === '') {
        $this->addFlash('error', 'Participant email is required.');
        return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
    }
    $conn = $em->getConnection();
    
    // Check if current user is CREATOR or ADMIN
    $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $this->getCurrentUserId());
    $result = $stmt->executeQuery();
    $userRole = $result->fetchOne();
    
    if (!in_array($userRole, ['CREATOR', 'ADMIN'])) {
        $this->addFlash('error', 'Only CREATOR and ADMIN can add participants');
        return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
    }
    
    // Find user by email
    $sql = "SELECT id FROM users WHERE email = :email";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('email', $email);
    $result = $stmt->executeQuery();
    $newUser = $result->fetchAssociative();
    
    if (!$newUser) {
        $this->addFlash('error', 'User not found with email: ' . $email);
        return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
    }
    
        // Check if user was previously in conversation (soft deleted)
        $sql = "SELECT id, is_active FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('convId', $id);
        $stmt->bindValue('userId', $newUser['id']);
        $result = $stmt->executeQuery();
        $existing = $result->fetchAssociative();
        
        if ($existing) {
            if ($existing['is_active'] == 1) {
                $this->addFlash('error', 'User already in conversation');
                return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
            } else {
                // Reactivate the user (was soft deleted)
                $sql = "UPDATE conversation_user SET is_active = 1, joined_at = NOW(), added_by = :addedBy WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('id', $existing['id']);
                $stmt->bindValue('addedBy', $this->getCurrentUserId());
                $stmt->executeStatement();
                
                // Add system message
                $sql = "INSERT INTO message (conversation_id, sender_id, content, message_type, created_at) 
                        VALUES (:convId, :senderId, :content, 'TEXT', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('convId', $id);
                $stmt->bindValue('senderId', $this->getCurrentUserId());
                $stmt->bindValue('content', '👤 ' . $email . ' was re-added to the conversation');
                $stmt->executeStatement();
                
                $this->addFlash('success', 'User re-added to conversation!');
                return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
            }
        }
        
        // Add new participant as MEMBER
        $sql = "INSERT INTO conversation_user (conversation_id, user_id, role, added_by, joined_at, is_active) 
                VALUES (:convId, :userId, 'MEMBER', :addedBy, NOW(), 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('convId', $id);
        $stmt->bindValue('userId', $newUser['id']);
        $stmt->bindValue('addedBy', $this->getCurrentUserId());
        $stmt->executeStatement();
        
        $this->addFlash('success', 'Participant added successfully!');
        return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
    }

    #[Route('/conversation/{id}/participant/{userId}/remove', name: 'messaging_conversation_remove_participant', methods: ['POST'])]
    public function removeParticipant(int $id, string $userId, EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();
        
        // Check if current user is CREATOR or ADMIN
        $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('convId', $id);
        $stmt->bindValue('userId', $this->getCurrentUserId());
        $result = $stmt->executeQuery();
        $userRole = $result->fetchOne();
        
        if (!in_array($userRole, ['CREATOR', 'ADMIN'])) {
            $this->addFlash('error', 'Only CREATOR and ADMIN can remove participants');
            return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
        }
        
        // Cannot remove CREATOR
        $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('convId', $id);
        $stmt->bindValue('userId', $userId);
        $result = $stmt->executeQuery();
        $targetRole = $result->fetchOne();
        
        if ($targetRole === 'CREATOR') {
            $this->addFlash('error', 'Cannot remove the conversation creator');
            return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
        }
        
        // Soft delete (set is_active = 0)
        $sql = "UPDATE conversation_user SET is_active = 0 WHERE conversation_id = :convId AND user_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('convId', $id);
        $stmt->bindValue('userId', $userId);
        $stmt->executeStatement();
        
        // Add system message
        $sql = "INSERT INTO message (conversation_id, sender_id, content, message_type, created_at) 
                VALUES (:convId, :senderId, :content, 'TEXT', NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('convId', $id);
        $stmt->bindValue('senderId', $this->getCurrentUserId());
        $stmt->bindValue('content', '👤 A participant was removed from the conversation');
        $stmt->executeStatement();
        
        $this->addFlash('success', 'Participant removed successfully!');
        return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
}

#[Route('/conversation/{id}/leave', name: 'messaging_conversation_leave', methods: ['POST'])]
public function leaveConversation(int $id, EntityManagerInterface $em): Response
{
    $conn = $em->getConnection();
    
    // Check if user is CREATOR (creator cannot leave, must delete)
    $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $this->getCurrentUserId());
    $result = $stmt->executeQuery();
    $userRole = $result->fetchOne();
    
    if ($userRole === 'CREATOR') {
        $this->addFlash('error', 'Creator cannot leave. Delete the conversation instead.');
        return $this->redirectToRoute('app_messaging');
    }
    
    // Soft delete user from conversation
    $sql = "UPDATE conversation_user SET is_active = 0 WHERE conversation_id = :convId AND user_id = :userId";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $this->getCurrentUserId());
    $stmt->executeStatement();
    
    $this->addFlash('success', 'You left the conversation');
    return $this->redirectToRoute('app_messaging');
}

#[Route('/conversation/{id}/promote/{userId}', name: 'messaging_conversation_promote', methods: ['POST'])]
public function promoteToAdmin(int $id, string $userId, EntityManagerInterface $em): Response
{
    $conn = $em->getConnection();
    
    // Check if current user is CREATOR
    $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $this->getCurrentUserId());
    $result = $stmt->executeQuery();
    $userRole = $result->fetchOne();
    
    if ($userRole !== 'CREATOR') {
        $this->addFlash('error', 'Only CREATOR can promote to ADMIN');
        return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
    }
    
    // Promote user to ADMIN
    $sql = "UPDATE conversation_user SET role = 'ADMIN' WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $userId);
    $stmt->executeStatement();
    
    $this->addFlash('success', 'User promoted to ADMIN');
    return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
}

#[Route('/conversation/{id}/demote/{userId}', name: 'messaging_conversation_demote', methods: ['POST'])]
public function demoteToMember(int $id, string $userId, EntityManagerInterface $em): Response
{
    $conn = $em->getConnection();
    
    // Check if current user is CREATOR or ADMIN
    $sql = "SELECT role FROM conversation_user WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $this->getCurrentUserId());
    $result = $stmt->executeQuery();
    $userRole = $result->fetchOne();
    
    if (!in_array($userRole, ['CREATOR', 'ADMIN'])) {
        $this->addFlash('error', 'Only CREATOR and ADMIN can demote');
        return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
    }
    
    // Demote user to MEMBER
    $sql = "UPDATE conversation_user SET role = 'MEMBER' WHERE conversation_id = :convId AND user_id = :userId AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('convId', $id);
    $stmt->bindValue('userId', $userId);
    $stmt->executeStatement();
    
    $this->addFlash('success', 'User demoted to MEMBER');
    return $this->redirectToRoute('messaging_conversation_participants', ['id' => $id]);
}

#[Route('/admin', name: 'messaging_admin_menu')]
public function adminMenu(EntityManagerInterface $em): Response
{
    $conn = $em->getConnection();

    // Get quick stats for the menu page
    $totalMessages = $conn->executeQuery("SELECT COUNT(*) FROM message")->fetchOne();
    $totalUsers = $conn->executeQuery("SELECT COUNT(*) FROM users")->fetchOne();
    $totalConversations = $conn->executeQuery("SELECT COUNT(*) FROM conversation")->fetchOne();

    return $this->render('messaging/admin_menu.html.twig', [
        'totalMessages' => $totalMessages,
        'totalUsers' => $totalUsers,
        'totalConversations' => $totalConversations,
    ]);
}

#[Route('/message/forward', name: 'messaging_message_forward', methods: ['POST'])]
public function forwardMessage(Request $request, EntityManagerInterface $em): Response
{
    $messageId = (int) $request->request->get('message_id');
    $targetConversationId = (int) $request->request->get('target_conversation_id');
    $currentUserId = $this->getCurrentUserId();

    $conn = $em->getConnection();

    // Get original message
    $sql = "SELECT * FROM message WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $messageId);
    $result = $stmt->executeQuery();
    $originalMessage = $result->fetchAssociative();

    if (!$originalMessage) {
        $this->addFlash('error', 'Message not found');
        return $this->redirectToRoute('app_messaging');
    }

    // Create forwarded message
    $forwardedContent = $originalMessage['content'];

    $message = new Message();
    $message->setConversationId($targetConversationId);
    $message->setSenderId($currentUserId);
    $message->setContent($forwardedContent);
    $message->setMessageType($originalMessage['message_type']);
    $message->setFileUrl($originalMessage['file_url']);
    $message->setFileName($originalMessage['file_name']);
    $message->setFileSize($originalMessage['file_size']);
    $message->setCreatedAt(new \DateTime());

    $em->persist($message);
    $em->flush();

    // Update conversation last activity
    $sql = "UPDATE conversation SET last_activity = NOW() WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $targetConversationId);
    $stmt->executeStatement();

    $this->addFlash('success', 'Message forwarded!');
    return $this->redirectToRoute('messaging_conversation_show', ['id' => $targetConversationId]);
}

#[Route('/message/reply', name: 'messaging_message_reply', methods: ['POST'])]
public function replyMessage(Request $request, EntityManagerInterface $em): Response
{
    $conversationId = (int) $request->request->get('conversation_id');
    $replyToId = (int) $request->request->get('reply_to_id');
    $content = trim($request->request->get('content'));
    $currentUserId = $this->getCurrentUserId();
    
    if (empty($content)) {
        $this->addFlash('error', 'Reply cannot be empty');
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }
    
    // Get original message with sender info
    $conn = $em->getConnection();
    $sql = "
        SELECT m.*, u.full_name as sender_name 
        FROM message m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE m.id = :id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $replyToId);
    $result = $stmt->executeQuery();
    $original = $result->fetchAssociative();
    
    if (!$original) {
        $this->addFlash('error', 'Original message not found');
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }
    
    // Format the quoted message
    $senderName = $original['sender_name'] ?: 'User ' . substr($original['sender_id'], 0, 8);
    $originalContent = $original['content'];
    
    // If replying to a reply, extract just the actual message (not the entire reply chain)
    if (strpos($originalContent, '**Replying to') === 0) {
        // Split on \n\n to get just the reply text, not the header and quote
        $parts = explode("\n\n", $originalContent, 2);
        $originalContent = isset($parts[1]) ? $parts[1] : $originalContent;
    }
    
    // Truncate long quoted messages
    if (strlen($originalContent) > 150) {
        $originalContent = substr($originalContent, 0, 150) . '...';
    }
    
    // Build the reply with quote block
    $replyContent = "**Replying to " . $senderName . ":**\n> " . $originalContent . "\n\n" . $content;
    
    $message = new Message();
    $message->setConversationId($conversationId);
    $message->setSenderId($currentUserId);
    $message->setContent($replyContent);
    $message->setMessageType('TEXT');
    $message->setCreatedAt(new \DateTime());
    
    $em->persist($message);
    $em->flush();
    
    // Update conversation last activity
    $sql = "UPDATE conversation SET last_activity = NOW() WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $conversationId);
    $stmt->executeStatement();
    
    $this->addFlash('success', 'Reply sent!');
    return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
}

#[Route('/conversation/{id}/export', name: 'messaging_export')]
public function exportConversation(int $id, EntityManagerInterface $em): Response
{
    $currentUserId = $this->getCurrentUserId();
    $conn = $em->getConnection();

    // Get conversation details
    $sql = "SELECT * FROM conversation WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $id);
    $result = $stmt->executeQuery();
    $conversation = $result->fetchAssociative();

    if (!$conversation) {
        throw $this->createNotFoundException('Conversation not found');
    }

    // Get all messages
    $sql = "
        SELECT m.*, u.full_name as sender_name
        FROM message m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = :id
        ORDER BY m.created_at ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('id', $id);
    $result = $stmt->executeQuery();
    $messages = $result->fetchAllAssociative();

    // Build export content
    $export = [];
    $export[] = "WanderLust Chat Export";
    $export[] = "=====================";
    $export[] = "Conversation: " . ($conversation['name'] ?: 'Conversation #' . $id);
    $export[] = "Type: " . $conversation['type'];
    $export[] = "Exported: " . date('Y-m-d H:i:s');
    $export[] = "";
    $export[] = "MESSAGES";
    $export[] = "========";
    $export[] = "";

    foreach ($messages as $msg) {
        $date = (new \DateTime($msg['created_at']))->format('Y-m-d H:i:s');
        $sender = $msg['sender_name'] ?: 'User ' . substr($msg['sender_id'], 0, 8);

        if ($msg['message_type'] == 'IMAGE') {
            $content = "[IMAGE] " . ($msg['file_name'] ?: 'Image');
        } elseif ($msg['message_type'] == 'VIDEO') {
            $content = "[VIDEO] " . ($msg['file_name'] ?: 'Video');
        } elseif ($msg['message_type'] == 'AUDIO') {
            $content = "[AUDIO] " . ($msg['file_name'] ?: 'Audio');
        } elseif ($msg['message_type'] == 'FILE') {
            $content = "[FILE] " . ($msg['file_name'] ?: 'File');
        } else {
            $content = $msg['content'];
        }

        $export[] = "[$date] $sender: $content";
    }

    $export[] = "";
    $export[] = "=====================";
    $export[] = "End of conversation";

    $content = implode("\n", $export);

    // Create response as downloadable file
    $response = new Response($content);
    $response->headers->set('Content-Type', 'text/plain');
    $response->headers->set('Content-Disposition', 'attachment; filename="conversation_' . $id . '_' . date('Y-m-d') . '.txt"');

    return $response;
}

    #[Route('/message/{id}/react', name: 'messaging_message_react', methods: ['POST'])]
    public function addReaction(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $reaction = $request->request->get('reaction');
        $userId = $this->getCurrentUserId();
        $conn = $em->getConnection();
        
        // Check if user already reacted to this message
        $sql = "SELECT id, reaction FROM message_reactions WHERE message_id = :messageId AND user_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('messageId', $id);
        $stmt->bindValue('userId', $userId);
        $result = $stmt->executeQuery();
        $existing = $result->fetchAssociative();
        
        if ($existing) {
            if ($existing['reaction'] === $reaction) {
                // Same reaction - remove it (toggle off)
                $sql = "DELETE FROM message_reactions WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('id', $existing['id']);
                $stmt->executeStatement();
            } else {
                // Different reaction - update
                $sql = "UPDATE message_reactions SET reaction = :reaction WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('reaction', $reaction);
                $stmt->bindValue('id', $existing['id']);
                $stmt->executeStatement();
            }
        } else {
            // New reaction
            $sql = "INSERT INTO message_reactions (message_id, user_id, reaction, created_at) VALUES (:messageId, :userId, :reaction, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('messageId', $id);
            $stmt->bindValue('userId', $userId);
            $stmt->bindValue('reaction', $reaction);
            $stmt->executeStatement();
        }
        
        // Return JSON response instead of redirect
        return new JsonResponse(['success' => true]);
    }

    #[Route('/message/{id}/reactions', name: 'messaging_get_reactions', methods: ['GET'])]
    public function getReactions(int $id, EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        
        $sql = "
            SELECT r.reaction, COUNT(*) as count, GROUP_CONCAT(u.full_name SEPARATOR ', ') as users
            FROM message_reactions r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.message_id = :messageId
            GROUP BY r.reaction
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('messageId', $id);
        $result = $stmt->executeQuery();
        $reactions = $result->fetchAllAssociative();
        
        // Also get current user's reaction
        $userId = $this->getCurrentUserId();
        $sql = "SELECT reaction FROM message_reactions WHERE message_id = :messageId AND user_id = :userId LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('messageId', $id);
        $stmt->bindValue('userId', $userId);
        $result = $stmt->executeQuery();
        $userReactionRow = $result->fetchAssociative();
        $userReaction = $userReactionRow ? $userReactionRow['reaction'] : null;
        
        return $this->json([
            'reactions' => $reactions,
            'userReaction' => $userReaction
        ]);
    }

}