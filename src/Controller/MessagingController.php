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
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

#[Route('/', name: 'app_messaging')]
public function index(EntityManagerInterface $em): Response
{
    $currentUserId = $this->getCurrentUserId();
    $conn = $em->getConnection();
    
    // Get active conversations (not archived)
    $sql = "
        SELECT c.*, 
               (SELECT role FROM conversation_user WHERE conversation_id = c.id AND user_id = :userId) as user_role
        FROM conversation c
        JOIN conversation_user cu ON c.id = cu.conversation_id
        WHERE cu.user_id = :userId AND cu.is_active = 1 AND c.is_archived = 0
        ORDER BY c.is_pinned DESC, c.last_activity DESC, c.id DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('userId', $currentUserId);
    $result = $stmt->executeQuery();
    $conversations = $result->fetchAllAssociative();
    
    // Get archived conversations
    $sqlArchived = "
        SELECT c.*, 
               (SELECT role FROM conversation_user WHERE conversation_id = c.id AND user_id = :userId) as user_role
        FROM conversation c
        JOIN conversation_user cu ON c.id = cu.conversation_id
        WHERE cu.user_id = :userId AND cu.is_active = 1 AND c.is_archived = 1
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
            LEFT JOIN users u ON m.sender_id = u.user_id
            WHERE m.conversation_id = :conversationId
            ORDER BY m.created_at ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('conversationId', $id);
        $result = $stmt->executeQuery();
        $messages = $result->fetchAllAssociative();
        
        return $this->render('messaging/show.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    #[Route('/conversation/new', name: 'messaging_conversation_new', methods: ['GET', 'POST'])]
    public function newConversation(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name'));
            $type = $request->request->get('type');
            $participantEmails = $request->request->get('participant_emails', []);
            
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
            
            foreach ($participantEmails as $email) {
                $email = trim($email);
                if (empty($email)) continue;
                
                $sql = "SELECT user_id FROM users WHERE email = :email";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('email', $email);
                $result = $stmt->executeQuery();
                $participant = $result->fetchAssociative();
                
                if (!$participant) {
                    $this->addFlash('error', 'User not found with email: ' . $email);
                    return $this->redirectToRoute('app_messaging');
                }
                $participantIds[] = $participant['user_id'];
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
            $cu->setUserId($this->getCurrentUserId());
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
        $newName = trim($request->request->get('name'));
        
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
        $conversationId = $request->request->get('conversation_id');
        $content = $request->request->get('content');
        
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
    $newContent = trim($request->request->get('content'));
    
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
    $conversationId = $request->request->get('conversation_id');
    
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['file']['error'] ?? 'unknown';
        $this->addFlash('error', 'Upload failed. Error code: ' . $errorCode);
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }
    
    $tmpFile = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'];
    
    // Debug: Check if temp file exists
    if (!file_exists($tmpFile)) {
        $this->addFlash('error', 'Temp file does not exist: ' . $tmpFile);
        return $this->redirectToRoute('messaging_conversation_show', ['id' => $conversationId]);
    }
    
    // Generate unique filename
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $newName = time() . '_' . uniqid() . '.' . $extension;
    $destination = $this->uploadDir . $newName;
    
    // Use copy() instead of move_uploaded_file()
    if (!copy($tmpFile, $destination)) {
        $this->addFlash('error', 'Failed to copy file to destination');
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
    $message->setFileSize($_FILES['file']['size']);
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
    
    // Return the file as a response
    return $this->file($filePath, $filename);
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
            JOIN users u ON cu.user_id = u.user_id
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
    ]);
}

#[Route('/conversation/{id}/participant/add', name: 'messaging_conversation_add_participant', methods: ['POST'])]
public function addParticipant(int $id, Request $request, EntityManagerInterface $em): Response
{
    $email = trim($request->request->get('email'));
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
    $sql = "SELECT user_id FROM users WHERE email = :email";
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
        $stmt->bindValue('userId', $newUser['user_id']);
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
        $stmt->bindValue('userId', $newUser['user_id']);
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

#[Route('/admin/dashboard', name: 'messaging_admin_dashboard')]
public function adminDashboard(EntityManagerInterface $em): Response
{
    $conn = $em->getConnection();
    
    // Total users
    $sql = "SELECT COUNT(*) as total FROM users";
    $totalUsers = $conn->executeQuery($sql)->fetchOne();
    
    // Total conversations
    $sql = "SELECT COUNT(*) as total FROM conversation";
    $totalConversations = $conn->executeQuery($sql)->fetchOne();
    
    // Total messages
    $sql = "SELECT COUNT(*) as total FROM message";
    $totalMessages = $conn->executeQuery($sql)->fetchOne();
    
    // Total media messages (images, videos, audio, files)
    $sql = "SELECT COUNT(*) as total FROM message WHERE message_type != 'TEXT'";
    $totalMedia = $conn->executeQuery($sql)->fetchOne();
    
    // Messages per day (last 7 days)
    $sql = "
        SELECT DATE(created_at) as date, COUNT(*) as count 
        FROM message 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ";
    $messagesPerDay = $conn->executeQuery($sql)->fetchAllAssociative();
    
    // Most active conversations (top 5)
    $sql = "
        SELECT c.id, c.name, COUNT(m.id) as message_count
        FROM conversation c
        JOIN message m ON c.id = m.conversation_id
        GROUP BY c.id
        ORDER BY message_count DESC
        LIMIT 5
    ";
    $topConversations = $conn->executeQuery($sql)->fetchAllAssociative();
    
    // Top message senders
    $sql = "
        SELECT u.full_name, u.email, COUNT(m.id) as message_count
        FROM users u
        JOIN message m ON u.user_id = m.sender_id
        GROUP BY u.user_id
        ORDER BY message_count DESC
        LIMIT 5
    ";
    $topSenders = $conn->executeQuery($sql)->fetchAllAssociative();
    
    // Recent activity (last 10 messages)
    $sql = "
        SELECT m.*, u.full_name as sender_name, c.name as conversation_name
        FROM message m
        JOIN users u ON m.sender_id = u.user_id
        JOIN conversation c ON m.conversation_id = c.id
        ORDER BY m.created_at DESC
        LIMIT 10
    ";
    $recentActivity = $conn->executeQuery($sql)->fetchAllAssociative();
    
    // Group vs Personal stats
    $sql = "SELECT type, COUNT(*) as count FROM conversation GROUP BY type";
    $conversationTypes = $conn->executeQuery($sql)->fetchAllAssociative();
    
    // Message types distribution
    $sql = "SELECT message_type, COUNT(*) as count FROM message GROUP BY message_type";
    $messageTypes = $conn->executeQuery($sql)->fetchAllAssociative();
    
    return $this->render('messaging/admin_dashboard.html.twig', [
        'totalUsers' => $totalUsers,
        'totalConversations' => $totalConversations,
        'totalMessages' => $totalMessages,
        'totalMedia' => $totalMedia,
        'messagesPerDay' => $messagesPerDay,
        'topConversations' => $topConversations,
        'topSenders' => $topSenders,
        'recentActivity' => $recentActivity,
        'conversationTypes' => $conversationTypes,
        'messageTypes' => $messageTypes,
    ]);
}

}
