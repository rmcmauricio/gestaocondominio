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
        
        error_log("InvitationService::sendInvitation called: condominiumId={$condominiumId}, fractionId=" . ($fractionId ?? 'null') . ", email={$email}, name={$name}, role={$role}");
        
        if (!$db) {
            error_log("InvitationService::sendInvitation - Database connection not available");
            return false;
        }

        // Check if user already exists
        try {
            $user = $this->userModel->findByEmail($email);
            
            if ($user) {
                error_log("InvitationService::sendInvitation - User already exists with ID: {$user['id']}, associating with fraction");
                // User exists, just associate with fraction
                $result = $this->associateExistingUser($condominiumId, $fractionId, $user['id'], $role);
                error_log("InvitationService::sendInvitation - Association result: " . ($result ? 'SUCCESS' : 'FAILED'));
                return $result;
            }
        } catch (\Exception $e) {
            error_log("InvitationService::sendInvitation - Error checking user existence: " . $e->getMessage());
            return false;
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

            // Invitation saved successfully
            error_log("Invitation saved successfully: email={$email}, token={$token}, fraction_id=" . ($fractionId ?? 'null'));

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

            // Try to send email
            $emailSent = $this->emailService->sendEmail($email, $subject, $html, $text);
            
            // Get environment to check if we're in development
            $appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'development');
            $isDevelopment = (strtolower($appEnv) === 'development');
            
            if (!$emailSent) {
                error_log("Invitation warning: Failed to send email to {$email}, but invitation was saved with token: {$token}");
                
                // In development mode, if email fails (e.g., DEV_EMAIL not set), 
                // still return true because invitation is saved and valid
                if ($isDevelopment) {
                    error_log("Invitation: In development mode, invitation saved successfully. Email not sent but invitation is valid.");
                    return true; // Invitation is saved, so it's successful even if email failed
                }
                
                // In production, email failure is more critical
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("Invitation error: " . $e->getMessage());
            error_log("Invitation error trace: " . $e->getTraceAsString());
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
        global $db;
        
        if (!$db) {
            error_log("Association error: Database connection not available");
            return false;
        }
        
        try {
            // Check if association already exists based on UNIQUE constraint (user_id, fraction_id)
            // Note: The constraint is only on (user_id, fraction_id), not including condominium_id
            if ($fractionId !== null) {
                $checkStmt = $db->prepare("
                    SELECT id, condominium_id, ended_at FROM condominium_users 
                    WHERE user_id = :user_id 
                    AND fraction_id = :fraction_id
                    LIMIT 1
                ");
                $checkStmt->execute([
                    ':user_id' => $userId,
                    ':fraction_id' => $fractionId
                ]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // Association already exists, update it instead
                    error_log("Association already exists, updating: user_id={$userId}, fraction_id={$fractionId}, existing_id={$existing['id']}");
                    $updateStmt = $db->prepare("
                        UPDATE condominium_users 
                        SET condominium_id = :condominium_id,
                            role = :role,
                            ended_at = NULL
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ':condominium_id' => $condominiumId,
                        ':role' => $role,
                        ':id' => $existing['id']
                    ]);
                    return true;
                }
            } else {
                // For null fraction_id, check if user is already associated with this condominium without fraction
                $checkStmt = $db->prepare("
                    SELECT id FROM condominium_users 
                    WHERE user_id = :user_id 
                    AND condominium_id = :condominium_id
                    AND fraction_id IS NULL
                    AND (ended_at IS NULL OR ended_at > CURDATE())
                    LIMIT 1
                ");
                $checkStmt->execute([
                    ':user_id' => $userId,
                    ':condominium_id' => $condominiumId
                ]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // Association already exists, update it instead
                    error_log("Association already exists (no fraction), updating: user_id={$userId}, condominium_id={$condominiumId}, existing_id={$existing['id']}");
                    $updateStmt = $db->prepare("
                        UPDATE condominium_users 
                        SET role = :role,
                            ended_at = NULL
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ':role' => $role,
                        ':id' => $existing['id']
                    ]);
                    return true;
                }
            }
            
            // Create new association
            $this->condominiumUserModel->associate([
                'condominium_id' => $condominiumId,
                'user_id' => $userId,
                'fraction_id' => $fractionId ?? null,
                'role' => $role,
                'is_primary' => false
            ]);
            error_log("Association created successfully: user_id={$userId}, condominium_id={$condominiumId}, fraction_id=" . ($fractionId ?? 'null'));
            return true;
        } catch (\Exception $e) {
            error_log("Association error: " . $e->getMessage());
            error_log("Association error trace: " . $e->getTraceAsString());
            error_log("Association data: condominium_id={$condominiumId}, fraction_id=" . ($fractionId ?? 'null') . ", user_id={$userId}, role={$role}");
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





