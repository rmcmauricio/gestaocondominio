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
    private $emailTemplateModel;

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

        // Initialize email template model
        $this->emailTemplateModel = new \App\Models\EmailTemplate();
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
        $verificationUrl = BASE_URL . 'verify-email?token=' . $token;

        // Use template from database (required)
        $template = $this->emailTemplateModel->findByKey('welcome');
        if (!$template) {
            error_log("EmailService: Template 'welcome' not found in database. Please run seeder.");
            return false;
        }

        $subject = $template['subject'] ?? $this->t('welcome_subject');
        $html = $this->renderTemplate('welcome', [
            'nome' => $nome,
            'verificationUrl' => $verificationUrl,
            'baseUrl' => BASE_URL
        ]);
        $text = $this->renderTextTemplate('welcome', [
            'nome' => $nome,
            'verificationUrl' => $verificationUrl,
            'baseUrl' => BASE_URL
        ]);

        if (empty($html)) {
            error_log("EmailService: Failed to render 'welcome' template. Check base_layout exists.");
            return false;
        }

        return $this->sendEmailInternal($email, $subject, $html, $text);
    }

    public function sendApprovalNotification(string $email, string $nome): bool
    {
        // Try to use template from database
        $template = $this->emailTemplateModel->findByKey('approval');
        if ($template) {
            $subject = $template['subject'] ?? $this->t('approval_subject');
            $html = $this->renderTemplate('approval', [
                'nome' => $nome,
                'baseUrl' => BASE_URL
            ]);
            $text = $this->renderTextTemplate('approval', [
                'nome' => $nome,
                'baseUrl' => BASE_URL
            ]);
        } else {
            error_log("EmailService: Template 'approval' not found in database. Please run seeder.");
            return false;
        }

        return $this->sendEmailInternal($email, $subject, $html, $text);
    }

    public function sendNewUserNotification(string $userEmail, string $userName, int $userId): bool
    {
        $adminUrl = BASE_URL . 'dashboard/approvals';

        // Try to use template from database
        $template = $this->emailTemplateModel->findByKey('new_user_notification');
        if ($template) {
            $subject = $template['subject'] ?? $this->t('new_user_subject');
            $html = $this->renderTemplate('new_user_notification', [
                'userEmail' => $userEmail,
                'userName' => $userName,
                'userId' => (string)$userId,
                'adminUrl' => $adminUrl,
                'baseUrl' => BASE_URL
            ]);
            $text = $this->renderTextTemplate('new_user_notification', [
                'userEmail' => $userEmail,
                'userName' => $userName,
                'userId' => (string)$userId,
                'adminUrl' => $adminUrl,
                'baseUrl' => BASE_URL
            ]);
        } else {
            error_log("EmailService: Template 'new_user_notification' not found in database. Please run seeder.");
            return false;
        }

        // Obter emails de suporte (suporta múltiplos emails separados por vírgula)
        $supportEmails = defined('SUPPORT_EMAILS') ? SUPPORT_EMAILS : (defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'suporte@lyricsjam.com');
        $emailList = array_map('trim', explode(',', $supportEmails));

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


    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(string $email, string $nome, string $resetToken): bool
    {
        $resetUrl = BASE_URL . 'reset-password?token=' . $resetToken;

        // Try to use template from database
        $template = $this->emailTemplateModel->findByKey('password_reset');
        if ($template) {
            $subject = $template['subject'] ?? $this->t('password_reset_subject');
            $html = $this->renderTemplate('password_reset', [
                'nome' => $nome,
                'resetUrl' => $resetUrl,
                'baseUrl' => BASE_URL
            ]);
            $text = $this->renderTextTemplate('password_reset', [
                'nome' => $nome,
                'resetUrl' => $resetUrl,
                'baseUrl' => BASE_URL
            ]);
        } else {
            error_log("EmailService: Template 'password_reset' not found in database. Please run seeder.");
            return false;
        }

        return $this->sendEmailInternal($email, $subject, $html, $text);
    }

    /**
     * Send password reset success email
     */
    public function sendPasswordResetSuccessEmail(string $email, string $nome): bool
    {
        // Try to use template from database
        $template = $this->emailTemplateModel->findByKey('password_reset_success');
        if ($template) {
            $subject = $template['subject'] ?? $this->t('password_reset_success_subject');
            $html = $this->renderTemplate('password_reset_success', [
                'nome' => $nome,
                'baseUrl' => BASE_URL
            ]);
            $text = $this->renderTextTemplate('password_reset_success', [
                'nome' => $nome,
                'baseUrl' => BASE_URL
            ]);
        } else {
            error_log("EmailService: Template 'password_reset_success' not found in database. Please run seeder.");
            return false;
        }

        return $this->sendEmailInternal($email, $subject, $html, $text);
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
        // Try to use template from database
        $template = $this->emailTemplateModel->findByKey('notification');
        if ($template) {
            $subject = $template['subject'] ?? ($this->t('notification_email_subject') . ': ' . $title);
            // Replace subject placeholders
            $subject = str_replace('{title}', $title, $subject);
            $html = $this->renderTemplate('notification', [
                'nome' => $nome,
                'notificationType' => $notificationType,
                'title' => $title,
                'message' => $message,
                'link' => $link ?? '',
                'icon' => $this->getNotificationIcon($notificationType),
                'baseUrl' => BASE_URL
            ]);
            $text = $this->renderTextTemplate('notification', [
                'nome' => $nome,
                'notificationType' => $notificationType,
                'title' => $title,
                'message' => $message,
                'link' => $link ?? '',
                'baseUrl' => BASE_URL
            ]);
        } else {
            error_log("EmailService: Template 'notification' not found in database. Please run seeder.");
            return false;
        }

        return $this->sendEmail($email, $subject, $html, $text, 'notification', $userId);
    }

    /**
     * Send message email
     */
    public function sendMessageEmail(string $email, string $nome, string $senderName, string $subject, string $messageContent, string $link, bool $isAnnouncement = false, ?int $userId = null): bool
    {
        // Try to use template from database
        $template = $this->emailTemplateModel->findByKey('message');
        if ($template) {
            $messageType = $isAnnouncement ? 'Anúncio' : 'Mensagem Privada';
            $buttonText = $isAnnouncement ? 'Ver Anúncio' : 'Responder Mensagem';
            $emailSubject = $template['subject'] ?? ($isAnnouncement
                ? $this->t('message_email_announcement_title') . ': ' . $subject
                : $this->t('message_email_private_title') . ': ' . $subject);
            // Replace subject placeholders
            $emailSubject = str_replace('{messageType}', $messageType, $emailSubject);
            $emailSubject = str_replace('{subject}', $subject, $emailSubject);

            $html = $this->renderTemplate('message', [
                'nome' => $nome,
                'senderName' => $senderName,
                'subject' => $subject,
                'messageContent' => $messageContent,
                'link' => $link,
                'isAnnouncement' => $isAnnouncement ? 'true' : 'false',
                'messageType' => $messageType,
                'buttonText' => $buttonText,
                'baseUrl' => BASE_URL
            ]);
            $text = $this->renderTextTemplate('message', [
                'nome' => $nome,
                'senderName' => $senderName,
                'subject' => $subject,
                'messageContent' => strip_tags($messageContent),
                'link' => $link,
                'isAnnouncement' => $isAnnouncement ? 'true' : 'false',
                'messageType' => $messageType,
                'buttonText' => $buttonText,
                'baseUrl' => BASE_URL
            ]);

            if (empty($html)) {
                error_log("EmailService: Failed to render 'message' template. Check base_layout exists.");
                return false;
            }
        } else {
            error_log("EmailService: Template 'message' not found in database. Please run seeder.");
            return false;
        }

        return $this->sendEmail($email, $emailSubject, $html, $text, 'message', $userId);
    }

    /**
     * Send fraction assignment email
     */
    public function sendFractionAssignmentEmail(string $email, string $nome, string $condominiumName, string $fractionIdentifier, string $link, ?int $userId = null): bool
    {
        // Try to use template from database
        $template = $this->emailTemplateModel->findByKey('fraction_assignment');
        if ($template) {
            $subject = $template['subject'] ?? ($this->t('fraction_assignment_subject') . ': ' . $fractionIdentifier);
            // Replace subject placeholders
            $subject = str_replace('{fractionIdentifier}', $fractionIdentifier, $subject);
            $html = $this->renderTemplate('fraction_assignment', [
                'nome' => $nome,
                'condominiumName' => $condominiumName,
                'fractionIdentifier' => $fractionIdentifier,
                'link' => $link,
                'baseUrl' => BASE_URL
            ]);
            $text = $this->renderTextTemplate('fraction_assignment', [
                'nome' => $nome,
                'condominiumName' => $condominiumName,
                'fractionIdentifier' => $fractionIdentifier,
                'link' => $link,
                'baseUrl' => BASE_URL
            ]);
        } else {
            error_log("EmailService: Template 'fraction_assignment' not found in database. Please run seeder.");
            return false;
        }

        return $this->sendEmail($email, $subject, $html, $text, 'notification', $userId);
    }

    /**
     * Render email template from database
     * Combines base layout with specific template body
     */
    public function renderTemplate(string $templateKey, array $data = []): string
    {
        // Get base layout
        $baseLayout = $this->emailTemplateModel->getBaseLayout();
        if (!$baseLayout) {
            // Fallback: return empty if base layout not found
            error_log("EmailService: Base layout not found, using fallback");
            return '';
        }

        // Get specific template
        $template = $this->emailTemplateModel->findByKey($templateKey);
        if (!$template) {
            // Fallback: use hardcoded templates
            error_log("EmailService: Template '{$templateKey}' not found in database, using fallback");
            return '';
        }

        // Replace fields in body
        $body = $template['html_body'];
        foreach ($data as $key => $value) {
            // Don't escape HTML for specific fields that should contain HTML
            $htmlFields = ['logoUrl', 'messageContent', 'body'];
            if (in_array($key, $htmlFields)) {
                $body = str_replace('{' . $key . '}', $value, $body);
            } else {
                // Escape HTML but preserve line breaks
                $escapedValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $body = str_replace('{' . $key . '}', $escapedValue, $body);
            }
        }

        // Replace fields in base layout
        $html = $baseLayout['html_body'];
        $html = str_replace('{body}', $body, $html);

        // Replace base layout fields (these should not be escaped as they may contain HTML)
        $html = str_replace('{baseUrl}', BASE_URL, $html);
        $html = str_replace('{logoUrl}', $this->getLogoInline(), $html);
        $html = str_replace('{currentYear}', date('Y'), $html);
        $html = str_replace('{companyName}', htmlspecialchars($this->fromName, ENT_QUOTES, 'UTF-8'), $html);

        // Replace subject if present
        if ($template['subject']) {
            $subject = $template['subject'];
            foreach ($data as $key => $value) {
                // Subject should always be escaped
                $subject = str_replace('{' . $key . '}', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $subject);
            }
            $html = str_replace('{subject}', $subject, $html);
        } else {
            $html = str_replace('{subject}', '', $html);
        }

        return $html;
    }

    /**
     * Render text template from database
     */
    public function renderTextTemplate(string $templateKey, array $data = []): string
    {
        // Get base layout
        $baseLayout = $this->emailTemplateModel->getBaseLayout();
        if (!$baseLayout) {
            return '';
        }

        // Get specific template
        $template = $this->emailTemplateModel->findByKey($templateKey);
        if (!$template) {
            return '';
        }

        // Replace fields in body
        $body = $template['text_body'] ?? strip_tags($template['html_body']);
        foreach ($data as $key => $value) {
            $body = str_replace('{' . $key . '}', strip_tags($value), $body);
        }

        // Replace fields in base layout text
        $text = $baseLayout['text_body'] ?? '';
        $text = str_replace('{body}', $body, $text);
        $text = str_replace('{baseUrl}', BASE_URL, $text);
        $text = str_replace('{currentYear}', date('Y'), $text);
        $text = str_replace('{companyName}', $this->fromName, $text);

        return $text;
    }


    /**
     * Get notification icon based on type
     */
    private function getNotificationIcon(string $notificationType): string
    {
        $icons = [
            'occurrence' => '⚠️',
            'fee_overdue' => '💰',
            'assembly' => '📋',
            'vote' => '🗳️',
            'occurrence_comment' => '💬',
            'default' => '🔔'
        ];
        return $icons[$notificationType] ?? $icons['default'];
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
