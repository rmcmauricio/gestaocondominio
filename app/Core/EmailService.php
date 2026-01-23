<?php

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load .env file
if (file_exists(__DIR__ . '/../../.env')) {
    $lines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

class EmailService
{
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $fromEmail;
    private $fromName;
    private $emailTranslations;

    public function __construct()
    {
        // Load email configuration from .env (loaded into $_ENV)
        $this->smtpHost = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $this->smtpPort = isset($_ENV['SMTP_PORT']) ? (int)$_ENV['SMTP_PORT'] : 587;
        $this->smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
        $this->smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';
        $this->fromEmail = $_ENV['FROM_EMAIL'] ?? 'noreply@example.com';
        $this->fromName = $_ENV['FROM_NAME'] ?? 'Meu Prédio';

        // Load email translations
        $this->loadEmailTranslations();
    }

    private function loadEmailTranslations(): void
    {
        // Try to get language from session, but default to Portuguese
        $lang = isset($_SESSION) && isset($_SESSION['lang']) ? $_SESSION['lang'] : 'pt';
        $emailFile = __DIR__ . "/../Metafiles/{$lang}/emails.json";

        if (file_exists($emailFile)) {
            $emailData = json_decode(file_get_contents($emailFile), true);
            if (isset($emailData['t']) && is_array($emailData['t'])) {
                $this->emailTranslations = $emailData['t'];
            }
        }

        // Fallback to Portuguese if translations not found or empty
        if (empty($this->emailTranslations)) {
            $fallbackFile = __DIR__ . "/../Metafiles/pt/emails.json";
            if (file_exists($fallbackFile)) {
                $fallbackData = json_decode(file_get_contents($fallbackFile), true);
                if (isset($fallbackData['t']) && is_array($fallbackData['t'])) {
                    $this->emailTranslations = $fallbackData['t'];
                }
            }
        }
        
        // If still empty, initialize as empty array to avoid errors
        if (empty($this->emailTranslations)) {
            $this->emailTranslations = [];
        }
    }

    private function t(string $key, array $replacements = []): string
    {
        // Get translation or use key as fallback
        $text = $this->emailTranslations[$key] ?? $key;
        
        // If translation is empty or same as key, try to provide a default
        if (empty($text) || $text === $key) {
            // Provide some default Portuguese translations for common keys
            $defaults = [
                'password_reset_title' => 'Redefinir Senha',
                'password_reset_subtitle' => 'Solicitação de redefinição de senha',
                'password_reset_hello' => 'Olá {nome}!',
                'password_reset_message' => 'Recebemos uma solicitação para redefinir a senha da sua conta.',
                'password_reset_button' => 'Redefinir Senha',
                'password_reset_important' => 'Importante',
                'password_reset_warning_1' => 'Este link expira em 1 hora',
                'password_reset_warning_2' => 'Se você não solicitou esta alteração, ignore este email',
                'password_reset_warning_3' => 'A sua senha não será alterada até que você clique no link acima',
                'password_reset_copy_link' => 'Se o botão não funcionar, copie e cole este link no seu navegador:',
                'welcome_footer_made_with' => 'Feito com ❤️ para gestão de condomínios',
                'welcome_footer_copyright' => '© O Meu Prédio - Todos os direitos reservados',
            ];
            
            if (isset($defaults[$key])) {
                $text = $defaults[$key];
            }
        }

        // Replace placeholders like {nome}
        foreach ($replacements as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', $value, $text);
        }

        return $text;
    }

    public function sendWelcomeEmail(string $email, string $nome, string $token): bool
    {
        $subject = $this->t('welcome_subject');
        $verificationUrl = BASE_URL . 'verify-email?token=' . $token;

        $html = $this->getWelcomeEmailTemplate($nome, $verificationUrl);
        $text = $this->getWelcomeEmailText($nome, $verificationUrl);

        return $this->sendEmailInternal($email, $subject, $html, $text);
    }

    public function sendApprovalNotification(string $email, string $nome): bool
    {
        $subject = $this->t('approval_subject');

        $html = $this->getApprovalEmailTemplate($nome);
        $text = $this->getApprovalEmailText($nome);

        return $this->sendEmailInternal($email, $subject, $html, $text);
    }

    public function sendNewUserNotification(string $userEmail, string $userName, int $userId): bool
    {
        $subject = $this->t('new_user_subject');
        $adminUrl = BASE_URL . 'dashboard/approvals';

        // Obter emails de suporte (suporta múltiplos emails separados por vírgula)
        $supportEmails = defined('SUPPORT_EMAILS') ? SUPPORT_EMAILS : (defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'suporte@lyricsjam.com');
        $emailList = array_map('trim', explode(',', $supportEmails));

        $html = $this->getNewUserNotificationTemplate($userEmail, $userName, $adminUrl);
        $text = $this->getNewUserNotificationText($userEmail, $userName, $adminUrl);

        // Enviar para todos os emails de suporte
        $allSent = true;
        foreach ($emailList as $email) {
            if (!empty($email)) {
                $sent = $this->sendEmail($email, $subject, $html, $text);
                if (!$sent) {
                    $allSent = false;
                    error_log("Falha ao enviar notificação para: " . $email);
                }
            }
        }

        return $allSent;
    }

    /**
     * Send generic email
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $html HTML content
     * @param string $text Plain text content
     * @param string|null $emailType Type of email ('notification' or 'message')
     * @param int|null $userId User ID for preference checking and demo protection
     * @return bool
     */
    public function sendEmail(string $to, string $subject, string $html, string $text = '', ?string $emailType = null, ?int $userId = null): bool
    {
        return $this->sendEmailInternal($to, $subject, $html, $text, $emailType, $userId);
    }

    private function sendEmailInternal(string $to, string $subject, string $html, string $text, ?string $emailType = null, ?int $userId = null): bool
    {
        // Check if user is demo - demo users never receive emails
        if ($userId !== null) {
            if (\App\Middleware\DemoProtectionMiddleware::isDemoUser($userId)) {
                error_log("EmailService: Skipping email to demo user (ID: {$userId})");
                return false;
            }

            // Check user preferences if email type is provided
            if ($emailType !== null && in_array($emailType, ['notification', 'message'])) {
                $preferenceModel = new \App\Models\UserEmailPreference();
                if (!$preferenceModel->hasEmailEnabled($userId, $emailType)) {
                    error_log("EmailService: Email disabled by user preference (User ID: {$userId}, Type: {$emailType})");
                    return false;
                }
            }
        }

        // Store original recipient for DEV redirection
        $originalTo = $to;
        
        // Get APP_ENV from multiple sources
        $appEnv = null;
        if (defined('APP_ENV')) {
            $appEnv = APP_ENV;
        } elseif (isset($_ENV['APP_ENV'])) {
            $appEnv = $_ENV['APP_ENV'];
        } else {
            $appEnv = 'development'; // Default to development
        }
        
        // Get DEV_EMAIL
        $devEmail = $_ENV['DEV_EMAIL'] ?? '';
        
        // Check if we're in development mode
        $isDevelopment = (strtolower($appEnv) === 'development');
        
        // In development, if DEV_EMAIL is not set, block email sending
        if ($isDevelopment && empty($devEmail)) {
            error_log("EmailService: BLOCKED - Development mode but DEV_EMAIL not set. Email to {$originalTo} was blocked. Please set DEV_EMAIL in .env file.");
            return false; // Don't send email in development if DEV_EMAIL is not configured
        }

        // Redirect to DEV_EMAIL in development environment
        if ($isDevelopment && !empty($devEmail)) {
            $to = $devEmail;
            
            // Modify subject to indicate it's a dev redirect
            $subject = '[DEV] [Original: ' . $originalTo . '] ' . $subject;
            
            // Add notice to email body about redirection
            $devNotice = '
            <div style="background: #F98E13; border: 2px solid #F98E13; padding: 15px; margin: 20px 0; border-radius: 8px; opacity: 0.9;">
                <strong style="color: #ffffff;">⚠️ EMAIL DE DESENVOLVIMENTO</strong><br>
                <p style="color: #ffffff; margin: 10px 0 0 0;">
                    Este email foi redirecionado para o ambiente de desenvolvimento.<br>
                    <strong>Destinatário original:</strong> ' . htmlspecialchars($originalTo) . '<br>
                    <strong>Destinatário atual:</strong> ' . htmlspecialchars($devEmail) . '
                </p>
            </div>';
            
            // Insert notice after opening body tag or at the beginning of content
            if (strpos($html, '<body') !== false) {
                $html = preg_replace('/(<body[^>]*>)/', '$1' . $devNotice, $html, 1);
            } else {
                $html = $devNotice . $html;
            }
            
            // Add notice to text version
            $text = "⚠️ EMAIL DE DESENVOLVIMENTO\n\n" .
                   "Este email foi redirecionado para o ambiente de desenvolvimento.\n" .
                   "Destinatário original: {$originalTo}\n" .
                   "Destinatário atual: {$devEmail}\n\n" .
                   "---\n\n" . $text;
        }

        try {
            $mail = new PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;

            // SSL options for better compatibility with problematic servers
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                    'ciphers' => 'DEFAULT@SECLEVEL=1',
                    'disable_compression' => true,
                    'peer_name' => $this->smtpHost,
                    'capture_peer_cert' => false,
                    'capture_peer_cert_chain' => false
                )
            );
            
            // Enable verbose debug output in development
            if (defined('APP_ENV') && APP_ENV === 'development') {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = function($str, $level) {
                    error_log("PHPMailer Debug: " . $str);
                };
            }

            // Character encoding settings
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';

            // Recipients
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;

            $mail->send();
            return true;
        } catch (Exception $e) {
            $errorMsg = "EmailService Error: " . $e->getMessage();
            error_log($errorMsg);
            // Log additional debug info in development
            if (defined('APP_ENV') && APP_ENV === 'development') {
                error_log("SMTP Host: " . $this->smtpHost);
                error_log("SMTP Port: " . $this->smtpPort);
                error_log("SMTP Username: " . $this->smtpUsername);
            }
            return false;
        }
    }

    private function getWelcomeEmailTemplate(string $nome, string $verificationUrl): string
    {
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #1a1f3a; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #a90f0f; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #F98E13; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='margin-bottom: 20px;'>
                        {$this->getLogoInline()}
                    </div>
                    <h1>🎵 " . $this->t('welcome_title') . "</h1>
                    <p>Revolucionário! A tua conta foi criada com sucesso.</p>
                </div>
                <div class='content'>
                    <h2>Olá {$nome}!</h2>
                    <p>" . $this->t('welcome_subtitle') . "</p>

                    <p><strong>" . $this->t('welcome_next_step') . "</strong> " . $this->t('welcome_confirm_email') . "</p>

                    <div style='text-align: center;'>
                        <a href='{$verificationUrl}' class='button' style='background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 15px 30px; border-radius: 5px; font-weight: bold; display: inline-block;'>" . $this->t('welcome_confirm_button') . "</a>
                    </div>

                    <p><strong>" . $this->t('welcome_what_happens') . "</strong></p>
                    <ul>
                        <li>✅ " . $this->t('welcome_step_1') . "</li>
                        <li>⏳ " . $this->t('welcome_step_2') . "</li>
                        <li>🎵 " . $this->t('welcome_step_3') . "</li>
                        <li>🚀 " . $this->t('welcome_step_4') . "</li>
                    </ul>

                    <p>" . $this->t('welcome_copy_link') . "</p>
                    <p style='word-break: break-all; background: #f5f5f5; padding: 10px; border-radius: 5px; color: #1a1f3a;'>{$verificationUrl}</p>
                </div>
                <div class='footer'>
                    <p>" . $this->t('welcome_footer_made_with') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getWelcomeEmailText(string $nome, string $verificationUrl): string
    {
        return "
" . $this->t('welcome_title') . "

Olá {$nome}!

" . $this->t('welcome_subtitle') . "

" . $this->t('welcome_next_step') . " " . $this->t('welcome_confirm_email') . "
Link: {$verificationUrl}

" . $this->t('welcome_what_happens') . "
- " . $this->t('welcome_step_1') . "
- " . $this->t('welcome_step_2') . "
- " . $this->t('welcome_step_3') . "
- " . $this->t('welcome_step_4') . "

" . $this->t('welcome_footer_made_with') . "
" . $this->t('welcome_footer_copyright') . "
        ";
    }

    private function getApprovalEmailTemplate(string $nome): string
    {
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #1a1f3a; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #a90f0f; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #F98E13; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 " . $this->t('approval_title') . "</h1>
                    <p>" . $this->t('approval_subtitle') . "</p>
                </div>
                <div class='content'>
                    <h2>" . $this->t('approval_congratulations', ['nome' => $nome]) . "</h2>
                    <p>" . $this->t('approval_message') . "</p>

                    <div style='text-align: center;'>
                        <a href='" . BASE_URL . "login' class='button' style='background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 15px 30px; border-radius: 5px; font-weight: bold; display: inline-block;'>" . $this->t('approval_login_button') . "</a>
                    </div>

                    <p><strong>" . $this->t('approval_what_can_do') . "</strong></p>
                    <ul>
                        <li>🎵 " . $this->t('approval_feature_1') . "</li>
                        <li>🎤 " . $this->t('approval_feature_2') . "</li>
                        <li>🎪 " . $this->t('approval_feature_3') . "</li>
                        <li>👥 " . $this->t('approval_feature_4') . "</li>
                    </ul>

                    <p>" . $this->t('approval_contact') . "</p>
                </div>
                <div class='footer'>
                    <p>" . $this->t('welcome_footer_made_with') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getApprovalEmailText(string $nome): string
    {
        return "
" . $this->t('approval_title') . " - " . $this->t('approval_subtitle') . "

" . $this->t('approval_congratulations', ['nome' => $nome]) . "

" . $this->t('approval_message') . "

" . $this->t('approval_login_button') . ": " . BASE_URL . "login

" . $this->t('approval_what_can_do') . ":
- " . $this->t('approval_feature_1') . "
- " . $this->t('approval_feature_2') . "
- " . $this->t('approval_feature_3') . "
- " . $this->t('approval_feature_4') . "

" . $this->t('approval_contact') . "

" . $this->t('welcome_footer_made_with') . "
" . $this->t('welcome_footer_copyright') . "
        ";
    }

    private function getNewUserNotificationTemplate(string $userEmail, string $userName, string $adminUrl): string
    {
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #1a1f3a; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #a90f0f; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #F98E13; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
                .user-info { background: #f5f5f5; border: 1px solid #a90f0f; border-left: 4px solid #a90f0f; border-radius: 8px; padding: 20px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='margin-bottom: 20px;'>
                        {$this->getLogoInline()}
                    </div>
                    <h1>🔔 " . $this->t('new_user_title') . "</h1>
                    <p>" . $this->t('new_user_subtitle') . "</p>
                </div>
                <div class='content'>
                    <h2>" . $this->t('new_user_details') . "</h2>

                    <div class='user-info'>
                        <p><strong>" . $this->t('new_user_name') . "</strong> {$userName}</p>
                        <p><strong>" . $this->t('new_user_email') . "</strong> {$userEmail}</p>
                        <p><strong>" . $this->t('new_user_date') . "</strong> " . date('d/m/Y H:i') . "</p>
                        <p><strong>" . $this->t('new_user_status') . "</strong> <span style='color: #F98E13; font-weight: bold;'>" . $this->t('new_user_pending') . "</span></p>
                    </div>

                    <p><strong>" . $this->t('new_user_next_step') . "</strong> " . $this->t('new_user_approve_message') . "</p>

                    <div style='text-align: center;'>
                        <a href='{$adminUrl}' class='button' style='background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 15px 30px; border-radius: 5px; font-weight: bold; display: inline-block;'>" . $this->t('new_user_view_button') . "</a>
                    </div>

                    <p><strong>" . $this->t('new_user_what_can_do') . "</strong></p>
                    <ul>
                        <li>✅ " . $this->t('new_user_action_1') . "</li>
                        <li>❌ " . $this->t('new_user_action_2') . "</li>
                        <li>📧 " . $this->t('new_user_action_3') . "</li>
                        <li>👥 " . $this->t('new_user_action_4') . "</li>
                    </ul>

                    <p>" . $this->t('new_user_copy_link') . "</p>
                    <p style='word-break: break-all; background: #f5f5f5; padding: 10px; border-radius: 5px; color: #1a1f3a;'>{$adminUrl}</p>
                </div>
                <div class='footer'>
                    <p>" . $this->t('new_user_footer_auto') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getNewUserNotificationText(string $userEmail, string $userName, string $adminUrl): string
    {
        return "
" . $this->t('new_user_title') . " - " . $this->t('new_user_pending') . "

" . $this->t('new_user_details') . ":
- " . $this->t('new_user_name') . " {$userName}
- " . $this->t('new_user_email') . " {$userEmail}
- " . $this->t('new_user_date') . " " . date('d/m/Y H:i') . "
- " . $this->t('new_user_status') . " " . $this->t('new_user_pending') . "

" . $this->t('new_user_next_step') . " " . $this->t('new_user_approve_message') . "

" . $this->t('new_user_view_button') . ": {$adminUrl}

" . $this->t('new_user_what_can_do') . ":
- " . $this->t('new_user_action_1') . "
- " . $this->t('new_user_action_2') . "
- " . $this->t('new_user_action_3') . "
- " . $this->t('new_user_action_4') . "

" . $this->t('new_user_footer_auto') . "
" . $this->t('welcome_footer_copyright') . "
        ";
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(string $email, string $nome, string $resetToken): bool
    {
        $subject = $this->t('password_reset_subject');
        $resetUrl = BASE_URL . 'reset-password?token=' . $resetToken;

        $html = $this->getPasswordResetEmailTemplate($nome, $resetUrl);
        $text = $this->getPasswordResetEmailText($nome, $resetUrl);

        return $this->sendEmailInternal($email, $subject, $html, $text);
    }

    /**
     * Send password reset success email
     */
    public function sendPasswordResetSuccessEmail(string $email, string $nome): bool
    {
        $subject = $this->t('password_reset_success_subject');

        $html = $this->getPasswordResetSuccessEmailTemplate($nome);
        $text = $this->getPasswordResetSuccessEmailText($nome);

        return $this->sendEmailInternal($email, $subject, $html, $text);
    }

    private function getPasswordResetEmailTemplate(string $nome, string $resetUrl): string
    {
        // Use inline SVG for better email client compatibility
        $logoHtml = $this->getLogoInline();
        
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #1a1f3a; 
                    background-color: #f5f5f5;
                    margin: 0; 
                    padding: 20px;
                }
                .email-wrapper {
                    max-width: 600px; 
                    margin: 0 auto; 
                    background-color: #ffffff;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 20px rgba(169, 15, 15, 0.1);
                }
                .header { 
                    background: #a90f0f; 
                    color: white; 
                    padding: 40px 30px; 
                    text-align: center;
                    position: relative;
                }
                .header::before {
                    content: '';
                    position: absolute;
                    top: -50px;
                    right: -50px;
                    width: 200px;
                    height: 200px;
                    background: rgba(249, 142, 19, 0.2);
                    border-radius: 50%;
                    filter: blur(60px);
                }
                .header-content {
                    position: relative;
                    z-index: 1;
                }
                .logo-container {
                    margin-bottom: 20px;
                }
                .header h1 {
                    font-size: 1.75rem;
                    font-weight: 700;
                    margin-bottom: 10px;
                    color: white;
                }
                .header p {
                    font-size: 1rem;
                    opacity: 0.95;
                    color: white;
                }
                .content { 
                    background: #ffffff; 
                    padding: 40px 30px; 
                }
                .greeting {
                    font-size: 1.125rem;
                    font-weight: 600;
                    color: #1a1f3a;
                    margin-bottom: 20px;
                }
                .message {
                    font-size: 1rem;
                    color: #6b7280;
                    margin-bottom: 30px;
                    line-height: 1.7;
                }
                .button-container {
                    text-align: center;
                    margin: 35px 0;
                }
                .button { 
                    display: inline-block; 
                    background: #F98E13;
                    color: #ffffff; 
                    padding: 16px 40px; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    font-weight: 600;
                    font-size: 1rem;
                    box-shadow: 0 4px 14px rgba(249, 142, 19, 0.4);
                    transition: all 0.3s ease;
                }
                .button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(249, 142, 19, 0.5);
                }
                .warning { 
                    background: #f5f5f5; 
                    border-left: 4px solid #F98E13;
                    padding: 20px; 
                    border-radius: 8px; 
                    margin: 30px 0;
                }
                .warning-title {
                    font-weight: 700;
                    color: #F98E13;
                    margin-bottom: 12px;
                    font-size: 1rem;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .warning ul {
                    margin: 10px 0 0 0;
                    padding-left: 20px;
                    color: #1a1f3a;
                }
                .warning li {
                    margin-bottom: 8px;
                    line-height: 1.5;
                }
                .link-box {
                    background: #f5f5f5;
                    padding: 15px;
                    border-radius: 8px;
                    margin-top: 25px;
                    word-break: break-all;
                    font-family: 'Courier New', monospace;
                    font-size: 0.875rem;
                    color: #6b7280;
                    border: 1px solid #f5f5f5;
                }
                .link-label {
                    font-weight: 600;
                    color: #1a1f3a;
                    margin-bottom: 8px;
                    font-size: 0.875rem;
                }
                .footer { 
                    text-align: center; 
                    padding: 30px;
                    background: #f5f5f5;
                    border-top: 1px solid #e5e7eb;
                    color: #6b7280; 
                    font-size: 0.875rem;
                }
                .footer p {
                    margin: 5px 0;
                }
                @media only screen and (max-width: 600px) {
                    body { padding: 10px; }
                    .header, .content { padding: 25px 20px; }
                    .button { padding: 14px 30px; font-size: 0.9375rem; }
                }
            </style>
        </head>
        <body>
            <div class='email-wrapper'>
                <div class='header'>
                    <div class='header-content'>
                        <div class='logo-container'>
                            {$logoHtml}
                        </div>
                        <h1>🔐 " . $this->t('password_reset_title') . "</h1>
                        <p>" . $this->t('password_reset_subtitle') . "</p>
                    </div>
                </div>
                <div class='content'>
                    <div class='greeting'>" . $this->t('password_reset_hello', ['nome' => $nome]) . "</div>
                    <div class='message'>" . $this->t('password_reset_message') . "</div>

                    <div class='button-container'>
                        <a href='{$resetUrl}' class='button' style='background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;'>" . $this->t('password_reset_button') . "</a>
                    </div>

                    <div class='warning'>
                        <div class='warning-title'>
                            <span>⚠️</span>
                            <span>" . $this->t('password_reset_important') . "</span>
                        </div>
                        <ul>
                            <li>" . $this->t('password_reset_warning_1') . "</li>
                            <li>" . $this->t('password_reset_warning_2') . "</li>
                            <li>" . $this->t('password_reset_warning_3') . "</li>
                        </ul>
                    </div>

                    <div class='link-box'>
                        <div class='link-label'>" . $this->t('password_reset_copy_link') . "</div>
                        {$resetUrl}
                    </div>
                </div>
                <div class='footer'>
                    <p>" . $this->t('welcome_footer_made_with') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getPasswordResetEmailText(string $nome, string $resetUrl): string
    {
        return "
" . $this->t('password_reset_hello', ['nome' => $nome]) . "

" . $this->t('password_reset_message') . "

" . $this->t('password_reset_button') . ": {$resetUrl}

⚠️ " . $this->t('password_reset_important') . ":
- " . $this->t('password_reset_warning_1') . "
- " . $this->t('password_reset_warning_2') . "
- " . $this->t('password_reset_warning_3') . "

" . $this->t('welcome_footer_made_with') . "
" . $this->t('welcome_footer_copyright') . "
        ";
    }

    private function getPasswordResetSuccessEmailTemplate(string $nome): string
    {
        // Use inline SVG for better email client compatibility
        $logoHtml = $this->getLogoInline();
        
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #1a1f3a; 
                    background-color: #f5f5f5;
                    margin: 0; 
                    padding: 20px;
                }
                .email-wrapper {
                    max-width: 600px; 
                    margin: 0 auto; 
                    background-color: #ffffff;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 20px rgba(169, 15, 15, 0.1);
                }
                .header { 
                    background: #a90f0f; 
                    color: white; 
                    padding: 40px 30px; 
                    text-align: center;
                    position: relative;
                }
                .header::before {
                    content: '';
                    position: absolute;
                    top: -50px;
                    right: -50px;
                    width: 200px;
                    height: 200px;
                    background: rgba(249, 142, 19, 0.2);
                    border-radius: 50%;
                    filter: blur(60px);
                }
                .header-content {
                    position: relative;
                    z-index: 1;
                }
                .logo-container {
                    margin-bottom: 20px;
                }
                .header h1 {
                    font-size: 1.75rem;
                    font-weight: 700;
                    margin-bottom: 10px;
                    color: white;
                }
                .header p {
                    font-size: 1rem;
                    opacity: 0.95;
                    color: white;
                }
                .content { 
                    background: #ffffff; 
                    padding: 40px 30px; 
                }
                .greeting {
                    font-size: 1.125rem;
                    font-weight: 600;
                    color: #1a1f3a;
                    margin-bottom: 20px;
                }
                .message {
                    font-size: 1rem;
                    color: #6b7280;
                    margin-bottom: 30px;
                    line-height: 1.7;
                }
                .success-box { 
                    background: #f5f5f5; 
                    border-left: 4px solid #F98E13;
                    padding: 20px; 
                    border-radius: 8px; 
                    margin: 30px 0;
                }
                .success-box strong {
                    color: #F98E13;
                    font-size: 1rem;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    margin-bottom: 8px;
                }
                .success-box p {
                    color: #1a1f3a;
                    margin: 0;
                    line-height: 1.6;
                }
                .button-container {
                    text-align: center;
                    margin: 35px 0;
                }
                .button { 
                    display: inline-block; 
                    background: #F98E13;
                    color: #ffffff; 
                    padding: 16px 40px; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    font-weight: 600;
                    font-size: 1rem;
                    box-shadow: 0 4px 14px rgba(249, 142, 19, 0.4);
                    transition: all 0.3s ease;
                }
                .button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(249, 142, 19, 0.5);
                }
                .security-note {
                    margin-top: 25px;
                    padding: 20px;
                    background: #f5f5f5;
                    border-radius: 8px;
                    border: 1px solid #f5f5f5;
                }
                .security-note strong {
                    color: #1a1f3a;
                    display: block;
                    margin-bottom: 8px;
                    font-size: 0.9375rem;
                }
                .security-note p {
                    color: #6b7280;
                    margin: 0;
                    font-size: 0.9375rem;
                    line-height: 1.6;
                }
                .footer { 
                    text-align: center; 
                    padding: 30px;
                    background: #f5f5f5;
                    border-top: 1px solid #e5e7eb;
                    color: #6b7280; 
                    font-size: 0.875rem;
                }
                .footer p {
                    margin: 5px 0;
                }
                @media only screen and (max-width: 600px) {
                    body { padding: 10px; }
                    .header, .content { padding: 25px 20px; }
                    .button { padding: 14px 30px; font-size: 0.9375rem; }
                }
            </style>
        </head>
        <body>
            <div class='email-wrapper'>
                <div class='header'>
                    <div class='header-content'>
                        <div class='logo-container'>
                            {$logoHtml}
                        </div>
                        <h1>✅ " . $this->t('password_reset_success_title') . "</h1>
                        <p>" . $this->t('password_reset_success_subtitle') . "</p>
                    </div>
                </div>
                <div class='content'>
                    <div class='greeting'>" . $this->t('password_reset_success_hello', ['nome' => $nome]) . "</div>
                    <div class='message'>" . $this->t('password_reset_success_message') . "</div>

                    <div class='success-box'>
                        <strong>
                            <span>✅</span>
                            <span>" . $this->t('password_reset_success_security') . ":</span>
                        </strong>
                        <p>" . $this->t('password_reset_success_security_message') . "</p>
                    </div>

                    <div class='button-container'>
                        <a href='" . BASE_URL . "login' class='button' style='background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;'>" . $this->t('password_reset_success_login_button') . "</a>
                    </div>

                    <div class='security-note'>
                        <strong>" . $this->t('password_reset_success_if_not_you') . "</strong>
                        <p>" . $this->t('password_reset_success_contact') . "</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>" . $this->t('welcome_footer_made_with') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getPasswordResetSuccessEmailText(string $nome): string
    {
        return "
" . $this->t('password_reset_success_hello', ['nome' => $nome]) . "

" . $this->t('password_reset_success_message') . "

✅ " . $this->t('password_reset_success_security') . ": " . $this->t('password_reset_success_security_message') . "

" . $this->t('password_reset_success_login_button') . ": " . BASE_URL . "login

" . $this->t('password_reset_success_if_not_you') . "
" . $this->t('password_reset_success_contact') . "

" . $this->t('welcome_footer_made_with') . "
" . $this->t('welcome_footer_copyright') . "
        ";
    }

    /**
     * Get logo as HTML - works in all email clients including Gmail
     * Uses HTML table with styled text - most compatible approach
     * Gmail strips SVG, divs with CSS, and sometimes emojis
     * Solution: Pure HTML table with inline styles (Gmail-safe)
     */
    /**
     * Send notification email
     */
    public function sendNotificationEmail(string $email, string $nome, string $notificationType, string $title, string $message, string $link = null, ?int $userId = null): bool
    {
        $subject = $this->t('notification_email_subject') . ': ' . $title;
        
        $html = $this->getNotificationEmailTemplate($nome, $notificationType, $title, $message, $link);
        $text = $this->getNotificationEmailText($nome, $notificationType, $title, $message, $link);
        
        return $this->sendEmail($email, $subject, $html, $text, 'notification', $userId);
    }

    /**
     * Send message email
     */
    public function sendMessageEmail(string $email, string $nome, string $senderName, string $subject, string $messageContent, string $link, bool $isAnnouncement = false, ?int $userId = null): bool
    {
        $emailSubject = $isAnnouncement 
            ? $this->t('message_email_announcement_title') . ': ' . $subject
            : $this->t('message_email_private_title') . ': ' . $subject;
        
        $html = $this->getMessageEmailTemplate($nome, $senderName, $subject, $messageContent, $link, $isAnnouncement);
        $text = $this->getMessageEmailText($nome, $senderName, $subject, $messageContent, $link, $isAnnouncement);
        
        return $this->sendEmail($email, $emailSubject, $html, $text, 'message', $userId);
    }

    /**
     * Send fraction assignment email
     */
    public function sendFractionAssignmentEmail(string $email, string $nome, string $condominiumName, string $fractionIdentifier, string $link, ?int $userId = null): bool
    {
        $subject = $this->t('fraction_assignment_subject') . ': ' . $fractionIdentifier;
        
        $html = $this->getFractionAssignmentEmailTemplate($nome, $condominiumName, $fractionIdentifier, $link);
        $text = $this->getFractionAssignmentEmailText($nome, $condominiumName, $fractionIdentifier, $link);
        
        return $this->sendEmail($email, $subject, $html, $text, 'notification', $userId);
    }

    /**
     * Get email template for subscription renewal reminder
     */
    public function getSubscriptionRenewalReminderTemplate(string $nome, string $planName, string $expirationDate, int $daysLeft, float $monthlyPrice, string $link): string
    {
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Renovação de Subscrição</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background-color: #007bff; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;'>
                <h1 style='margin: 0;'>Renovação de Subscrição</h1>
            </div>
            <div style='background-color: #f8f9fa; padding: 30px; border-radius: 0 0 5px 5px;'>
                <p>Olá <strong>{$nome}</strong>,</p>
                <p>A sua subscrição do plano <strong>{$planName}</strong> expira em <strong>{$daysLeft} dia(s)</strong> ({$expirationDate}).</p>
                <div style='background-color: white; padding: 20px; border-left: 4px solid #007bff; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>Valor mensal:</strong> €" . number_format($monthlyPrice, 2, ',', '.') . "</p>
                </div>
                <p>Para renovar a sua subscrição e evitar o bloqueio do acesso, efetue o pagamento através do link abaixo.</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$link}' style='display: inline-block; padding: 15px 30px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Renovar Subscrição</a>
                </div>
                <div style='background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>⚠️ Importante:</strong> Se não renovar até {$expirationDate}, o acesso à gestão dos condomínios será bloqueado.</p>
                </div>
            </div>
            <div style='text-align: center; margin-top: 20px; color: #666; font-size: 12px;'>
                <p>© O Meu Prédio - Todos os direitos reservados</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }

    /**
     * Get email template for subscription expiration
     */
    public function getSubscriptionExpiredTemplate(string $nome, string $planName, string $link): string
    {
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Subscrição Expirada</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background-color: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;'>
                <h1 style='margin: 0;'>Subscrição Expirada</h1>
            </div>
            <div style='background-color: #f8f9fa; padding: 30px; border-radius: 0 0 5px 5px;'>
                <p>Olá <strong>{$nome}</strong>,</p>
                <p>A sua subscrição do plano <strong>{$planName}</strong> expirou.</p>
                <div style='background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>⚠️ Acesso Bloqueado:</strong> O acesso à gestão dos condomínios foi bloqueado até efetuar o pagamento.</p>
                </div>
                <p>Para reativar a sua subscrição, efetue o pagamento através do link abaixo.</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$link}' style='display: inline-block; padding: 15px 30px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Renovar Subscrição</a>
                </div>
                <div style='background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>ℹ️ Nota:</strong> Para reativar uma subscrição expirada, será necessário pagar os meses em atraso mais o mês atual.</p>
                </div>
            </div>
            <div style='text-align: center; margin-top: 20px; color: #666; font-size: 12px;'>
                <p>© O Meu Prédio - Todos os direitos reservados</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }

    private function getNotificationEmailTemplate(string $nome, string $notificationType, string $title, string $message, ?string $link = null): string
    {
        $logoHtml = $this->getLogoInline();
        
        // Map notification types to icons
        $icons = [
            'occurrence' => '⚠️',
            'fee_overdue' => '💰',
            'assembly' => '📋',
            'vote' => '🗳️',
            'occurrence_comment' => '💬',
            'default' => '🔔'
        ];
        $icon = $icons[$notificationType] ?? $icons['default'];
        
        $linkHtml = '';
        if ($link) {
            $linkHtml = '
                    <div class="button-container">
                        <a href="' . htmlspecialchars($link) . '" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Detalhes</a>
                    </div>';
        }
        
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #1a1f3a; 
                    background-color: #f5f5f5;
                    margin: 0; 
                    padding: 20px;
                }
                .email-wrapper {
                    max-width: 600px; 
                    margin: 0 auto; 
                    background-color: #ffffff;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 20px rgba(169, 15, 15, 0.1);
                }
                .header { 
                    background: #a90f0f; 
                    color: white; 
                    padding: 40px 30px; 
                    text-align: center;
                    position: relative;
                }
                .header::before {
                    content: '';
                    position: absolute;
                    top: -50px;
                    right: -50px;
                    width: 200px;
                    height: 200px;
                    background: rgba(249, 142, 19, 0.2);
                    border-radius: 50%;
                    filter: blur(60px);
                }
                .header-content {
                    position: relative;
                    z-index: 1;
                }
                .logo-container {
                    margin-bottom: 20px;
                    padding: 10px 0;
                }
                .header h1 {
                    font-size: 1.75rem;
                    font-weight: 700;
                    margin-bottom: 10px;
                    color: white;
                }
                .header p {
                    font-size: 1rem;
                    opacity: 0.95;
                    color: white;
                }
                .content { 
                    background: #ffffff; 
                    padding: 40px 30px; 
                }
                .greeting {
                    font-size: 1.125rem;
                    font-weight: 600;
                    color: #1a1f3a;
                    margin-bottom: 20px;
                }
                .notification-title {
                    font-size: 1.25rem;
                    font-weight: 700;
                    color: #a90f0f;
                    margin-bottom: 15px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .message {
                    font-size: 1rem;
                    color: #6b7280;
                    margin-bottom: 30px;
                    line-height: 1.7;
                }
                .button-container {
                    text-align: center;
                    margin: 35px 0;
                }
                .button { 
                    display: inline-block; 
                    background: #F98E13;
                    color: #ffffff; 
                    padding: 16px 40px; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    font-weight: 600;
                    font-size: 1rem;
                    box-shadow: 0 4px 14px rgba(249, 142, 19, 0.4);
                    transition: all 0.3s ease;
                }
                .button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(249, 142, 19, 0.5);
                }
                .footer { 
                    text-align: center; 
                    padding: 30px;
                    background: #f5f5f5;
                    border-top: 1px solid #e5e7eb;
                    color: #6b7280; 
                    font-size: 0.875rem;
                }
                .footer p {
                    margin: 5px 0;
                }
                @media only screen and (max-width: 600px) {
                    body { padding: 10px; }
                    .header, .content { padding: 25px 20px; }
                    .button { padding: 14px 30px; font-size: 0.9375rem; }
                }
            </style>
        </head>
        <body>
            <div class='email-wrapper'>
                <div class='header'>
                    <div class='header-content'>
                        <div class='logo-container'>
                            {$logoHtml}
                        </div>
                        <h1>{$icon} " . htmlspecialchars($title) . "</h1>
                        <p>Nova Notificação</p>
                    </div>
                </div>
                <div class='content'>
                    <div class='greeting'>Olá " . htmlspecialchars($nome) . "!</div>
                    
                    <div class='notification-title'>
                        <span>{$icon}</span>
                        <span>" . htmlspecialchars($title) . "</span>
                    </div>
                    
                    <div class='message'>" . nl2br(htmlspecialchars($message)) . "</div>
                    
                    {$linkHtml}
                </div>
                <div class='footer'>
                    <p>" . $this->t('welcome_footer_made_with') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getNotificationEmailText(string $nome, string $notificationType, string $title, string $message, ?string $link = null): string
    {
        $text = "Olá {$nome}!\n\n";
        $text .= htmlspecialchars($title) . "\n\n";
        $text .= htmlspecialchars($message) . "\n\n";
        
        if ($link) {
            $text .= "Ver detalhes: {$link}\n\n";
        }
        
        $text .= $this->t('welcome_footer_made_with') . "\n";
        $text .= $this->t('welcome_footer_copyright') . "\n";
        
        return $text;
    }

    private function getMessageEmailTemplate(string $nome, string $senderName, string $subject, string $messageContent, string $link, bool $isAnnouncement = false): string
    {
        $logoHtml = $this->getLogoInline();
        $messageType = $isAnnouncement ? 'Anúncio' : 'Mensagem Privada';
        $icon = $isAnnouncement ? '📢' : '✉️';
        
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #1a1f3a; 
                    background-color: #f5f5f5;
                    margin: 0; 
                    padding: 20px;
                }
                .email-wrapper {
                    max-width: 600px; 
                    margin: 0 auto; 
                    background-color: #ffffff;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 20px rgba(169, 15, 15, 0.1);
                }
                .header { 
                    background: #a90f0f; 
                    color: white; 
                    padding: 40px 30px; 
                    text-align: center;
                    position: relative;
                }
                .header::before {
                    content: '';
                    position: absolute;
                    top: -50px;
                    right: -50px;
                    width: 200px;
                    height: 200px;
                    background: rgba(249, 142, 19, 0.2);
                    border-radius: 50%;
                    filter: blur(60px);
                }
                .header-content {
                    position: relative;
                    z-index: 1;
                }
                .logo-container {
                    margin-bottom: 20px;
                    padding: 10px 0;
                }
                .header h1 {
                    font-size: 1.75rem;
                    font-weight: 700;
                    margin-bottom: 10px;
                    color: white;
                }
                .header p {
                    font-size: 1rem;
                    opacity: 0.95;
                    color: white;
                }
                .content { 
                    background: #ffffff; 
                    padding: 40px 30px; 
                }
                .greeting {
                    font-size: 1.125rem;
                    font-weight: 600;
                    color: #1a1f3a;
                    margin-bottom: 20px;
                }
                .message-info {
                    background: #f5f5f5;
                    border-left: 4px solid #a90f0f;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 25px;
                }
                .message-info-row {
                    margin-bottom: 10px;
                    font-size: 0.9375rem;
                }
                .message-info-label {
                    font-weight: 600;
                    color: #1a1f3a;
                    display: inline-block;
                    min-width: 100px;
                }
                .message-info-value {
                    color: #6b7280;
                }
                .message-content {
                    background: #ffffff;
                    border: 1px solid #f5f5f5;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 25px 0;
                    font-size: 1rem;
                    line-height: 1.7;
                    color: #1a1f3a;
                }
                .button-container {
                    text-align: center;
                    margin: 35px 0;
                }
                .button { 
                    display: inline-block; 
                    background: #F98E13;
                    color: #ffffff; 
                    padding: 16px 40px; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    font-weight: 600;
                    font-size: 1rem;
                    box-shadow: 0 4px 14px rgba(249, 142, 19, 0.4);
                    transition: all 0.3s ease;
                }
                .button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(249, 142, 19, 0.5);
                }
                .footer { 
                    text-align: center; 
                    padding: 30px;
                    background: #f5f5f5;
                    border-top: 1px solid #e5e7eb;
                    color: #6b7280; 
                    font-size: 0.875rem;
                }
                .footer p {
                    margin: 5px 0;
                }
                @media only screen and (max-width: 600px) {
                    body { padding: 10px; }
                    .header, .content { padding: 25px 20px; }
                    .button { padding: 14px 30px; font-size: 0.9375rem; }
                }
            </style>
        </head>
        <body>
            <div class='email-wrapper'>
                <div class='header'>
                    <div class='header-content'>
                        <div class='logo-container'>
                            {$logoHtml}
                        </div>
                        <h1>{$icon} Nova {$messageType}</h1>
                        <p>Você recebeu uma nova mensagem</p>
                    </div>
                </div>
                <div class='content'>
                    <div class='greeting'>Olá " . htmlspecialchars($nome) . "!</div>
                    
                    <div class='message-info'>
                        <div class='message-info-row'>
                            <span class='message-info-label'>De:</span>
                            <span class='message-info-value'>" . htmlspecialchars($senderName) . "</span>
                        </div>
                        <div class='message-info-row'>
                            <span class='message-info-label'>Assunto:</span>
                            <span class='message-info-value'>" . htmlspecialchars($subject) . "</span>
                        </div>
                        <div class='message-info-row'>
                            <span class='message-info-label'>Tipo:</span>
                            <span class='message-info-value'>{$messageType}</span>
                        </div>
                    </div>
                    
                    <div class='message-content'>" . $messageContent . "</div>
                    
                    <div class='button-container'>
                        <a href='" . htmlspecialchars($link) . "' class='button' style='background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;'>" . ($isAnnouncement ? 'Ver Anúncio' : 'Responder Mensagem') . "</a>
                    </div>
                </div>
                <div class='footer'>
                    <p>" . $this->t('welcome_footer_made_with') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getMessageEmailText(string $nome, string $senderName, string $subject, string $messageContent, string $link, bool $isAnnouncement = false): string
    {
        $messageType = $isAnnouncement ? 'Anúncio' : 'Mensagem Privada';
        $text = "Olá {$nome}!\n\n";
        $text .= "Você recebeu uma nova {$messageType}.\n\n";
        $text .= "De: {$senderName}\n";
        $text .= "Assunto: {$subject}\n\n";
        $text .= strip_tags($messageContent) . "\n\n";
        $text .= ($isAnnouncement ? 'Ver anúncio' : 'Responder mensagem') . ": {$link}\n\n";
        $text .= $this->t('welcome_footer_made_with') . "\n";
        $text .= $this->t('welcome_footer_copyright') . "\n";
        
        return $text;
    }

    private function getFractionAssignmentEmailTemplate(string $nome, string $condominiumName, string $fractionIdentifier, string $link): string
    {
        $logoHtml = $this->getLogoInline();
        
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #1a1f3a; 
                    background-color: #f5f5f5;
                    margin: 0; 
                    padding: 20px;
                }
                .email-wrapper {
                    max-width: 600px; 
                    margin: 0 auto; 
                    background-color: #ffffff;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 20px rgba(169, 15, 15, 0.1);
                }
                .header { 
                    background: #a90f0f; 
                    color: white; 
                    padding: 40px 30px; 
                    text-align: center;
                    position: relative;
                }
                .header::before {
                    content: '';
                    position: absolute;
                    top: -50px;
                    right: -50px;
                    width: 200px;
                    height: 200px;
                    background: rgba(249, 142, 19, 0.2);
                    border-radius: 50%;
                    filter: blur(60px);
                }
                .header-content {
                    position: relative;
                    z-index: 1;
                }
                .logo-container {
                    margin-bottom: 20px;
                    padding: 10px 0;
                }
                .header h1 {
                    font-size: 1.75rem;
                    font-weight: 700;
                    margin-bottom: 10px;
                    color: white;
                }
                .header p {
                    font-size: 1rem;
                    opacity: 0.95;
                    color: white;
                }
                .content { 
                    background: #ffffff; 
                    padding: 40px 30px; 
                }
                .greeting {
                    font-size: 1.125rem;
                    font-weight: 600;
                    color: #1a1f3a;
                    margin-bottom: 20px;
                }
                .info-box {
                    background: #f5f5f5;
                    border-left: 4px solid #a90f0f;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 25px;
                }
                .info-row {
                    margin-bottom: 12px;
                    font-size: 1rem;
                }
                .info-label {
                    font-weight: 600;
                    color: #1a1f3a;
                    display: inline-block;
                    min-width: 120px;
                }
                .info-value {
                    color: #a90f0f;
                    font-weight: 600;
                }
                .message {
                    font-size: 1rem;
                    color: #6b7280;
                    margin-bottom: 30px;
                    line-height: 1.7;
                }
                .button-container {
                    text-align: center;
                    margin: 35px 0;
                }
                .button { 
                    display: inline-block; 
                    background: #F98E13;
                    color: #ffffff; 
                    padding: 16px 40px; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    font-weight: 600;
                    font-size: 1rem;
                    box-shadow: 0 4px 14px rgba(249, 142, 19, 0.4);
                    transition: all 0.3s ease;
                }
                .button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(249, 142, 19, 0.5);
                }
                .footer { 
                    text-align: center; 
                    padding: 30px;
                    background: #f5f5f5;
                    border-top: 1px solid #e5e7eb;
                    color: #6b7280; 
                    font-size: 0.875rem;
                }
                .footer p {
                    margin: 5px 0;
                }
                @media only screen and (max-width: 600px) {
                    body { padding: 10px; }
                    .header, .content { padding: 25px 20px; }
                    .button { padding: 14px 30px; font-size: 0.9375rem; }
                }
            </style>
        </head>
        <body>
            <div class='email-wrapper'>
                <div class='header'>
                    <div class='header-content'>
                        <div class='logo-container'>
                            {$logoHtml}
                        </div>
                        <h1>🏠 Fração Atribuída</h1>
                        <p>Uma fração foi atribuída à sua conta</p>
                    </div>
                </div>
                <div class='content'>
                    <div class='greeting'>Olá " . htmlspecialchars($nome) . "!</div>
                    
                    <div class='message'>
                        " . $this->t('fraction_assignment_message') . "
                    </div>
                    
                    <div class='info-box'>
                        <div class='info-row'>
                            <span class='info-label'>Condomínio:</span>
                            <span class='info-value'>" . htmlspecialchars($condominiumName) . "</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Fração:</span>
                            <span class='info-value'>" . htmlspecialchars($fractionIdentifier) . "</span>
                        </div>
                    </div>
                    
                    <div class='button-container'>
                        <a href='" . htmlspecialchars($link) . "' class='button' style='background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;'>Ver Fração</a>
                    </div>
                </div>
                <div class='footer'>
                    <p>" . $this->t('welcome_footer_made_with') . "</p>
                    <p>" . $this->t('welcome_footer_copyright') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getFractionAssignmentEmailText(string $nome, string $condominiumName, string $fractionIdentifier, string $link): string
    {
        $text = "Olá {$nome}!\n\n";
        $text .= $this->t('fraction_assignment_message') . "\n\n";
        $text .= "Condomínio: {$condominiumName}\n";
        $text .= "Fração: {$fractionIdentifier}\n\n";
        $text .= "Ver fração: {$link}\n\n";
        $text .= $this->t('welcome_footer_made_with') . "\n";
        $text .= $this->t('welcome_footer_copyright') . "\n";
        
        return $text;
    }

    private function getLogoInline(): string
    {
        // Get APP_ENV to determine if we should use URL or base64
        $appEnv = null;
        if (defined('APP_ENV')) {
            $appEnv = APP_ENV;
        } elseif (isset($_ENV['APP_ENV'])) {
            $appEnv = $_ENV['APP_ENV'];
        } else {
            $appEnv = 'development';
        }
        
        $isDevelopment = (strtolower($appEnv) === 'development');
        
        // In development, try URL first (better for local testing)
        if ($isDevelopment && defined('BASE_URL')) {
            $logoUrl = rtrim(BASE_URL, '/') . '/assets/images/logo.png';
            // Verify URL is accessible (optional check)
            $logoPngPath = __DIR__ . '/../../assets/images/logo.png';
            if (file_exists($logoPngPath) && is_readable($logoPngPath)) {
                error_log("EmailService: Using logo URL for development: {$logoUrl}");
                return '
                <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                    <tr>
                        <td align="center" style="padding: 15px 0 10px 0;">
                            <img src="' . htmlspecialchars($logoUrl) . '" alt="O Meu Prédio" style="max-width: 200px; height: auto; display: block; margin: 0 auto; width: auto;" width="200" />
                        </td>
                    </tr>
                </table>';
            }
        }
        
        // Try to use PNG logo as base64 (better email client compatibility for production)
        $pngLogo = $this->getLogoPngBase64();
        if (!empty($pngLogo)) {
            return '
            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                <tr>
                    <td align="center" style="padding: 15px 0 10px 0;">
                        <img src="' . htmlspecialchars($pngLogo) . '" alt="O Meu Prédio" style="max-width: 200px; height: auto; display: block; margin: 0 auto; width: auto;" width="200" />
                    </td>
                </tr>
            </table>';
        }
        
        // Fallback to text-based logo if PNG not available
        return '
        <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
            <tr>
                <td align="center" style="padding: 15px 0 10px 0;">
                    <table border="0" cellpadding="0" cellspacing="0">
                        <tr>
                            <td align="center" style="font-family: Arial, Helvetica, sans-serif; font-size: 26px; font-weight: bold; color: #ffffff; letter-spacing: 1px; line-height: 1.2;">
                                O MEU PRÉDIO
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #ffffff; letter-spacing: 0.5px; padding-top: 5px; opacity: 0.9;">
                                Gestão de Condomínios
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';
    }
    
    private function getLogoPngBase64(): string
    {
        // Try to load PNG logo
        $logoPngPath = __DIR__ . '/../../assets/images/logo.png';
        if (file_exists($logoPngPath) && is_readable($logoPngPath)) {
            $logoContent = file_get_contents($logoPngPath);
            if ($logoContent !== false && strlen($logoContent) > 0) {
                // Encode PNG as base64 for email
                $base64 = base64_encode($logoContent);
                error_log("EmailService: PNG logo loaded successfully, size: " . strlen($logoContent) . " bytes, base64 length: " . strlen($base64));
                return 'data:image/png;base64,' . $base64;
            } else {
                error_log("EmailService: PNG logo file exists but content is empty or unreadable");
            }
        } else {
            error_log("EmailService: PNG logo not found at: {$logoPngPath}");
        }
        return '';
    }
    
    private function getLogoBase64(): string
    {
        // Try project logo first
        $logoPath = __DIR__ . '/../../assets/images/logo.svg';
        if (file_exists($logoPath) && is_readable($logoPath)) {
            $logoContent = file_get_contents($logoPath);
            if ($logoContent !== false) {
                // Encode SVG properly for email (URL encode instead of base64 for better compatibility)
                return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($logoContent);
            }
        }
        // Fallback to old logo if exists
        $oldLogoPath = __DIR__ . '/../../assets/images/sync2stage_logo.svg';
        if (file_exists($oldLogoPath) && is_readable($oldLogoPath)) {
            $logoContent = file_get_contents($oldLogoPath);
            if ($logoContent !== false) {
                return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($logoContent);
            }
        }
        return '';
    }
}
