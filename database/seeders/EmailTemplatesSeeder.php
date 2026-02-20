<?php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../database.php';

use App\Models\EmailTemplate;

class EmailTemplatesSeeder
{
    protected $emailTemplateModel;

    public function __construct()
    {
        $this->emailTemplateModel = new EmailTemplate();
    }

    public function run(): void
    {
        global $db;
        
        if (!$db) {
            throw new \Exception("Database connection not available");
        }

        // Template Base Layout - sempre atualizar para garantir que está atualizado
        $baseLayoutHtml = '<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; 
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
            content: \'\';
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
        .logo-section {
            background: #ffffff;
            padding: 30px 30px 20px 30px;
            text-align: center;
        }
        .logo-container {
            margin-bottom: 0;
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
            .logo-section { padding: 25px 20px 15px 20px; }
            .header, .content { padding: 25px 20px; }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="logo-section">
            <div class="logo-container">
                <img src="https://omeupredio.com/assets/images/logo.png" alt="O Meu Prédio" width="200" style="display: block; max-width: 200px; height: auto; margin: 0 auto; border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic;" />
            </div>
        </div>
        <div class="header">
            <div class="header-content">
                <h1>{subject}</h1>
            </div>
        </div>
        <div class="content">
            {body}
        </div>
        <div class="footer">
            <p>Feito com ❤️ para gestão de condomínios</p>
            <p>© {companyName} {currentYear} - Todos os direitos reservados</p>
        </div>
    </div>
</body>
</html>';

            $baseLayoutText = '{body}

---
Feito com ❤️ para gestão de condomínios
© {companyName} {currentYear} - Todos os direitos reservados';

            $existingTemplate = $this->emailTemplateModel->findByKey('base_layout');
            
            if ($existingTemplate) {
                // Atualizar template existente
                $this->emailTemplateModel->update($existingTemplate['id'], [
                    'html_body' => $baseLayoutHtml,
                    'text_body' => $baseLayoutText
                ]);
            } else {
                // Criar novo template
                $this->emailTemplateModel->create([
                    'template_key' => 'base_layout',
                    'name' => 'Layout Base (Header/Footer)',
                    'description' => 'Template base com header e footer usado em todos os emails. Contém o placeholder {body} onde o conteúdo específico é inserido.',
                    'subject' => null,
                    'html_body' => $baseLayoutHtml,
                    'text_body' => $baseLayoutText,
                    'available_fields' => [
                        ['key' => '{body}', 'description' => 'Conteúdo específico do email (obrigatório)', 'required' => true],
                        ['key' => '{subject}', 'description' => 'Assunto/título do email', 'required' => false],
                        ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false],
                        ['key' => '{logoUrl}', 'description' => 'URL do logo', 'required' => false],
                        ['key' => '{currentYear}', 'description' => 'Ano atual', 'required' => false],
                        ['key' => '{companyName}', 'description' => 'Nome da empresa', 'required' => false]
                    ],
                    'is_base_layout' => true,
                    'is_active' => true
                ]);
            }

        // Templates específicos - apenas o body (sem header/footer)
        $templates = [
            [
                'template_key' => 'welcome',
                'name' => 'Email de Boas-vindas',
                'description' => 'Email enviado quando um novo usuário se registra',
                'subject' => 'Bem-vindo ao O Meu Prédio!',
                'html_body' => '<div class="greeting">
    <h2>Olá {nome}!</h2>
    <p>Bem-vindo ao O Meu Prédio! A sua conta foi criada com sucesso.</p>
    <p><strong>Próximo passo:</strong> Confirme o seu email para ativar a sua conta.</p>
    <div style="text-align: center; margin: 30px 0;">
        <a href="{verificationUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Confirmar Email</a>
    </div>
    <p><strong>O que acontece a seguir?</strong></p>
    <ul>
        <li>✅ Clique no botão acima para confirmar o seu email</li>
        <li>⏳ Aguarde a aprovação do administrador</li>
        <li>🎵 Receberá uma notificação quando aprovado</li>
        <li>🚀 Poderá começar a usar a plataforma</li>
    </ul>
    <p>Se o botão não funcionar, copie e cole este link no seu navegador:</p>
    <p style="word-break: break-all; background: #f5f5f5; padding: 10px; border-radius: 5px; color: #1a1f3a;">{verificationUrl}</p>
</div>',
                'text_body' => 'Bem-vindo ao O Meu Prédio!

Olá {nome}!

Bem-vindo ao O Meu Prédio! A sua conta foi criada com sucesso.

Próximo passo: Confirme o seu email para ativar a sua conta.
Link: {verificationUrl}

O que acontece a seguir?
- Clique no link acima para confirmar o seu email
- Aguarde a aprovação do administrador
- Receberá uma notificação quando aprovado
- Poderá começar a usar a plataforma',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{verificationUrl}', 'description' => 'URL de verificação do email', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'approval',
                'name' => 'Notificação de Aprovação',
                'description' => 'Email enviado quando um usuário é aprovado',
                'subject' => 'Conta Aprovada - Bem-vindo!',
                'html_body' => '<div class="greeting">
    <h2>Parabéns {nome}!</h2>
    <p>A sua conta foi aprovada e está agora ativa.</p>
    <div style="text-align: center; margin: 30px 0;">
        <a href="{baseUrl}login" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Fazer Login</a>
    </div>
    <p><strong>O que pode fazer agora?</strong></p>
    <ul>
        <li>🎵 Aceder à plataforma</li>
        <li>🎤 Gerir o seu condomínio</li>
        <li>🎪 Ver documentos e informações</li>
        <li>👥 Comunicar com outros condóminos</li>
    </ul>
</div>',
                'text_body' => 'Conta Aprovada - Bem-vindo!

Parabéns {nome}!

A sua conta foi aprovada e está agora ativa.

Fazer Login: {baseUrl}login

O que pode fazer agora?
- Aceder à plataforma
- Gerir o seu condomínio
- Ver documentos e informações
- Comunicar com outros condóminos',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'password_reset',
                'name' => 'Redefinição de Senha',
                'description' => 'Email enviado quando usuário solicita redefinição de senha',
                'subject' => 'Redefinir Senha',
                'html_body' => '<div class="greeting">{nome}!</div>
<div class="message">Recebemos uma solicitação para redefinir a senha da sua conta.</div>
<div style="text-align: center; margin: 35px 0;">
    <a href="{resetUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Redefinir Senha</a>
</div>
<div class="warning">
    <div class="warning-title">
        <span>⚠️</span>
        <span>Importante</span>
    </div>
    <ul>
        <li>Este link expira em 1 hora</li>
        <li>Se você não solicitou esta alteração, ignore este email</li>
        <li>A sua senha não será alterada até que você clique no link acima</li>
    </ul>
</div>
<div class="link-box">
    <div class="link-label">Se o botão não funcionar, copie e cole este link no seu navegador:</div>
    {resetUrl}
</div>',
                'text_body' => 'Olá {nome}!

Recebemos uma solicitação para redefinir a senha da sua conta.

Redefinir Senha: {resetUrl}

⚠️ Importante:
- Este link expira em 1 hora
- Se você não solicitou esta alteração, ignore este email
- A sua senha não será alterada até que você clique no link acima',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{resetUrl}', 'description' => 'URL de redefinição de senha', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'password_reset_success',
                'name' => 'Sucesso na Redefinição de Senha',
                'description' => 'Email enviado após redefinição de senha bem-sucedida',
                'subject' => 'Senha Redefinida com Sucesso',
                'html_body' => '<div class="greeting">{nome}!</div>
<div class="message">A sua senha foi redefinida com sucesso.</div>
<div class="success-box">
    <strong>
        <span>✅</span>
        <span>Segurança:</span>
    </strong>
    <p>Se você não realizou esta alteração, entre em contacto connosco imediatamente.</p>
</div>
<div style="text-align: center; margin: 35px 0;">
    <a href="{baseUrl}login" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Fazer Login</a>
</div>
<div class="security-note">
    <strong>Se não foi você:</strong>
    <p>Se você não solicitou esta alteração, entre em contacto connosco imediatamente para proteger a sua conta.</p>
</div>',
                'text_body' => 'Olá {nome}!

A sua senha foi redefinida com sucesso.

✅ Segurança: Se você não realizou esta alteração, entre em contacto connosco imediatamente.

Fazer Login: {baseUrl}login

Se não foi você:
Se você não solicitou esta alteração, entre em contacto connosco imediatamente para proteger a sua conta.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'new_user_notification',
                'name' => 'Notificação de Novo Usuário',
                'description' => 'Email enviado para admins quando novo usuário se registra',
                'subject' => 'Novo Utilizador Registado',
                'html_body' => '<div class="greeting">
    <h2>Novo Utilizador Registado</h2>
    <p>Um novo utilizador registou-se e aguarda aprovação.</p>
</div>
<div class="user-info">
    <p><strong>Nome:</strong> {userName}</p>
    <p><strong>Email:</strong> {userEmail}</p>
    <p><strong>Data:</strong> ' . date('d/m/Y H:i') . '</p>
    <p><strong>Status:</strong> <span style="color: #F98E13; font-weight: bold;">Pendente</span></p>
</div>
<p><strong>Próximo passo:</strong> Aprove ou rejeite este utilizador através do painel de administração.</p>
<div style="text-align: center; margin: 30px 0;">
    <a href="{adminUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Utilizadores</a>
</div>
<p><strong>O que pode fazer:</strong></p>
<ul>
    <li>✅ Aprovar o utilizador</li>
    <li>❌ Rejeitar o utilizador</li>
    <li>📧 Contactar o utilizador</li>
    <li>👥 Ver detalhes completos</li>
</ul>',
                'text_body' => 'Novo Utilizador Registado

Um novo utilizador registou-se e aguarda aprovação.

Nome: {userName}
Email: {userEmail}
Data: ' . date('d/m/Y H:i') . '
Status: Pendente

Próximo passo: Aprove ou rejeite este utilizador através do painel de administração.

Ver Utilizadores: {adminUrl}

O que pode fazer:
- Aprovar o utilizador
- Rejeitar o utilizador
- Contactar o utilizador
- Ver detalhes completos',
                'available_fields' => [
                    ['key' => '{userEmail}', 'description' => 'Email do novo usuário', 'required' => true],
                    ['key' => '{userName}', 'description' => 'Nome do novo usuário', 'required' => true],
                    ['key' => '{userId}', 'description' => 'ID do usuário', 'required' => false],
                    ['key' => '{adminUrl}', 'description' => 'URL do painel de administração', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'notification',
                'name' => 'Notificações Gerais',
                'description' => 'Template para notificações gerais do sistema',
                'subject' => 'Nova Notificação: {title}',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="notification-title">
    <span>{icon}</span>
    <span>{title}</span>
</div>
<div class="message">{message}</div>
{if:link}
<div class="button-container">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Detalhes</a>
</div>
{/if:link}',
                'text_body' => 'Olá {nome}!

{title}

{message}

{if:link}Ver detalhes: {link}{/if:link}',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do destinatário', 'required' => true],
                    ['key' => '{notificationType}', 'description' => 'Tipo de notificação (assembly, occurrence, etc)', 'required' => false],
                    ['key' => '{title}', 'description' => 'Título da notificação', 'required' => true],
                    ['key' => '{message}', 'description' => 'Mensagem da notificação', 'required' => true],
                    ['key' => '{link}', 'description' => 'Link para mais detalhes (opcional)', 'required' => false],
                    ['key' => '{icon}', 'description' => 'Ícone da notificação', 'required' => false],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'message',
                'name' => 'Mensagens Privadas/Anúncios',
                'description' => 'Template para mensagens privadas e anúncios',
                'subject' => '{messageType}: {subject}',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message-info">
    <div class="message-info-row">
        <span class="message-info-label">De:</span>
        <span class="message-info-value">{senderName}</span>
    </div>
    <div class="message-info-row">
        <span class="message-info-label">Assunto:</span>
        <span class="message-info-value">{subject}</span>
    </div>
    <div class="message-info-row">
        <span class="message-info-label">Tipo:</span>
        <span class="message-info-value">{messageType}</span>
    </div>
</div>
<div class="message-content">{messageContent}</div>
<div class="button-container">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">{buttonText}</a>
</div>',
                'text_body' => 'Olá {nome}!

Você recebeu uma nova {messageType}.

De: {senderName}
Assunto: {subject}

{messageContent}

{buttonText}: {link}',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do destinatário', 'required' => true],
                    ['key' => '{senderName}', 'description' => 'Nome do remetente', 'required' => true],
                    ['key' => '{subject}', 'description' => 'Assunto da mensagem', 'required' => true],
                    ['key' => '{messageContent}', 'description' => 'Conteúdo da mensagem (pode conter HTML)', 'required' => true],
                    ['key' => '{link}', 'description' => 'Link para responder/ver mensagem', 'required' => true],
                    ['key' => '{isAnnouncement}', 'description' => 'Se é anúncio (true/false)', 'required' => false],
                    ['key' => '{messageType}', 'description' => 'Tipo: Anúncio ou Mensagem Privada', 'required' => false],
                    ['key' => '{buttonText}', 'description' => 'Texto do botão (Ver Anúncio/Responder Mensagem)', 'required' => false],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'fraction_assignment',
                'name' => 'Atribuição de Fração',
                'description' => 'Email enviado quando uma fração é atribuída a um usuário',
                'subject' => 'Fração Atribuída: {fractionIdentifier}',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">Uma fração foi atribuída à sua conta.</div>
<div class="info-box">
    <div class="info-row">
        <span class="info-label">Condomínio:</span>
        <span class="info-value">{condominiumName}</span>
    </div>
    <div class="info-row">
        <span class="info-label">Fração:</span>
        <span class="info-value">{fractionIdentifier}</span>
    </div>
</div>
<div class="button-container">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Fração</a>
</div>',
                'text_body' => 'Olá {nome}!

Uma fração foi atribuída à sua conta.

Condomínio: {condominiumName}
Fração: {fractionIdentifier}

Ver fração: {link}',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{condominiumName}', 'description' => 'Nome do condomínio', 'required' => true],
                    ['key' => '{fractionIdentifier}', 'description' => 'Identificador da fração', 'required' => true],
                    ['key' => '{link}', 'description' => 'Link para ver a fração', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'subscription_renewal_reminder',
                'name' => 'Aviso de Renovação de Subscrição',
                'description' => 'Email enviado antes da subscrição expirar',
                'subject' => 'Renovação de Subscrição - {daysLeft} dia(s) restante(s)',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">
    <p>A sua subscrição do plano <strong>{planName}</strong> expira em <strong>{daysLeft} dia(s)</strong> ({expirationDate}).</p>
    <p><strong>Valor mensal:</strong> €{monthlyPrice}</p>
    <p>Para renovar a sua subscrição e evitar o bloqueio do acesso, efetue o pagamento através do link abaixo.</p>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{link}" style="display: inline-block; padding: 15px 30px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">Renovar Subscrição</a>
</div>
<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>⚠️ Importante:</strong> Se não renovar até {expirationDate}, o acesso à gestão dos condomínios será bloqueado.</p>
</div>',
                'text_body' => 'Olá {nome}!

A sua subscrição do plano {planName} expira em {daysLeft} dia(s) ({expirationDate}).

Valor mensal: €{monthlyPrice}

Para renovar a sua subscrição e evitar o bloqueio do acesso, efetue o pagamento através do link abaixo.

Renovar Subscrição: {link}

⚠️ Importante: Se não renovar até {expirationDate}, o acesso à gestão dos condomínios será bloqueado.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{planName}', 'description' => 'Nome do plano', 'required' => true],
                    ['key' => '{expirationDate}', 'description' => 'Data de expiração (formato: dd/mm/yyyy)', 'required' => true],
                    ['key' => '{daysLeft}', 'description' => 'Dias restantes até expiração', 'required' => true],
                    ['key' => '{monthlyPrice}', 'description' => 'Preço mensal (número)', 'required' => true],
                    ['key' => '{link}', 'description' => 'Link para renovar subscrição', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'subscription_expired',
                'name' => 'Subscrição Expirada',
                'description' => 'Email enviado quando subscrição expira',
                'subject' => 'Subscrição Expirada',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">
    <p>A sua subscrição do plano <strong>{planName}</strong> expirou.</p>
    <div style="background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;">
        <p style="margin: 0;"><strong>⚠️ Acesso Bloqueado:</strong> O acesso à gestão dos condomínios foi bloqueado até efetuar o pagamento.</p>
    </div>
    <p>Para reativar a sua subscrição, efetue o pagamento através do link abaixo.</p>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{link}" style="display: inline-block; padding: 15px 30px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">Renovar Subscrição</a>
</div>
<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>ℹ️ Nota:</strong> Para reativar uma subscrição expirada, será necessário pagar os meses em atraso mais o mês atual.</p>
</div>',
                'text_body' => 'Olá {nome}!

A sua subscrição do plano {planName} expirou.

⚠️ Acesso Bloqueado: O acesso à gestão dos condomínios foi bloqueado até efetuar o pagamento.

Para reativar a sua subscrição, efetue o pagamento através do link abaixo.

Renovar Subscrição: {link}

ℹ️ Nota: Para reativar uma subscrição expirada, será necessário pagar os meses em atraso mais o mês atual.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{planName}', 'description' => 'Nome do plano', 'required' => true],
                    ['key' => '{link}', 'description' => 'Link para renovar subscrição', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'assembly_convocation',
                'name' => 'Convocatória de Assembleia',
                'description' => 'Email enviado para convocar condóminos para uma assembleia',
                'subject' => 'Convocatória de Assembleia: {assemblyTitle}',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">
    <p>Está convocado(a) para participar na <strong>{assemblyTitle}</strong>.</p>
    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Data:</span>
            <span class="info-value">{assemblyDate}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Hora:</span>
            <span class="info-value">{assemblyTime}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Local:</span>
            <span class="info-value">{assemblyLocation}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Condomínio:</span>
            <span class="info-value">{condominiumName}</span>
        </div>
    </div>
    <p><strong>Ordem de Trabalhos:</strong></p>
    <div style="background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin: 15px 0;">
        {agenda}
    </div>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Detalhes da Assembleia</a>
</div>
<div style="background-color: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>ℹ️ Importante:</strong> A sua presença é fundamental para a tomada de decisões do condomínio.</p>
</div>',
                'text_body' => 'Olá {nome}!

Está convocado(a) para participar na {assemblyTitle}.

Data: {assemblyDate}
Hora: {assemblyTime}
Local: {assemblyLocation}
Condomínio: {condominiumName}

Ordem de Trabalhos:
{agenda}

Ver detalhes: {link}

ℹ️ Importante: A sua presença é fundamental para a tomada de decisões do condomínio.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do condómino', 'required' => true],
                    ['key' => '{assemblyTitle}', 'description' => 'Título da assembleia', 'required' => true],
                    ['key' => '{assemblyDate}', 'description' => 'Data da assembleia', 'required' => true],
                    ['key' => '{assemblyTime}', 'description' => 'Hora da assembleia', 'required' => true],
                    ['key' => '{assemblyLocation}', 'description' => 'Local da assembleia', 'required' => true],
                    ['key' => '{condominiumName}', 'description' => 'Nome do condomínio', 'required' => true],
                    ['key' => '{agenda}', 'description' => 'Ordem de trabalhos (pode conter HTML)', 'required' => false],
                    ['key' => '{link}', 'description' => 'Link para ver detalhes da assembleia', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'payment_confirmed',
                'name' => 'Pagamento Confirmado',
                'description' => 'Email enviado quando um pagamento é confirmado',
                'subject' => 'Pagamento Confirmado - {amount}€',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">
    <p>O seu pagamento foi confirmado com sucesso.</p>
    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Valor:</span>
            <span class="info-value">{amount}€</span>
        </div>
        <div class="info-row">
            <span class="info-label">Método:</span>
            <span class="info-value">{paymentMethod}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Data:</span>
            <span class="info-value">{paymentDate}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Referência:</span>
            <span class="info-value">{reference}</span>
        </div>
    </div>
    <p><strong>Detalhes do pagamento:</strong></p>
    <p>{description}</p>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Recibo</a>
</div>
<div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>✅ Pagamento Processado:</strong> O seu pagamento foi processado e registado no sistema.</p>
</div>',
                'text_body' => 'Olá {nome}!

O seu pagamento foi confirmado com sucesso.

Valor: {amount}€
Método: {paymentMethod}
Data: {paymentDate}
Referência: {reference}

Detalhes: {description}

Ver recibo: {link}

✅ Pagamento Processado: O seu pagamento foi processado e registado no sistema.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{amount}', 'description' => 'Valor do pagamento', 'required' => true],
                    ['key' => '{paymentMethod}', 'description' => 'Método de pagamento', 'required' => true],
                    ['key' => '{paymentDate}', 'description' => 'Data do pagamento', 'required' => true],
                    ['key' => '{reference}', 'description' => 'Referência do pagamento', 'required' => false],
                    ['key' => '{description}', 'description' => 'Descrição do pagamento', 'required' => false],
                    ['key' => '{link}', 'description' => 'Link para ver recibo', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'invoice_generated',
                'name' => 'Fatura Gerada',
                'description' => 'Email enviado quando uma fatura é gerada',
                'subject' => 'Nova Fatura Gerada - {invoiceNumber}',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">
    <p>Uma nova fatura foi gerada para si.</p>
    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Número da Fatura:</span>
            <span class="info-value">{invoiceNumber}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Valor:</span>
            <span class="info-value">{amount}€</span>
        </div>
        <div class="info-row">
            <span class="info-label">Data de Emissão:</span>
            <span class="info-value">{issueDate}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Data de Vencimento:</span>
            <span class="info-value">{dueDate}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Plano:</span>
            <span class="info-value">{planName}</span>
        </div>
    </div>
    <p><strong>Descrição:</strong></p>
    <p>{description}</p>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Fatura</a>
</div>
<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>⚠️ Atenção:</strong> Por favor, efetue o pagamento até {dueDate} para evitar interrupções no serviço.</p>
</div>',
                'text_body' => 'Olá {nome}!

Uma nova fatura foi gerada para si.

Número: {invoiceNumber}
Valor: {amount}€
Data de Emissão: {issueDate}
Data de Vencimento: {dueDate}
Plano: {planName}

Descrição: {description}

Ver fatura: {link}

⚠️ Atenção: Por favor, efetue o pagamento até {dueDate} para evitar interrupções no serviço.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{invoiceNumber}', 'description' => 'Número da fatura', 'required' => true],
                    ['key' => '{amount}', 'description' => 'Valor da fatura', 'required' => true],
                    ['key' => '{issueDate}', 'description' => 'Data de emissão', 'required' => true],
                    ['key' => '{dueDate}', 'description' => 'Data de vencimento', 'required' => true],
                    ['key' => '{planName}', 'description' => 'Nome do plano', 'required' => false],
                    ['key' => '{description}', 'description' => 'Descrição da fatura', 'required' => false],
                    ['key' => '{link}', 'description' => 'Link para ver fatura', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'subscription_activated',
                'name' => 'Subscrição Ativada',
                'description' => 'Email enviado quando uma subscrição é ativada',
                'subject' => 'Subscrição Ativada - {planName}',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">
    <p>A sua subscrição foi ativada com sucesso!</p>
    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Plano:</span>
            <span class="info-value">{planName}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Data de Ativação:</span>
            <span class="info-value">{activationDate}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Próxima Renovação:</span>
            <span class="info-value">{nextRenewalDate}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Valor Mensal:</span>
            <span class="info-value">{monthlyPrice}€</span>
        </div>
    </div>
    <p><strong>Benefícios do seu plano:</strong></p>
    <ul>
        {features}
    </ul>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Aceder à Plataforma</a>
</div>
<div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>✅ Bem-vindo!</strong> Agora pode começar a usar todas as funcionalidades do seu plano.</p>
</div>',
                'text_body' => 'Olá {nome}!

A sua subscrição foi ativada com sucesso!

Plano: {planName}
Data de Ativação: {activationDate}
Próxima Renovação: {nextRenewalDate}
Valor Mensal: {monthlyPrice}€

Benefícios do seu plano:
{features}

Aceder à plataforma: {link}

✅ Bem-vindo! Agora pode começar a usar todas as funcionalidades do seu plano.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{planName}', 'description' => 'Nome do plano', 'required' => true],
                    ['key' => '{activationDate}', 'description' => 'Data de ativação', 'required' => true],
                    ['key' => '{nextRenewalDate}', 'description' => 'Data da próxima renovação', 'required' => true],
                    ['key' => '{monthlyPrice}', 'description' => 'Preço mensal', 'required' => true],
                    ['key' => '{features}', 'description' => 'Lista de funcionalidades (pode conter HTML)', 'required' => false],
                    ['key' => '{link}', 'description' => 'Link para aceder à plataforma', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'subscription_plan_changed',
                'name' => 'Plano Alterado',
                'description' => 'Email enviado quando o plano de subscrição é alterado',
                'subject' => 'Plano Alterado - {newPlanName}',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">
    <p>O seu plano de subscrição foi alterado com sucesso.</p>
    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Plano Anterior:</span>
            <span class="info-value">{oldPlanName}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Novo Plano:</span>
            <span class="info-value">{newPlanName}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Data da Alteração:</span>
            <span class="info-value">{changeDate}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Novo Valor Mensal:</span>
            <span class="info-value">{newMonthlyPrice}€</span>
        </div>
    </div>
    <p><strong>Novas funcionalidades disponíveis:</strong></p>
    <ul>
        {newFeatures}
    </ul>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{link}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Detalhes do Plano</a>
</div>
<div style="background-color: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>ℹ️ Nota:</strong> As alterações no plano já estão ativas. Pode começar a usar as novas funcionalidades imediatamente.</p>
</div>',
                'text_body' => 'Olá {nome}!

O seu plano de subscrição foi alterado com sucesso.

Plano Anterior: {oldPlanName}
Novo Plano: {newPlanName}
Data da Alteração: {changeDate}
Novo Valor Mensal: {newMonthlyPrice}€

Novas funcionalidades disponíveis:
{newFeatures}

Ver detalhes: {link}

ℹ️ Nota: As alterações no plano já estão ativas. Pode começar a usar as novas funcionalidades imediatamente.',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do usuário', 'required' => true],
                    ['key' => '{oldPlanName}', 'description' => 'Nome do plano anterior', 'required' => true],
                    ['key' => '{newPlanName}', 'description' => 'Nome do novo plano', 'required' => true],
                    ['key' => '{changeDate}', 'description' => 'Data da alteração', 'required' => true],
                    ['key' => '{newMonthlyPrice}', 'description' => 'Novo preço mensal', 'required' => true],
                    ['key' => '{newFeatures}', 'description' => 'Lista de novas funcionalidades (pode conter HTML)', 'required' => false],
                    ['key' => '{link}', 'description' => 'Link para ver detalhes do plano', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'demo_access',
                'name' => 'Acesso à Demonstração',
                'description' => 'Email enviado com link único de acesso à demonstração',
                'subject' => 'Acesso à Demonstração - O Meu Prédio',
                'html_body' => '<div class="greeting">Olá!</div>
<div class="message">
    <p>Solicitou acesso à demonstração da plataforma <strong>O Meu Prédio</strong>.</p>
    <p>Clique no botão abaixo para aceder à demonstração e explorar todas as funcionalidades da plataforma.</p>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{accessUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Aceder à Demonstração</a>
</div>
<div class="warning">
    <div class="warning-title">
        <span>⚠️</span>
        <span>Importante</span>
    </div>
    <ul>
        <li>Este link é único e válido por 24 horas</li>
        <li>O link só pode ser usado uma vez</li>
        <li>Se precisar de novo acesso, solicite novamente através do website</li>
    </ul>
</div>
<div class="link-box">
    <div class="link-label">Se o botão não funcionar, copie e cole este link no seu navegador:</div>
    {accessUrl}
</div>
<div style="background-color: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>ℹ️ Sobre a Demonstração:</strong> A demonstração contém dados fictícios para que possa explorar todas as funcionalidades. Todas as alterações são repostas automaticamente.</p>
</div>',
                'text_body' => 'Olá!

Solicitou acesso à demonstração da plataforma O Meu Prédio.

Clique no link abaixo para aceder à demonstração e explorar todas as funcionalidades da plataforma.

Aceder à Demonstração: {accessUrl}

⚠️ Importante:
- Este link é único e válido por 24 horas
- O link só pode ser usado uma vez
- Se precisar de novo acesso, solicite novamente através do website

ℹ️ Sobre a Demonstração: A demonstração contém dados fictícios para que possa explorar todas as funcionalidades. Todas as alterações são repostas automaticamente.',
                'available_fields' => [
                    ['key' => '{accessUrl}', 'description' => 'URL de acesso único à demo (com token)', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'pilot_email_verification',
                'name' => 'Verificação de email - Inscrição User Piloto',
                'description' => 'Email com link para confirmar o email na inscrição como user piloto (anti-spam)',
                'subject' => 'Confirme o seu email - Inscrição User Piloto',
                'html_body' => '<div class="greeting">Olá!</div>
<div class="message">
    <p>Obrigado por demonstrar interesse em ser utilizador piloto do <strong>O Meu Prédio</strong>!</p>
    <p>Para confirmar que este email é válido e concluir a sua inscrição, clique no botão abaixo.</p>
    <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
        <p style="margin: 0;"><strong>⚠️ Um passo em falta:</strong> Só após confirmar o email a sua inscrição será registada e entraremos em contacto.</p>
    </div>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{verificationUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Confirmar o meu email</a>
</div>
<div class="warning">
    <ul>
        <li>Este link é válido por 24 horas</li>
        <li>Se não solicitou esta inscrição, pode ignorar este email</li>
    </ul>
</div>
<div class="link-box">
    <div class="link-label">Se o botão não funcionar, copie e cole no navegador:</div>
    {verificationUrl}
</div>',
                'text_body' => 'Olá!

Obrigado por demonstrar interesse em ser utilizador piloto do O Meu Prédio!

Para confirmar que este email é válido e concluir a sua inscrição, abra o link abaixo no navegador:

{verificationUrl}

Este link é válido por 24 horas. Se não solicitou esta inscrição, pode ignorar este email.',
                'available_fields' => [
                    ['key' => '{email}', 'description' => 'Email do utilizador', 'required' => false],
                    ['key' => '{verificationUrl}', 'description' => 'URL para confirmar o email', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'pilot_signup_thank_you',
                'name' => 'Agradecimento - Inscrição User Piloto',
                'description' => 'Email de agradecimento enviado quando um utilizador se inscreve como user piloto',
                'subject' => 'Obrigado pelo seu interesse - O Meu Prédio',
                'html_body' => '<div class="greeting">Olá!</div>
<div class="message">
    <p>Obrigado por se inscrever como utilizador piloto do <strong>O Meu Prédio</strong>!</p>
    <p>A sua inscrição foi recebida com sucesso e estamos muito entusiasmados por ter o seu interesse em participar nesta fase de testes.</p>
    <div style="background-color: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0;">
        <p style="margin: 0;"><strong>ℹ️ O que acontece a seguir?</strong></p>
        <ul style="margin: 10px 0 0 20px; padding: 0;">
            <li>Analisaremos o seu pedido</li>
            <li>Entraremos em contacto em breve através deste email</li>
            <li>Receberá informações sobre como participar na fase de testes</li>
            <li>Beneficiará de descontos especiais no lançamento oficial</li>
        </ul>
    </div>
    <p><strong>Porquê participar como utilizador piloto?</strong></p>
    <ul>
        <li>✅ Acesso antecipado à plataforma</li>
        <li>✅ Descontos exclusivos no lançamento</li>
        <li>✅ Oportunidade de influenciar o desenvolvimento</li>
        <li>✅ Suporte prioritário durante a fase de testes</li>
    </ul>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{baseUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Visitar Website</a>
</div>
<div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>✅ Inscrição Confirmada:</strong> O seu email foi registado e entraremos em contacto em breve.</p>
</div>',
                'text_body' => 'Olá!

Obrigado por se inscrever como utilizador piloto do O Meu Prédio!

A sua inscrição foi recebida com sucesso e estamos muito entusiasmados por ter o seu interesse em participar nesta fase de testes.

ℹ️ O que acontece a seguir?
- Analisaremos o seu pedido
- Entraremos em contacto em breve através deste email
- Receberá informações sobre como participar na fase de testes
- Beneficiará de descontos especiais no lançamento oficial

Porquê participar como utilizador piloto?
- ✅ Acesso antecipado à plataforma
- ✅ Descontos exclusivos no lançamento
- ✅ Oportunidade de influenciar o desenvolvimento
- ✅ Suporte prioritário durante a fase de testes

Visitar Website: {baseUrl}

✅ Inscrição Confirmada: O seu email foi registado e entraremos em contacto em breve.',
                'available_fields' => [
                    ['key' => '{email}', 'description' => 'Email do utilizador', 'required' => false],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'registration_invite',
                'name' => 'Convite de Registo',
                'description' => 'Email enviado com convite para registo usando token único',
                'subject' => 'Convite para Registar-se - O Meu Prédio',
                'html_body' => '<div class="greeting">Olá!</div>
<div class="message">
    <p>Recebeu um convite especial para se registar no <strong>O Meu Prédio</strong>!</p>
    <p>Como utilizador piloto, tem acesso privilegiado para criar a sua conta e começar a utilizar a plataforma.</p>
    <div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
        <p style="margin: 0;"><strong>✅ Convite Especial:</strong> Este convite permite-lhe registar-se mesmo durante a fase de testes.</p>
    </div>
</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="{registrationUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Criar Conta Agora</a>
</div>
<div class="warning">
    <div class="warning-title">
        <span>⚠️</span>
        <span>Importante</span>
    </div>
    <ul>
        <li>Este convite expira em {expiresAt}</li>
        <li>O link só pode ser usado uma vez</li>
        <li>Se precisar de novo convite, contacte-nos</li>
    </ul>
</div>
<div class="link-box">
    <div class="link-label">Se o botão não funcionar, copie e cole este link no seu navegador:</div>
    {registrationUrl}
</div>
<div style="background-color: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0;">
    <p style="margin: 0;"><strong>ℹ️ Sobre o Registo:</strong> Ao criar a sua conta, terá acesso completo à plataforma e poderá começar a gerir o seu condomínio imediatamente.</p>
</div>',
                'text_body' => 'Olá!

Recebeu um convite especial para se registar no O Meu Prédio!

Como utilizador piloto, tem acesso privilegiado para criar a sua conta e começar a utilizar a plataforma.

✅ Convite Especial: Este convite permite-lhe registar-se mesmo durante a fase de testes.

Criar Conta: {registrationUrl}

⚠️ Importante:
- Este convite expira em {expiresAt}
- O link só pode ser usado uma vez
- Se precisar de novo convite, contacte-nos

ℹ️ Sobre o Registo: Ao criar a sua conta, terá acesso completo à plataforma e poderá começar a gerir o seu condomínio imediatamente.',
                'available_fields' => [
                    ['key' => '{email}', 'description' => 'Email do utilizador', 'required' => false],
                    ['key' => '{registrationUrl}', 'description' => 'URL de registo com token (ex: /register?token=xxx)', 'required' => true],
                    ['key' => '{expiresAt}', 'description' => 'Data de expiração do convite (formato: dd/mm/yyyy)', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'pilot_user_notification',
                'name' => 'Notificação - Novo User Piloto',
                'description' => 'Email enviado ao super admin quando um novo user piloto se inscreve',
                'subject' => 'Novo User Piloto Inscrito - O Meu Prédio',
                'html_body' => '<div class="greeting">
    <h2>Novo User Piloto Inscrito</h2>
    <p>Um novo utilizador interessado em participar como user piloto acabou de se inscrever.</p>
</div>
<div class="user-info">
    <p><strong>Email:</strong> {email}</p>
    <p><strong>Data de Inscrição:</strong> {subscribedAt}</p>
    <p><strong>Status:</strong> <span style="color: #F98E13; font-weight: bold;">Aguardando Ação</span></p>
</div>
<p><strong>Próximo passo:</strong> Pode enviar um convite de registo através do painel de administração.</p>
<div style="text-align: center; margin: 30px 0;">
    <a href="{adminUrl}" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Ver Users Piloto</a>
</div>
<p><strong>O que pode fazer:</strong></p>
<ul>
    <li>✅ Enviar convite de registo</li>
    <li>📧 Ver histórico de inscrições</li>
    <li>👥 Gerir todos os users piloto</li>
    <li>📊 Ver estatísticas</li>
</ul>',
                'text_body' => 'Novo User Piloto Inscrito

Um novo utilizador interessado em participar como user piloto acabou de se inscrever.

Email: {email}
Data de Inscrição: {subscribedAt}
Status: Aguardando Ação

Próximo passo: Pode enviar um convite de registo através do painel de administração.

Ver Users Piloto: {adminUrl}

O que pode fazer:
- Enviar convite de registo
- Ver histórico de inscrições
- Gerir todos os users piloto
- Ver estatísticas',
                'available_fields' => [
                    ['key' => '{email}', 'description' => 'Email do user piloto que se inscreveu', 'required' => true],
                    ['key' => '{subscribedAt}', 'description' => 'Data e hora da inscrição', 'required' => true],
                    ['key' => '{adminUrl}', 'description' => 'URL do painel de users piloto', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ],
            [
                'template_key' => 'condominium_deletion_confirm',
                'name' => 'Confirmação de Eliminação de Condomínio',
                'description' => 'Email enviado ao super admin para confirmar eliminação de condomínio (dupla autenticação)',
                'subject' => 'Confirmar eliminação do condomínio - O Meu Prédio',
                'html_body' => '<div class="greeting">Olá {nome}!</div>
<div class="message">Solicitou a eliminação do condomínio <strong>{condominiumName}</strong>.</div>
<div class="warning">
    <div class="warning-title">
        <span>⚠️</span>
        <span>Atenção</span>
    </div>
    <ul>
        <li>Esta ação é <strong>irreversível</strong> - todos os dados do condomínio serão apagados permanentemente</li>
        <li>O link expira em 1 hora</li>
        <li>Se não solicitou esta ação, ignore este email</li>
    </ul>
</div>
<div style="text-align: center; margin: 35px 0;">
    <a href="{confirmUrl}" class="button" style="background: #dc3545; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Confirmar Eliminação</a>
</div>
<div class="link-box">
    <div class="link-label">Se o botão não funcionar, copie e cole este link no seu navegador:</div>
    {confirmUrl}
</div>',
                'text_body' => 'Olá {nome}!

Solicitou a eliminação do condomínio {condominiumName}.

⚠️ Atenção:
- Esta ação é irreversível - todos os dados do condomínio serão apagados permanentemente
- O link expira em 1 hora
- Se não solicitou esta ação, ignore este email

Confirmar eliminação: {confirmUrl}',
                'available_fields' => [
                    ['key' => '{nome}', 'description' => 'Nome do super admin', 'required' => true],
                    ['key' => '{condominiumName}', 'description' => 'Nome do condomínio a eliminar', 'required' => true],
                    ['key' => '{confirmUrl}', 'description' => 'URL de confirmação com token', 'required' => true],
                    ['key' => '{baseUrl}', 'description' => 'URL base do sistema', 'required' => false]
                ]
            ]
        ];

        // Create specific templates
        foreach ($templates as $templateData) {
            if (!$this->emailTemplateModel->existsByKey($templateData['template_key'])) {
                $this->emailTemplateModel->create([
                    'template_key' => $templateData['template_key'],
                    'name' => $templateData['name'],
                    'description' => $templateData['description'],
                    'subject' => $templateData['subject'],
                    'html_body' => $templateData['html_body'],
                    'text_body' => $templateData['text_body'],
                    'available_fields' => $templateData['available_fields'],
                    'is_base_layout' => false,
                    'is_active' => true
                ]);
            }
        }

        echo "Email templates seeded successfully!\n";
        echo "Created base layout and " . count($templates) . " specific templates.\n";
    }
}

// Execute se chamado diretamente
if (php_sapi_name() === 'cli') {
    try {
        $seeder = new EmailTemplatesSeeder();
        $seeder->run();
        echo "Email templates seeded successfully!\n";
    } catch (\Exception $e) {
        echo "Error seeding email templates: " . $e->getMessage() . "\n";
        exit(1);
    }
}
