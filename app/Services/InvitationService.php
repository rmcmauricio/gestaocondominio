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
     * Create invitation (with or without email)
     */
    public function createInvitation(int $condominiumId, ?int $fractionId, ?string $email, string $name, string $role = 'condomino', array $contactData = []): ?int
    {
        global $db;
        
        if (!$db) {
            error_log("InvitationService::createInvitation - Database connection not available");
            return null;
        }

        // If email is provided, check if user already exists
        if (!empty($email)) {
            try {
                $user = $this->userModel->findByEmail($email);
                
                if ($user) {
                    // User exists, just associate with fraction (no invitation needed)
                    $result = $this->associateExistingUser($condominiumId, $fractionId, $user['id'], $role, $contactData);
                    return $result ? -1 : null; // Return -1 to indicate user was associated directly
                }
            } catch (\Exception $e) {
                error_log("InvitationService::createInvitation - Error checking user existence: " . $e->getMessage());
                return null;
            }
        }

        // Generate invitation token (only if email is provided, otherwise null)
        $token = !empty($email) ? Security::generateToken(32) : null;
        $expiresAt = !empty($email) ? date('Y-m-d H:i:s', strtotime('+7 days')) : null;

        // Store invitation
        $stmt = $db->prepare("
            INSERT INTO invitations (
                condominium_id, fraction_id, email, name, role, nif, phone, alternative_address, token, expires_at, created_by
            )
            VALUES (
                :condominium_id, :fraction_id, :email, :name, :role, :nif, :phone, :alternative_address, :token, :expires_at, :created_by
            )
        ");

        $userId = $_SESSION['user']['id'] ?? null;
        
        try {
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':fraction_id' => $fractionId ?? null,
                ':email' => !empty($email) ? $email : null,
                ':name' => $name,
                ':role' => $role,
                ':nif' => !empty($contactData['nif'] ?? null) ? Security::sanitize($contactData['nif']) : null,
                ':phone' => !empty($contactData['phone'] ?? null) ? Security::sanitize($contactData['phone']) : null,
                ':alternative_address' => !empty($contactData['alternative_address'] ?? null) ? Security::sanitize($contactData['alternative_address']) : null,
                ':token' => $token,
                ':expires_at' => $expiresAt,
                ':created_by' => $userId
            ]);

            $invitationId = $db->lastInsertId();

            // If email is provided, send invitation email
            if (!empty($email) && !empty($token)) {
                $this->sendInvitationEmail($email, $name, $token);
            }

            return (int)$invitationId;
        } catch (\Exception $e) {
            error_log("Invitation error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Send invitation to condomino (legacy method - now calls createInvitation)
     */
    public function sendInvitation(int $condominiumId, ?int $fractionId, string $email, string $name, string $role = 'condomino'): bool
    {
        $result = $this->createInvitation($condominiumId, $fractionId, $email, $name, $role);
        return $result !== null;
    }

    /**
     * Send invitation email
     */
    protected function sendInvitationEmail(string $email, string $name, string $token): bool
    {
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
            // In development mode, if email fails (e.g., DEV_EMAIL not set), 
            // still return true because invitation is saved and valid
            if ($isDevelopment) {
                return true; // Invitation is saved, so it's successful even if email failed
            }
            
            // In production, email failure is more critical
            error_log("Invitation warning: Failed to send email to {$email}, but invitation was saved with token: {$token}");
            return false;
        }
        
        return true;
    }

    /**
     * Update invitation email and send invitation
     */
    public function updateInvitationEmailAndSend(int $invitationId, int $condominiumId, string $email): bool
    {
        global $db;
        
        if (!$db) {
            error_log("InvitationService::updateInvitationEmailAndSend - Database connection not available");
            return false;
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Check if user already exists with this email
        try {
            $user = $this->userModel->findByEmail($email);
            
            if ($user) {
                // User exists, get invitation details and associate
                $stmt = $db->prepare("
                    SELECT fraction_id, name, role, nif, phone, alternative_address FROM invitations 
                    WHERE id = :invitation_id 
                    AND condominium_id = :condominium_id
                    AND accepted_at IS NULL
                    LIMIT 1
                ");
                $stmt->execute([
                    ':invitation_id' => $invitationId,
                    ':condominium_id' => $condominiumId
                ]);
                $invitation = $stmt->fetch();
                
                if ($invitation) {
                    // Associate user and mark invitation as accepted
                    // Use user's NIF/phone if available, otherwise use invitation data
                    // User data prevails: if user has NIF and invitation doesn't or is different, use user NIF
                    $invitationNif = $invitation['nif'] ?? null;
                    $userNif = $user['nif'] ?? null;
                    $finalNif = null;
                    
                    // If user has NIF and (invitation doesn't have NIF or it's different), use user NIF
                    if (!empty($userNif)) {
                        if (empty($invitationNif) || $invitationNif !== $userNif) {
                            $finalNif = $userNif;
                        } else {
                            $finalNif = $invitationNif; // Keep invitation NIF if it matches
                        }
                    } else {
                        $finalNif = $invitationNif; // Use invitation NIF if user doesn't have one
                    }
                    
                    // Phone: prefer user phone, fallback to invitation phone
                    $finalPhone = !empty($user['phone']) ? $user['phone'] : ($invitation['phone'] ?? null);
                    
                    $result = $this->associateExistingUser(
                        $condominiumId,
                        $invitation['fraction_id'],
                        $user['id'],
                        $invitation['role'],
                        [
                            'nif' => $finalNif,
                            'phone' => $finalPhone,
                            'alternative_address' => $invitation['alternative_address'] ?? null
                        ]
                    );
                    if ($result) {
                        $this->markInvitationAsAcceptedById($invitationId);
                        return true;
                    }
                }
                return false;
            }
        } catch (\Exception $e) {
            error_log("InvitationService::updateInvitationEmailAndSend - Error checking user existence: " . $e->getMessage());
            return false;
        }

        // Get invitation details
        $stmt = $db->prepare("
            SELECT name, role, token, nif, phone, alternative_address FROM invitations 
            WHERE id = :invitation_id 
            AND condominium_id = :condominium_id
            AND accepted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':invitation_id' => $invitationId,
            ':condominium_id' => $condominiumId
        ]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return false;
        }

        // Generate new token if invitation doesn't have one or expired
        $token = $invitation['token'];
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        if (empty($token)) {
            $token = Security::generateToken(32);
        }

        // Update invitation with email and token (preserve existing contact data)
        $updateStmt = $db->prepare("
            UPDATE invitations 
            SET email = :email, 
                token = :token, 
                expires_at = :expires_at
            WHERE id = :invitation_id
            AND condominium_id = :condominium_id
        ");
        
        try {
            $updateStmt->execute([
                ':email' => $email,
                ':token' => $token,
                ':expires_at' => $expiresAt,
                ':invitation_id' => $invitationId,
                ':condominium_id' => $condominiumId
            ]);

            // Send invitation email
            return $this->sendInvitationEmail($email, $invitation['name'], $token);
        } catch (\Exception $e) {
            error_log("Invitation update error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resend invitation email
     */
    public function resendInvitation(int $invitationId, int $condominiumId): bool
    {
        global $db;
        
        if (!$db) {
            error_log("InvitationService::resendInvitation - Database connection not available");
            return false;
        }

        // Get invitation details
        $stmt = $db->prepare("
            SELECT email, name, token FROM invitations 
            WHERE id = :invitation_id 
            AND condominium_id = :condominium_id
            AND accepted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':invitation_id' => $invitationId,
            ':condominium_id' => $condominiumId
        ]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return false;
        }

        // Check if invitation has email
        if (empty($invitation['email'])) {
            return false; // Cannot resend without email
        }

        // Generate new token and extend expiration
        $token = Security::generateToken(32);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        // Update token and expiration
        $updateStmt = $db->prepare("
            UPDATE invitations 
            SET token = :token, 
                expires_at = :expires_at
            WHERE id = :invitation_id
        ");
        
        try {
            $updateStmt->execute([
                ':token' => $token,
                ':expires_at' => $expiresAt,
                ':invitation_id' => $invitationId
            ]);

            // Send invitation email
            return $this->sendInvitationEmail($invitation['email'], $invitation['name'], $token);
        } catch (\Exception $e) {
            error_log("Invitation resend error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update invitation details (name, email, role)
     */
    public function updateInvitation(int $invitationId, int $condominiumId, array $data): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        // Verify invitation belongs to condominium and is not accepted
        $stmt = $db->prepare("
            SELECT id, email, token FROM invitations 
            WHERE id = :invitation_id 
            AND condominium_id = :condominium_id
            AND accepted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':invitation_id' => $invitationId,
            ':condominium_id' => $condominiumId
        ]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return false;
        }

        $name = Security::sanitize($data['name'] ?? '');
        $email = !empty($data['email']) ? Security::sanitize($data['email']) : null;
        $role = Security::sanitize($data['role'] ?? 'condomino');
        $nif = !empty($data['nif']) ? Security::sanitize($data['nif']) : null;
        $phone = !empty($data['phone']) ? Security::sanitize($data['phone']) : null;
        $alternativeAddress = !empty($data['alternative_address']) ? Security::sanitize($data['alternative_address']) : null;

        if (empty($name)) {
            return false;
        }

        // If email is being added or changed, generate token and expiration
        $token = $invitation['token'] ?? null;
        $expiresAt = null;
        
        if (!empty($email)) {
            // If email is new or changed, generate new token
            if (empty($invitation['email']) || $invitation['email'] !== $email) {
                $token = Security::generateToken(32);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
            } else {
                // Email unchanged, keep existing token and expiration
                $stmt = $db->prepare("SELECT expires_at FROM invitations WHERE id = :invitation_id");
                $stmt->execute([':invitation_id' => $invitationId]);
                $existing = $stmt->fetch();
                $expiresAt = $existing['expires_at'] ?? null;
            }
        }

        // Ensure we always have a token (e.g. legacy rows or missing column)
        if (empty($token)) {
            $token = Security::generateToken(32);
            if ($expiresAt === null && !empty($email)) {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
            }
        }

        // Update invitation
        $updateStmt = $db->prepare("
            UPDATE invitations 
            SET name = :name,
                email = :email,
                role = :role,
                nif = :nif,
                phone = :phone,
                alternative_address = :alternative_address,
                token = :token,
                expires_at = :expires_at
            WHERE id = :invitation_id
        ");
        
        try {
            $updateStmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':nif' => $nif,
                ':phone' => $phone,
                ':alternative_address' => $alternativeAddress,
                ':token' => $token,
                ':expires_at' => $expiresAt,
                ':invitation_id' => $invitationId
            ]);

            // If email was added/changed and is valid, send invitation email
            if (!empty($email) && !empty($token) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Check if user already exists
                $user = $this->userModel->findByEmail($email);
                if (!$user) {
                    // Only send email if user doesn't exist
                    $this->sendInvitationEmail($email, $name, $token);
                }
            }

            return true;
        } catch (\Exception $e) {
            error_log("Invitation update error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark invitation as accepted by ID
     */
    protected function markInvitationAsAcceptedById(int $invitationId): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("
            UPDATE invitations 
            SET accepted_at = NOW() 
            WHERE id = :invitation_id
        ");

        return $stmt->execute([':invitation_id' => $invitationId]);
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

        // Check if invitation has email
        if (empty($invitation['email'])) {
            return null; // Cannot accept invitation without email
        }

        // Check if user already exists
        $user = $this->userModel->findByEmail($invitation['email']);
        
        if ($user) {
            // User exists, just associate
            // Use user's NIF/phone if available, otherwise use invitation data
            // User data prevails: if user has NIF and invitation doesn't or is different, use user NIF
            $invitationNif = $invitation['nif'] ?? null;
            $userNif = $user['nif'] ?? null;
            $finalNif = null;
            
            // If user has NIF and (invitation doesn't have NIF or it's different), use user NIF
            if (!empty($userNif)) {
                if (empty($invitationNif) || $invitationNif !== $userNif) {
                    $finalNif = $userNif;
                } else {
                    $finalNif = $invitationNif; // Keep invitation NIF if it matches
                }
            } else {
                $finalNif = $invitationNif; // Use invitation NIF if user doesn't have one
            }
            
            // Phone: prefer user phone, fallback to invitation phone
            $finalPhone = !empty($user['phone']) ? $user['phone'] : ($invitation['phone'] ?? null);
            
            $this->associateExistingUser(
                $invitation['condominium_id'],
                $invitation['fraction_id'],
                $user['id'],
                $invitation['role'],
                [
                    'nif' => $finalNif,
                    'phone' => $finalPhone,
                    'alternative_address' => $invitation['alternative_address'] ?? null
                ]
            );
            $this->markInvitationAsAccepted($token);
            return $user['id'];
        }

        // Create new user (with NIF and phone from invitation if provided)
        $createData = [
            'email' => $invitation['email'],
            'password' => $password,
            'name' => $invitation['name'],
            'role' => 'condomino',
            'status' => 'active'
        ];
        
        // Add NIF and phone to user creation if available in invitation
        if (!empty($invitation['nif'])) {
            $createData['nif'] = $invitation['nif'];
        }
        if (!empty($invitation['phone'])) {
            $createData['phone'] = $invitation['phone'];
        }
        
        $userId = $this->userModel->create($createData);

        if ($userId) {
            // Associate with condominium and fraction
            // Use invitation data (which was already copied to user profile)
            $userData = $this->userModel->findById($userId);
            $finalNif = $userData['nif'] ?? null;
            $finalPhone = $userData['phone'] ?? null;
            
            $this->condominiumUserModel->associate([
                'condominium_id' => $invitation['condominium_id'],
                'user_id' => $userId,
                'fraction_id' => $invitation['fraction_id'],
                'role' => $invitation['role'],
                'is_primary' => true,
                'nif' => $finalNif,
                'phone' => $finalPhone,
                'alternative_address' => $invitation['alternative_address'] ?? null
            ]);

            // Mark invitation as accepted
            $this->markInvitationAsAccepted($token);
        }

        return $userId;
    }

    /**
     * Associate existing user with fraction
     */
    protected function associateExistingUser(int $condominiumId, ?int $fractionId, int $userId, string $role, array $contactData = []): bool
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
                    // Association already exists, update it instead (including contact data)
                    $updateStmt = $db->prepare("
                        UPDATE condominium_users 
                        SET condominium_id = :condominium_id,
                            role = :role,
                            nif = :nif,
                            phone = :phone,
                            alternative_address = :alternative_address,
                            ended_at = NULL
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ':condominium_id' => $condominiumId,
                        ':role' => $role,
                        ':nif' => !empty($contactData['nif']) ? Security::sanitize($contactData['nif']) : null,
                        ':phone' => !empty($contactData['phone']) ? Security::sanitize($contactData['phone']) : null,
                        ':alternative_address' => !empty($contactData['alternative_address']) ? Security::sanitize($contactData['alternative_address']) : null,
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
                    // Association already exists, update it instead (including contact data)
                    $updateStmt = $db->prepare("
                        UPDATE condominium_users 
                        SET role = :role,
                            nif = :nif,
                            phone = :phone,
                            alternative_address = :alternative_address,
                            ended_at = NULL
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ':role' => $role,
                        ':nif' => !empty($contactData['nif']) ? Security::sanitize($contactData['nif']) : null,
                        ':phone' => !empty($contactData['phone']) ? Security::sanitize($contactData['phone']) : null,
                        ':alternative_address' => !empty($contactData['alternative_address']) ? Security::sanitize($contactData['alternative_address']) : null,
                        ':id' => $existing['id']
                    ]);
                    return true;
                }
            }
            
            // Create new association
            $associationData = [
                'condominium_id' => $condominiumId,
                'user_id' => $userId,
                'fraction_id' => $fractionId ?? null,
                'role' => $role,
                'is_primary' => false
            ];
            
            // Add contact data if provided
            if (!empty($contactData['nif'])) {
                $associationData['nif'] = $contactData['nif'];
            }
            if (!empty($contactData['phone'])) {
                $associationData['phone'] = $contactData['phone'];
            }
            if (!empty($contactData['alternative_address'])) {
                $associationData['alternative_address'] = $contactData['alternative_address'];
            }
            
            $this->condominiumUserModel->associate($associationData);
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
            AND (expires_at IS NULL OR expires_at > NOW())
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





