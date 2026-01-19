<?php

namespace App\Services;

use App\Core\EmailService;
use App\Core\Security;
use App\Models\User;
use App\Models\CondominiumUser;

class InvitationService
{
    protected $userModel;
    protected $condominiumUserModel;
    protected $emailService;

    public function __construct()
    {
        $this->userModel = new User();
        $this->condominiumUserModel = new CondominiumUser();
        $this->emailService = new EmailService();
    }

    /**
     * Send invitation to condomino
     */
    public function sendInvitation(int $condominiumId, ?int $fractionId, string $email, string $name, string $role = 'condomino'): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        // Check if user already exists
        $user = $this->userModel->findByEmail($email);
        
        if ($user) {
            // User exists, just associate with fraction
            return $this->associateExistingUser($condominiumId, $fractionId, $user['id'], $role);
        }

        // Generate invitation token
        $token = Security::generateToken(32);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        // Store invitation
        $stmt = $db->prepare("
            INSERT INTO invitations (
                condominium_id, fraction_id, email, name, role, token, expires_at, created_by
            )
            VALUES (
                :condominium_id, :fraction_id, :email, :name, :role, :token, :expires_at, :created_by
            )
        ");

        $userId = $_SESSION['user']['id'] ?? null;
        
        try {
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':fraction_id' => $fractionId ?? null,
                ':email' => $email,
                ':name' => $name,
                ':role' => $role,
                ':token' => $token,
                ':expires_at' => $expiresAt,
                ':created_by' => $userId
            ]);

            // Send invitation email
            $invitationLink = BASE_URL . 'invitation/accept?token=' . $token;
            
            $subject = 'Convite para Condomínio';
            $html = "
                <h2>Convite para Condomínio</h2>
                <p>Olá {$name},</p>
                <p>Foi convidado para fazer parte de um condomínio.</p>
                <p>Clique no link abaixo para criar a sua conta e aceitar o convite:</p>
                <p><a href='{$invitationLink}'>Aceitar Convite</a></p>
                <p>Este link expira em 7 dias.</p>
            ";
            $text = "Convite para Condomínio\n\nOlá {$name},\n\nFoi convidado para fazer parte de um condomínio.\n\nClique no link abaixo para criar a sua conta e aceitar o convite:\n{$invitationLink}\n\nEste link expira em 7 dias.";

            return $this->emailService->sendEmail($email, $subject, $html, $text);
        } catch (\Exception $e) {
            error_log("Invitation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Accept invitation and create user account
     */
    public function acceptInvitation(string $token, string $password): ?int
    {
        global $db;
        
        if (!$db) {
            return null;
        }

        // Find invitation
        $stmt = $db->prepare("
            SELECT * FROM invitations 
            WHERE token = :token 
            AND expires_at > NOW() 
            AND accepted_at IS NULL
            LIMIT 1
        ");

        $stmt->execute([':token' => $token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return null;
        }

        // Check if user already exists
        $user = $this->userModel->findByEmail($invitation['email']);
        
        if ($user) {
            // User exists, just associate
            $this->associateExistingUser(
                $invitation['condominium_id'],
                $invitation['fraction_id'],
                $user['id'],
                $invitation['role']
            );
            $this->markInvitationAsAccepted($token);
            return $user['id'];
        }

        // Create new user
        $userId = $this->userModel->create([
            'email' => $invitation['email'],
            'password' => $password,
            'name' => $invitation['name'],
            'role' => 'condomino',
            'status' => 'active'
        ]);

        if ($userId) {
            // Associate with condominium and fraction
            $this->condominiumUserModel->associate([
                'condominium_id' => $invitation['condominium_id'],
                'user_id' => $userId,
                'fraction_id' => $invitation['fraction_id'],
                'role' => $invitation['role'],
                'is_primary' => true
            ]);

            // Mark invitation as accepted
            $this->markInvitationAsAccepted($token);
        }

        return $userId;
    }

    /**
     * Associate existing user with fraction
     */
    protected function associateExistingUser(int $condominiumId, ?int $fractionId, int $userId, string $role): bool
    {
        try {
            $this->condominiumUserModel->associate([
                'condominium_id' => $condominiumId,
                'user_id' => $userId,
                'fraction_id' => $fractionId ?? null,
                'role' => $role,
                'is_primary' => false
            ]);
            return true;
        } catch (\Exception $e) {
            error_log("Association error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark invitation as accepted
     */
    protected function markInvitationAsAccepted(string $token): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("
            UPDATE invitations 
            SET accepted_at = NOW() 
            WHERE token = :token
        ");

        return $stmt->execute([':token' => $token]);
    }

    /**
     * Get pending invitations for condominium
     */
    public function getPendingInvitations(int $condominiumId): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT * FROM invitations 
            WHERE condominium_id = :condominium_id 
            AND accepted_at IS NULL 
            AND expires_at > NOW()
            ORDER BY created_at DESC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Revoke (delete) an invitation
     */
    public function revokeInvitation(int $invitationId, int $condominiumId): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        // Verify invitation belongs to condominium
        $stmt = $db->prepare("
            SELECT id FROM invitations 
            WHERE id = :invitation_id 
            AND condominium_id = :condominium_id
            AND accepted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':invitation_id' => $invitationId,
            ':condominium_id' => $condominiumId
        ]);
        
        if (!$stmt->fetch()) {
            return false; // Invitation not found or already accepted
        }

        // Delete invitation
        $deleteStmt = $db->prepare("DELETE FROM invitations WHERE id = :invitation_id");
        return $deleteStmt->execute([':invitation_id' => $invitationId]);
    }
}





