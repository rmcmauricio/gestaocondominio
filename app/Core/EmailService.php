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
        // Load email configuration from constants (defined in .env)
        $this->smtpHost = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $this->smtpPort = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $this->smtpUsername = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
        $this->smtpPassword = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        $this->fromEmail = defined('FROM_EMAIL') ? FROM_EMAIL : 'noreply@sync2stage.com';
        $this->fromName = defined('FROM_NAME') ? FROM_NAME : 'Sync2Stage';

        // Load email translations
        $this->loadEmailTranslations();
    }

    private function loadEmailTranslations(): void
    {
        $lang = $_SESSION['lang'] ?? 'pt';
        $emailFile = __DIR__ . "/../Metafiles/{$lang}/emails.json";

        if (file_exists($emailFile)) {
            $emailData = json_decode(file_get_contents($emailFile), true);
            if (isset($emailData['t'])) {
                $this->emailTranslations = $emailData['t'];
            }
        }

        // Fallback to Portuguese if translations not found
        if (empty($this->emailTranslations)) {
            $fallbackFile = __DIR__ . "/../Metafiles/pt/emails.json";
            if (file_exists($fallbackFile)) {
                $fallbackData = json_decode(file_get_contents($fallbackFile), true);
                if (isset($fallbackData['t'])) {
                    $this->emailTranslations = $fallbackData['t'];
                }
            }
        }
    }

    private function t(string $key, array $replacements = []): string
    {
        $text = $this->emailTranslations[$key] ?? $key;

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
                    'peer_name' => 'mail.lyricsjam.com',
                    'capture_peer_cert' => false,
                    'capture_peer_cert_chain' => false
                )
            );

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
            error_log("EmailService Error: " . $e->getMessage());
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
                        <img src='{$this->getLogoBase64()}' alt='Sync2Stage Logo' style='height: 60px; width: auto; filter: brightness(0) invert(1);'>
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
                        <img src='{$this->getLogoBase64()}' alt='Sync2Stage Logo' style='height: 60px; width: auto; filter: brightness(0) invert(1);'>
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
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #ffc107; color: #000; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3); }
                .button:hover { background: #ff8c00; color: #000; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin: 20px 0; }
                .warning strong { color: #856404; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='margin-bottom: 20px;'>
                        <img src='{$this->getLogoBase64()}' alt='Sync2Stage Logo' style='height: 60px; width: auto; filter: brightness(0) invert(1);'>
                    </div>
                    <h1>🔐 " . $this->t('password_reset_title') . "</h1>
                    <p>" . $this->t('password_reset_subtitle') . "</p>
                </div>
                <div class='content'>
                    <h2>" . $this->t('password_reset_hello', ['nome' => $nome]) . "</h2>
                    <p>" . $this->t('password_reset_message') . "</p>

                    <div style='text-align: center;'>
                        <a href='{$resetUrl}' class='button'>" . $this->t('password_reset_button') . "</a>
                    </div>

                    <div class='warning'>
                        <strong>⚠️ " . $this->t('password_reset_important') . "</strong>
                        <ul style='margin: 10px 0; padding-left: 20px;'>
                            <li>" . $this->t('password_reset_warning_1') . "</li>
                            <li>" . $this->t('password_reset_warning_2') . "</li>
                            <li>" . $this->t('password_reset_warning_3') . "</li>
                        </ul>
                    </div>

                    <p><strong>" . $this->t('password_reset_copy_link') . "</strong></p>
                    <p style='word-break: break-all; background: #f0f0f0; padding: 10px; border-radius: 5px; font-family: monospace;'>{$resetUrl}</p>
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
        return "
        <!DOCTYPE html>
        <html lang='pt'>
        <head>
            <meta charset='UTF-8'>
            <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #28a745; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3); }
                .button:hover { background: #20c997; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                .success { background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 8px; margin: 20px 0; }
                .success strong { color: #155724; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='margin-bottom: 20px;'>
                        <img src='{$this->getLogoBase64()}' alt='Sync2Stage Logo' style='height: 60px; width: auto; filter: brightness(0) invert(1);'>
                    </div>
                    <h1>✅ " . $this->t('password_reset_success_title') . "</h1>
                    <p>" . $this->t('password_reset_success_subtitle') . "</p>
                </div>
                <div class='content'>
                    <h2>" . $this->t('password_reset_success_hello', ['nome' => $nome]) . "</h2>
                    <p>" . $this->t('password_reset_success_message') . "</p>

                    <div class='success'>
                        <strong>✅ " . $this->t('password_reset_success_security') . ":</strong> " . $this->t('password_reset_success_security_message') . "
                    </div>

                    <div style='text-align: center;'>
                        <a href='" . BASE_URL . "login' class='button'>" . $this->t('password_reset_success_login_button') . "</a>
                    </div>

                    <p><strong>" . $this->t('password_reset_success_if_not_you') . "</strong></p>
                    <p>" . $this->t('password_reset_success_contact') . "</p>
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

    private function getLogoBase64(): string
    {
        $logoPath = __DIR__ . '/../../assets/images/sync2stage_logo.svg';
        if (file_exists($logoPath)) {
            return 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($logoPath));
        }
        return '';
    }
}
