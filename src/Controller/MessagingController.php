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
    // Hardcoded current user (replace later with real auth)
    private string $CURRENT_USER_ID = '123e4567-e89b-12d3-a456-426614174000';
    

   private string $uploadDir;

public function __construct()
{
    // Get the project root directory
    $projectRoot = dirname(__DIR__, 2); // Goes up two levels from src/Controller
    $this->uploadDir = __DIR__ . '/../../public/uploads/';
    
    // Create upload directory if it doesn't exist
    if (!is_dir($this->uploadDir)) {
        mkdir($this->uploadDir, 0777, true);
    }
    
    error_log('Upload directory set to: ' . $this->uploadDir);
}

    #[Route('/', name: 'app_messaging')]
    public function index(EntityManagerInterface $em): Response
    {
        // Get user's conversations
        $conn = $em->getConnection();
        
        $sql = "
            SELECT c.*, 
                   (SELECT role FROM conversation_user WHERE conversation_id = c.id AND user_id = :userId) as user_role
            FROM conversation c
            JOIN conversation_user cu ON c.id = cu.conversation_id
            WHERE cu.user_id = :userId AND cu.is_active = 1 AND c.is_archived = 0
            ORDER BY c.is_pinned DESC, c.created_at DESC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('userId', $this->CURRENT_USER_ID);
        $result = $stmt->executeQuery();
        $conversations = $result->fetchAllAssociative();
        
        return $this->render('messaging/index.html.twig', [
            'conversations' => $conversations,
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
            $name = $request->request->get('name');
            $type = $request->request->get('type');
            $participantEmail = $request->request->get('participant_email');
            
            // Find participant by email
            $conn = $em->getConnection();
            $sql = "SELECT user_id FROM users WHERE email = :email";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('email', $participantEmail);
            $result = $stmt->executeQuery();
            $participant = $result->fetchAssociative();
            
            if (!$participant) {
                $this->addFlash('error', 'User not found with email: ' . $participantEmail);
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
            $cu->setUserId($this->CURRENT_USER_ID);
            $cu->setRole('CREATOR');
            $em->persist($cu);
            
            // Add participant as MEMBER
            $cu2 = new ConversationUser();
            $cu2->setConversationId($conversation->getId());
            $cu2->setUserId($participant['user_id']);
            $cu2->setRole('MEMBER');
            $em->persist($cu2);
            
            $em->flush();
            
            $this->addFlash('success', 'Conversation created successfully!');
            return $this->redirectToRoute('app_messaging');
        }
        
        return $this->render('messaging/new.html.twig');
    }

    #[Route('/conversation/{id}/rename', name: 'messaging_conversation_rename', methods: ['POST'])]
    public function renameConversation(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $newName = $request->request->get('name');
        
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
        $stmt->bindValue('userId', $this->CURRENT_USER_ID);
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
        $message->setSenderId($this->CURRENT_USER_ID);
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
        $newContent = $request->request->get('content');
        
        $conn = $em->getConnection();
        $sql = "UPDATE message SET content = :content, edited_at = NOW() WHERE id = :id AND sender_id = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('content', $newContent);
        $stmt->bindValue('id', $id);
        $stmt->bindValue('userId', $this->CURRENT_USER_ID);
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
        $stmt->bindValue('userId', $this->CURRENT_USER_ID);
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
    $message->setSenderId($this->CURRENT_USER_ID);
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
}