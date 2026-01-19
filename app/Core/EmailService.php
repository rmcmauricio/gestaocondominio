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
     */
    public function sendEmail(string $to, string $subject, string $html, string $text = ''): bool
    {
        return $this->sendEmailInternal($to, $subject, $html, $text);
    }

    private function sendEmailInternal(string $to, string $subject, string $html, string $text): bool
    {
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
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #fabd14 0%, #e6a700 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #fabd14; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
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
                        <a href='{$verificationUrl}' class='button'>" . $this->t('welcome_confirm_button') . "</a>
                    </div>

                    <p><strong>" . $this->t('welcome_what_happens') . "</strong></p>
                    <ul>
                        <li>✅ " . $this->t('welcome_step_1') . "</li>
                        <li>⏳ " . $this->t('welcome_step_2') . "</li>
                        <li>🎵 " . $this->t('welcome_step_3') . "</li>
                        <li>🚀 " . $this->t('welcome_step_4') . "</li>
                    </ul>

                    <p>" . $this->t('welcome_copy_link') . "</p>
                    <p style='word-break: break-all; background: #eee; padding: 10px; border-radius: 5px;'>{$verificationUrl}</p>
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
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #fabd14 0%, #e6a700 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #fabd14; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
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
                        <a href='" . BASE_URL . "login' class='button'>" . $this->t('approval_login_button') . "</a>
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
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #fabd14 0%, #e6a700 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #fabd14; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                .user-info { background: #e8f4fd; border: 1px solid #bee5eb; border-radius: 8px; padding: 20px; margin: 20px 0; }
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
                        <p><strong>" . $this->t('new_user_status') . "</strong> <span style='color: #ff9800; font-weight: bold;'>" . $this->t('new_user_pending') . "</span></p>
                    </div>

                    <p><strong>" . $this->t('new_user_next_step') . "</strong> " . $this->t('new_user_approve_message') . "</p>

                    <div style='text-align: center;'>
                        <a href='{$adminUrl}' class='button'>" . $this->t('new_user_view_button') . "</a>
                    </div>

                    <p><strong>" . $this->t('new_user_what_can_do') . "</strong></p>
                    <ul>
                        <li>✅ " . $this->t('new_user_action_1') . "</li>
                        <li>❌ " . $this->t('new_user_action_2') . "</li>
                        <li>📧 " . $this->t('new_user_action_3') . "</li>
                        <li>👥 " . $this->t('new_user_action_4') . "</li>
                    </ul>

                    <p>" . $this->t('new_user_copy_link') . "</p>
                    <p style='word-break: break-all; background: #eee; padding: 10px; border-radius: 5px;'>{$adminUrl}</p>
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
                    box-shadow: 0 4px 20px rgba(23, 74, 126, 0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #174A7E 0%, #2a5f9e 100%); 
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
                    background: linear-gradient(135deg, #F98E13 0%, #ffa733 100%);
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
                    background: #fff8e1; 
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
                    color: #856404;
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
                    border: 1px solid #e5e7eb;
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
                    background: #f9fafb;
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
                        <a href='{$resetUrl}' class='button'>" . $this->t('password_reset_button') . "</a>
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
                    box-shadow: 0 4px 20px rgba(23, 74, 126, 0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
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
                    background: rgba(16, 185, 129, 0.2);
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
                    background: #d1fae5; 
                    border-left: 4px solid #10b981;
                    padding: 20px; 
                    border-radius: 8px; 
                    margin: 30px 0;
                }
                .success-box strong {
                    color: #065f46;
                    font-size: 1rem;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    margin-bottom: 8px;
                }
                .success-box p {
                    color: #047857;
                    margin: 0;
                    line-height: 1.6;
                }
                .button-container {
                    text-align: center;
                    margin: 35px 0;
                }
                .button { 
                    display: inline-block; 
                    background: linear-gradient(135deg, #174A7E 0%, #2a5f9e 100%);
                    color: #ffffff; 
                    padding: 16px 40px; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    font-weight: 600;
                    font-size: 1rem;
                    box-shadow: 0 4px 14px rgba(23, 74, 126, 0.3);
                    transition: all 0.3s ease;
                }
                .button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(23, 74, 126, 0.4);
                }
                .security-note {
                    margin-top: 25px;
                    padding: 20px;
                    background: #f9fafb;
                    border-radius: 8px;
                    border: 1px solid #e5e7eb;
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
                    background: #f9fafb;
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
                        <a href='" . BASE_URL . "login' class='button'>" . $this->t('password_reset_success_login_button') . "</a>
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
    private function getLogoInline(): string
    {
        // Pure HTML table with inline styles - Gmail-safe approach
        // Tables are the most reliable HTML element in email clients
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
