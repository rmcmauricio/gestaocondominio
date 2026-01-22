<?php

namespace App\Services;

class PdfService
{
    /**
     * Get template path for document type
     * @param int|null $templateId Template ID (1-16), null for default template
     * @param string $documentType Type: 'receipt', 'minutes', 'convocation'
     * @return string Template file path
     */
    protected function getTemplatePath(?int $templateId, string $documentType): string
    {
        // If template ID is null, use default template (1)
        if ($templateId === null) {
            $templateId = 1;
        }
        
        // Validate template ID
        if ($templateId < 1 || $templateId > 17) {
            $templateId = 1; // Fallback to default
        }
        
        $templateFile = __DIR__ . '/../Templates/document_templates/' . $documentType . '_template_' . $templateId . '.html';
        
        // If template doesn't exist, fallback to template 1
        if (!file_exists($templateFile)) {
            $templateFile = __DIR__ . '/../Templates/document_templates/' . $documentType . '_template_1.html';
        }
        
        return $templateFile;
    }

    /**
     * Generate logo HTML for templates
     * @param string|null $logoPath Logo path relative to storage folder (e.g., 'condominiums/1/logo/logo.jpg') or null
     * @return string HTML for logo or empty string
     */
    protected function getLogoHtml(?string $logoPath): string
    {
        if (!$logoPath) {
            return '';
        }
        
        // Convert logo to base64 for DomPDF compatibility
        $fileStorageService = new \App\Services\FileStorageService();
        $fullPath = $fileStorageService->getFilePath($logoPath);
        
        if (!file_exists($fullPath)) {
            return '';
        }
        
        // Read file and convert to base64
        $imageData = file_get_contents($fullPath);
        if ($imageData === false) {
            return '';
        }
        
        // Get MIME type from file extension
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        $mimeType = $mimeTypes[$extension] ?? 'image/jpeg';
        $base64 = base64_encode($imageData);
        
        return '<img src="data:' . $mimeType . ';base64,' . $base64 . '" alt="Logo" class="header-logo">';
    }

    /**
     * Generate assembly convocation PDF
     */
    public function generateConvocation(int $assemblyId, array $assembly, array $attendees): string
    {
        // Simple HTML to PDF conversion
        // In production, use a library like TCPDF, DomPDF, or mPDF
        
        $html = $this->getConvocationHtml($assembly, $attendees);
        
        // Save to storage
        $filename = 'convocation_' . $assemblyId . '_' . time() . '.html';
        $filepath = __DIR__ . '/../../storage/documents/' . $filename;
        
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($filepath, $html);
        
        return $filename;
    }

    /**
     * Generate assembly minutes PDF
     */
    public function generateMinutes(int $assemblyId, array $assembly, array $attendees, array $votes): string
    {
        $html = $this->getMinutesHtml($assembly, $attendees, $votes);
        
        $filename = 'minutes_' . $assemblyId . '_' . time() . '.html';
        $filepath = __DIR__ . '/../../storage/documents/' . $filename;
        
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($filepath, $html);
        
        return $filename;
    }

    /**
     * Get convocation HTML
     */
    protected function getConvocationHtml(array $assembly, array $attendees): string
    {
        // Get condominium info
        $condominiumModel = new \App\Models\Condominium();
        $condominium = $condominiumModel->findById($assembly['condominium_id']);
        
        // Get template ID (null means default template, which will be handled by getTemplatePath)
        $templateId = $condominium ? $condominiumModel->getDocumentTemplate($assembly['condominium_id']) : 1;
        if ($templateId === null) {
            $templateId = 1; // Default template
        }
        
        // Get logo path
        $logoPath = $condominium ? $condominiumModel->getLogoPath($assembly['condominium_id']) : null;
        
        // Load template
        $templatePath = $this->getTemplatePath($templateId, 'convocation');
        $template = file_get_contents($templatePath);
        
        // Prepare data
        $date = date('d/m/Y', strtotime($assembly['scheduled_date']));
        $time = date('H:i', strtotime($assembly['scheduled_date']));
        $type = ($assembly['type'] === 'extraordinary' || $assembly['type'] === 'extraordinaria') ? 'Extraordinária' : 'Ordinária';
        $quorum = $assembly['quorum_percentage'] ?? 50;
        $location = $assembly['location'] ?? 'A definir';
        $description = nl2br(htmlspecialchars($assembly['description'] ?? $assembly['agenda'] ?? 'A definir na assembleia'));
        $generationDate = date('d/m/Y H:i');
        
        // Replace placeholders
        $template = str_replace('{{LOGO_HTML}}', $this->getLogoHtml($logoPath), $template);
        $template = str_replace('{{ASSEMBLY_TITLE}}', htmlspecialchars($assembly['title'] ?? ''), $template);
        $template = str_replace('{{ASSEMBLY_TYPE}}', $type, $template);
        $template = str_replace('{{ASSEMBLY_DATE}}', $date, $template);
        $template = str_replace('{{ASSEMBLY_TIME}}', $time, $template);
        $template = str_replace('{{ASSEMBLY_LOCATION}}', htmlspecialchars($location), $template);
        $template = str_replace('{{QUORUM}}', $quorum, $template);
        $template = str_replace('{{ASSEMBLY_DESCRIPTION}}', $description, $template);
        $template = str_replace('{{GENERATION_DATE}}', $generationDate, $template);
        
        return $template;
    }

    /**
     * Get minutes HTML
     */
    protected function getMinutesHtml(array $assembly, array $attendees, array $votes): string
    {
        // Use populateMinutesTemplate which already handles templates
        $voteTopicModel = new \App\Models\VoteTopic();
        $topics = $voteTopicModel->getByAssembly($assembly['id']);
        $agendaPointModel = new \App\Models\AssemblyAgendaPoint();
        $agendaPoints = $agendaPointModel->getByAssembly($assembly['id']);
        
        // Calculate vote results
        $voteResults = [];
        $voteModel = new \App\Models\Vote();
        foreach ($topics as $topic) {
            $res = $voteModel->calculateResults($topic['id']);
            $res['votes_by_fraction'] = $voteModel->getByTopic($topic['id']);
            $voteResults[$topic['id']] = $res;
        }
        
        return $this->populateMinutesTemplate($assembly, $attendees, $topics, $voteResults, $agendaPoints);
    }
    
    /**
     * Get minutes HTML (old method - kept for backward compatibility but now uses templates)
     * @deprecated This method now delegates to populateMinutesTemplate
     */
    protected function getMinutesHtmlOld(array $assembly, array $attendees, array $votes): string
    {
        $date = date('d/m/Y', strtotime($assembly['scheduled_date']));
        $time = date('H:i', strtotime($assembly['scheduled_date']));
        // Map type for display
        $type = ($assembly['type'] === 'extraordinary' || $assembly['type'] === 'extraordinaria') ? 'Extraordinária' : 'Ordinária';
        
        // Calculate total millage
        $totalMillage = 0;
        $attendeesList = '';
        foreach ($attendees as $attendee) {
            $millage = $attendee['fraction_millage'] ?? 0;
            $totalMillage += (float) $millage;
            $typeLabel = $attendee['attendance_type'] === 'proxy' ? ' (por procuração)' : '';
            $attendeesList .= "<tr><td>{$attendee['fraction_identifier']}</td><td>{$attendee['user_name']}</td><td>{$millage}‰</td><td>{$typeLabel}</td></tr>";
        }
        
        // Get vote topics and results
        global $db;
        $voteTopicModel = new \App\Models\VoteTopic();
        $voteModel = new \App\Models\Vote();
        $topics = $voteTopicModel->getByAssembly($assembly['id']);
        $agendaPointModel = new \App\Models\AssemblyAgendaPoint();
        $agendaPoints = $agendaPointModel->getByAssembly($assembly['id']);

        $ordemTrabalhos = nl2br(htmlspecialchars($assembly['description'] ?? $assembly['agenda'] ?? ''));
        $votesSection = '';

        if (!empty($agendaPoints)) {
            $ordemTrabalhos = '<ul style="list-style-type: none; padding-left: 0;">';
            foreach ($agendaPoints as $p) {
                $ordemTrabalhos .= '<li style="margin: 8px 0;">• ' . htmlspecialchars($p['title'] ?? '') . '</li>';
            }
            $ordemTrabalhos .= '</ul>';
            $topicsById = [];
            foreach ($topics as $t) {
                $topicsById[(int)($t['id'] ?? 0)] = $t;
            }
            $endTime = !empty($assembly['ended_at']) ? date('H:i', strtotime($assembly['ended_at'])) : date('H:i');
            foreach ($agendaPoints as $i => $p) {
                $votesSection .= '<div style="margin: 20px 0; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #007bff;">';
                $votesSection .= '<h4 style="margin-top: 0;">Ponto ' . ($i + 1) . ' – ' . htmlspecialchars($p['title'] ?? '') . '</h4>';
                if (!empty(trim($p['body'] ?? ''))) {
                    $votesSection .= '<p>' . nl2br(htmlspecialchars($p['body'])) . '</p>';
                }
                if (!empty($p['vote_topic_id'])) {
                    $res = $voteModel->calculateResults((int)$p['vote_topic_id']);
                    $topic = $topicsById[(int)$p['vote_topic_id']] ?? null;
                    if ($topic) {
                        $res['votes_by_fraction'] = $voteModel->getByTopic((int)$p['vote_topic_id']);
                    }
                    if ($topic && $res && ($res['total_votes'] ?? 0) > 0) {
                        $votesSection .= '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;"><thead><tr style="background-color: #e9ecef;"><th style="padding: 8px; border: 1px solid #ddd;">Opção</th><th style="padding: 8px; border: 1px solid #ddd;">Votos</th><th style="padding: 8px; border: 1px solid #ddd;">Permilagem</th><th style="padding: 8px; border: 1px solid #ddd;">%</th></tr></thead><tbody>';
                        foreach ($res['options'] as $option => $data) {
                            $votesSection .= '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . htmlspecialchars($option) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['count'] . '</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['millage'] . '‰</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['percentage_by_millage'] . '%</td></tr>';
                        }
                        $votesSection .= '</tbody></table>';
                        if (!empty($res['winning_option'])) {
                            $votesSection .= '<p style="margin-top: 12px;"><strong>Resultado (por permilagem):</strong> <strong>' . htmlspecialchars($res['winning_option']) . '</strong> ganhou com ' . number_format($res['winning_percentage_by_millage'] ?? 0, 2) . '%.</p>';
                        }
                        $ch = $this->generateVoteChartImageHtml($res);
                        if ($ch !== '') {
                            $votesSection .= '<div style="margin:12px 0;">' . $ch . '</div>';
                        }
                        if (!empty($res['votes_by_fraction'])) {
                            $vf = $res['votes_by_fraction'];
                            usort($vf, function ($a, $b) { return strcmp($a['fraction_identifier'] ?? '', $b['fraction_identifier'] ?? ''); });
                            $votesSection .= '<p><strong>Registo de votações por fração</strong></p><table style="width: 100%; border-collapse: collapse; margin-top: 10px;"><thead><tr style="background-color: #e9ecef;"><th style="padding: 8px; border: 1px solid #ddd;">Fração</th><th style="padding: 8px; border: 1px solid #ddd;">Condómino</th><th style="padding: 8px; border: 1px solid #ddd;">Voto</th><th style="padding: 8px; border: 1px solid #ddd;">Permilagem</th><th style="padding: 8px; border: 1px solid #ddd;">Observações</th></tr></thead><tbody>';
                            foreach ($vf as $row) {
                                $obs = isset($row['notes']) && trim((string)($row['notes'] ?? '')) !== '' ? htmlspecialchars($row['notes']) : '—';
                                $voto = $row['vote_option'] ?? $row['vote_value'] ?? '—';
                                $perm = isset($row['fraction_millage']) ? $row['fraction_millage'] . '‰' : '—';
                                $votesSection .= '<tr><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($row['fraction_identifier'] ?? '') . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($row['user_name'] ?? '') . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($voto) . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . $perm . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . $obs . '</td></tr>';
                            }
                            $votesSection .= '</tbody></table>';
                        }
                    }
                }
                $votesSection .= '</div>';
            }
            $minutesText = $assembly['minutes'] ?? '';
            $votesSection .= '<div style="margin: 20px 0;"><h4>Outros assuntos</h4><p>' . (!empty(trim($minutesText)) ? nl2br(htmlspecialchars($minutesText)) : 'Não foram discutidos outros assuntos.') . '</p></div>';
            $votesSection .= '<div style="margin: 20px 0;"><h4>Encerramento</h4><p>Nada mais havendo a tratar, foi a reunião encerrada pelas ' . htmlspecialchars($endTime) . ' horas, sendo lavrada a presente acta que, depois de lida e aprovada, vai ser assinada por todos os presentes.</p></div>';
        } elseif (!empty($topics)) {
            $votesSection = '<h3 style="margin-top: 30px; border-top: 2px solid #333; padding-top: 20px;">Votações Realizadas</h3>';
            foreach ($topics as $topic) {
                $results = $voteModel->calculateResults($topic['id']);
                $votesSection .= '<div style="margin: 20px 0; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #007bff;">';
                $votesSection .= '<h4 style="margin-top: 0;">' . htmlspecialchars($topic['title']) . '</h4>';
                
                if (!empty($topic['description'])) {
                    $votesSection .= '<p><em>' . htmlspecialchars($topic['description']) . '</em></p>';
                }
                
                if ($results['total_votes'] > 0) {
                    $votesSection .= '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;">';
                    $votesSection .= '<thead><tr style="background-color: #e9ecef;"><th style="padding: 8px; border: 1px solid #ddd;">Opção</th><th style="padding: 8px; border: 1px solid #ddd;">Votos</th><th style="padding: 8px; border: 1px solid #ddd;">Permilagem</th><th style="padding: 8px; border: 1px solid #ddd;">%</th></tr></thead>';
                    $votesSection .= '<tbody>';
                    
                    foreach ($results['options'] as $option => $data) {
                        $votesSection .= '<tr>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd;"><strong>' . htmlspecialchars($option) . '</strong></td>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['count'] . '</td>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['millage'] . '‰</td>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['percentage_by_millage'] . '%</td>';
                        $votesSection .= '</tr>';
                    }
                    
                    $votesSection .= '</tbody>';
                    $votesSection .= '<tfoot><tr style="background-color: #f8f9fa; font-weight: bold;"><td style="padding: 8px; border: 1px solid #ddd;">Total</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $results['total_votes'] . '</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $results['total_millage'] . '‰</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">100%</td></tr></tfoot>';
                    $votesSection .= '</table>';
                    if (!empty($results['winning_option'])) {
                        $votesSection .= '<p style="margin-top: 12px;"><strong>Resultado (por permilagem):</strong> <strong>' . htmlspecialchars($results['winning_option']) . '</strong> ganhou com ' . number_format($results['winning_percentage_by_millage'], 2) . '% da permilagem dos votos expressos.</p>';
                    }
                    $chartHtml = $this->generateVoteChartImageHtml($results);
                    if ($chartHtml !== '') {
                        $votesSection .= '<div style="margin:12px 0;">' . $chartHtml . '</div>';
                    }
                    $votesByFraction = $voteModel->getByTopic($topic['id']);
                    if (!empty($votesByFraction)) {
                        usort($votesByFraction, function ($a, $b) { return strcmp($a['fraction_identifier'] ?? '', $b['fraction_identifier'] ?? ''); });
                        $votesSection .= '<p><strong>Registo de votações por fração</strong></p>';
                        $votesSection .= '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;"><thead><tr style="background-color: #e9ecef;"><th style="padding: 8px; border: 1px solid #ddd;">Fração</th><th style="padding: 8px; border: 1px solid #ddd;">Condómino</th><th style="padding: 8px; border: 1px solid #ddd;">Voto</th><th style="padding: 8px; border: 1px solid #ddd;">Permilagem</th><th style="padding: 8px; border: 1px solid #ddd;">Observações</th></tr></thead><tbody>';
                        foreach ($votesByFraction as $row) {
                            $obs = isset($row['notes']) && trim((string)$row['notes']) !== '' ? htmlspecialchars($row['notes']) : '—';
                            $voto = $row['vote_option'] ?? $row['vote_value'] ?? '—';
                            $perm = isset($row['fraction_millage']) ? $row['fraction_millage'] . '‰' : '—';
                            $votesSection .= '<tr><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($row['fraction_identifier'] ?? '') . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($row['user_name'] ?? '') . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($voto) . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . $perm . '</td><td style="padding: 8px; border: 1px solid #ddd;">' . $obs . '</td></tr>';
                        }
                        $votesSection .= '</tbody></table>';
                    }
                } else {
                    $votesSection .= '<p><em>Nenhum voto registado para este tópico.</em></p>';
                }

                $votesSection .= '</div>';
            }
        }

        $minutesText = $assembly['minutes'] ?? $assembly['description'] ?? $assembly['agenda'] ?? '';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Atas da Assembleia</title>
            <style>
                @page { margin: 2cm; }
                body { 
                    font-family: 'Times New Roman', serif; 
                    margin: 0;
                    padding: 20px;
                    line-height: 1.6;
                    color: #333;
                }
                .header { 
                    text-align: center; 
                    margin-bottom: 40px;
                    border-bottom: 3px solid #333;
                    padding-bottom: 20px;
                }
                .header h1 { 
                    color: #1a1a1a;
                    font-size: 24px;
                    margin: 0;
                    text-transform: uppercase;
                    letter-spacing: 2px;
                }
                .info-box {
                    background-color: #f9f9f9;
                    border-left: 4px solid #28a745;
                    padding: 15px;
                    margin: 20px 0;
                }
                .info-label {
                    font-weight: bold;
                    display: inline-block;
                    width: 150px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                }
                table th, table td {
                    padding: 10px;
                    border: 1px solid #ddd;
                    text-align: left;
                }
                table th {
                    background-color: #f8f9fa;
                    font-weight: bold;
                }
                .content { 
                    line-height: 1.8;
                }
                .minutes-text {
                    margin-top: 20px;
                    padding: 15px;
                    background-color: #f5f5f5;
                    border-radius: 5px;
                    white-space: pre-wrap;
                }
                .footer { 
                    margin-top: 50px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    font-size: 11px;
                    color: #666;
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>ATAS DA ASSEMBLEIA</h1>
            </div>
            <div class='content'>
                <div class='info-box'>
                    <p><span class='info-label'>Título:</span> {$assembly['title']}</p>
                    <p><span class='info-label'>Tipo:</span> {$type}</p>
                    <p><span class='info-label'>Data:</span> {$date}</p>
                    <p><span class='info-label'>Hora:</span> {$time}</p>
                    <p><span class='info-label'>Local:</span> " . ($assembly['location'] ?? 'Não especificado') . "</p>
                </div>
                
                <h3>Lista de Presenças</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Fração</th>
                            <th>Condómino</th>
                            <th>Permilagem</th>
                            <th>Observações</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$attendeesList}
                    </tbody>
                    <tfoot>
                        <tr style='background-color: #f8f9fa; font-weight: bold;'>
                            <td colspan='2'>Total</td>
                            <td>{$totalMillage}‰</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                
                <h3>Ordem de Trabalhos</h3>
                <div class='minutes-text'>{$ordemTrabalhos}</div>
                
                {$votesSection}
                
                " . (empty($agendaPoints) && !empty($minutesText) ? "<h3>Resumo e Decisões</h3><div class='minutes-text'>" . nl2br(htmlspecialchars($minutesText)) . "</div>" : "") . "
            </div>
            <div class='footer'>
                <p>Atas geradas automaticamente pelo sistema de gestão de condomínios.</p>
                <p>Data de geração: " . date('d/m/Y H:i') . "</p>
            </div>
        </body>
        </html>";
    }

    /**
     * Generate vote chart as inline image (SVG in data URI) for embedding in minutes PDF.
     * Pie chart by percentage_by_millage. Returns empty string if no votes.
     */
    protected function generateVoteChartImageHtml(array $results): string
    {
        if (empty($results['options']) || ($results['total_votes'] ?? 0) <= 0) {
            return '';
        }
        $colors = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948'];
        $cx = 75;
        $cy = 70;
        $r = 55;
        $startAngle = -90; // 12 o'clock
        $segments = '';
        $legend = '';
        $idx = 0;
        foreach ($results['options'] as $option => $data) {
            $pct = (float)($data['percentage_by_millage'] ?? 0);
            if ($pct <= 0) {
                continue;
            }
            $arcDeg = ($pct / 100) * 360;
            $endAngle = $startAngle + $arcDeg;
            $startRad = $startAngle * M_PI / 180;
            $endRad = $endAngle * M_PI / 180;
            $x1 = $cx + $r * cos($startRad);
            $y1 = $cy + $r * sin($startRad);
            $x2 = $cx + $r * cos($endRad);
            $y2 = $cy + $r * sin($endRad);
            $largeArc = ($arcDeg > 180) ? 1 : 0;
            $segments .= sprintf('<path d="M %s %s L %s %s A %s %s 0 %d 1 %s %s Z" fill="%s" />',
                $cx, $cy, round($x1, 2), round($y1, 2), $r, $r, $largeArc, round($x2, 2), round($y2, 2),
                $colors[$idx % count($colors)]
            );
            $label = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $option) . ': ' . number_format($pct, 1) . '%';
            $ly = 25 + $idx * 18;
            $legend .= sprintf('<rect x="160" y="%d" width="10" height="10" fill="%s" /><text x="175" y="%d" font-size="11" font-family="sans-serif">%s</text>',
                $ly, $colors[$idx % count($colors)], $ly + 9, $label
            );
            $startAngle = $endAngle;
            $idx++;
        }
        if ($segments === '') {
            return '';
        }
        $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 140" width="320" height="140">' . $segments . $legend . '</svg>';
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt="Gráfico por permilagem" style="max-width:100%; height:auto; margin:10px 0;" />';
    }

    /**
     * Populate minutes template with assembly data
     */
    public function populateMinutesTemplate(array $assembly, array $attendees, array $topics, array $voteResults, array $agendaPoints = []): string
    {
        // Get condominium info
        $condominiumModel = new \App\Models\Condominium();
        $condominium = $condominiumModel->findById($assembly['condominium_id']);
        
        // Get template ID (null means default template, which will be handled by getTemplatePath)
        $templateId = $condominium ? $condominiumModel->getDocumentTemplate($assembly['condominium_id']) : 1;
        if ($templateId === null) {
            $templateId = 1; // Default template
        }
        
        // Get logo path
        $logoPath = $condominium ? $condominiumModel->getLogoPath($assembly['condominium_id']) : null;
        
        // Load template
        $templatePath = $this->getTemplatePath($templateId, 'minutes');
        if (!file_exists($templatePath)) {
            // Fallback to old template if new template doesn't exist
            $templatePath = __DIR__ . '/../Templates/minutes_template.html';
            if (!file_exists($templatePath)) {
                throw new \Exception("Template file not found: {$templatePath}");
            }
        }
        
        $template = file_get_contents($templatePath);
        
        $condominiumName = $condominium['name'] ?? 'Não especificado';
        $condominiumAddress = $condominium['address'] ?? 'Não especificado';
        
        // Format date and time
        $date = date('d/m/Y', strtotime($assembly['scheduled_date']));
        $time = date('H:i', strtotime($assembly['scheduled_date']));
        $day = date('d', strtotime($assembly['scheduled_date']));
        $month = date('F', strtotime($assembly['scheduled_date']));
        $year = date('Y', strtotime($assembly['scheduled_date']));
        
        // Portuguese month names
        $months = [
            'January' => 'Janeiro', 'February' => 'Fevereiro', 'March' => 'Março',
            'April' => 'Abril', 'May' => 'Maio', 'June' => 'Junho',
            'July' => 'Julho', 'August' => 'Agosto', 'September' => 'Setembro',
            'October' => 'Outubro', 'November' => 'Novembro', 'December' => 'Dezembro'
        ];
        $monthPt = $months[$month] ?? $month;
        
        // Map type for display
        $type = ($assembly['type'] === 'extraordinary' || $assembly['type'] === 'extraordinaria') ? 'Extraordinária' : 'Ordinária';
        
        // Format location
        $location = $assembly['location'] ?? 'Não especificado';
        
        // Calculate quorum info
        $attendeeModel = new \App\Models\AssemblyAttendee();
        $quorum = $attendeeModel->calculateQuorum($assembly['id'], $assembly['condominium_id']);
        $quorumPercentage = number_format($quorum['percentage'], 2);
        $quorumMillage = number_format($quorum['attended_millage'], 4);
        $totalMillage = number_format($quorum['total_millage'], 4);
        
        // Format attendees list
        $totalAttendeesMillage = 0;
        $attendeesList = '';
        foreach ($attendees as $attendee) {
            $millage = $attendee['fraction_millage'] ?? 0;
            $totalAttendeesMillage += (float) $millage;
            $typeLabel = $attendee['attendance_type'] === 'proxy' ? ' (por procuração)' : '';
            $notes = !empty($attendee['notes']) ? htmlspecialchars($attendee['notes']) : '';
            $attendeesList .= "<tr><td>{$attendee['fraction_identifier']}</td><td>{$attendee['user_name']}</td><td>{$millage}‰</td><td>{$typeLabel} {$notes}</td></tr>";
        }
        
        // Format votes results
        $votesSection = '';
        if (!empty($topics)) {
            foreach ($topics as $topic) {
                $results = $voteResults[$topic['id']] ?? null;
                if (!$results) {
                    continue;
                }
                
                $votesSection .= '<div style="margin: 20px 0; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #007bff;">';
                $votesSection .= '<h4 style="margin-top: 0;">' . htmlspecialchars($topic['title']) . '</h4>';
                
                if (!empty($topic['description'])) {
                    $votesSection .= '<p><em>' . htmlspecialchars($topic['description']) . '</em></p>';
                }
                
                if ($results['total_votes'] > 0) {
                    $votesSection .= '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;">';
                    $votesSection .= '<thead><tr style="background-color: #e9ecef;"><th style="padding: 8px; border: 1px solid #ddd;">Opção</th><th style="padding: 8px; border: 1px solid #ddd;">Votos</th><th style="padding: 8px; border: 1px solid #ddd;">Permilagem</th><th style="padding: 8px; border: 1px solid #ddd;">%</th></tr></thead>';
                    $votesSection .= '<tbody>';
                    
                    foreach ($results['options'] as $option => $data) {
                        $votesSection .= '<tr>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd;"><strong>' . htmlspecialchars($option) . '</strong></td>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['count'] . '</td>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['millage'] . '‰</td>';
                        $votesSection .= '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $data['percentage_by_millage'] . '%</td>';
                        $votesSection .= '</tr>';
                    }
                    
                    $votesSection .= '</tbody>';
                    $votesSection .= '<tfoot><tr style="background-color: #f8f9fa; font-weight: bold;"><td style="padding: 8px; border: 1px solid #ddd;">Total</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $results['total_votes'] . '</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' . $results['total_millage'] . '‰</td><td style="padding: 8px; border: 1px solid #ddd; text-align: center;">100%</td></tr></tfoot>';
                    $votesSection .= '</table>';
                } else {
                    $votesSection .= '<p><em>Nenhum voto registado para este tópico.</em></p>';
                }
                
                $votesSection .= '</div>';
            }
        }
        
        // Format assembly agenda/description
        $agenda = nl2br(htmlspecialchars($assembly['description'] ?? $assembly['agenda'] ?? 'Não especificado'));
        
        // Format summary
        $summary = nl2br(htmlspecialchars($assembly['minutes'] ?? ''));
        
        // Generation date
        $generationDate = date('d/m/Y H:i');
        
        // Replace placeholders - do ALL replacements
        $template = str_replace('{{LOGO_HTML}}', $this->getLogoHtml($logoUrl), $template);
        $template = str_replace('{{condominium_name}}', htmlspecialchars($condominiumName), $template);
        $template = str_replace('{{condominium_address}}', htmlspecialchars($condominiumAddress), $template);
        $template = str_replace('{{assembly_date}}', $date, $template);
        $template = str_replace('{{assembly_time}}', $time, $template);
        $template = str_replace('{{assembly_day}}', $day, $template);
        $template = str_replace('{{assembly_month}}', $monthPt, $template);
        $template = str_replace('{{assembly_year}}', $year, $template);
        $template = str_replace('{{assembly_type}}', $type, $template);
        $template = str_replace('{{assembly_location}}', htmlspecialchars($location), $template);
        $template = str_replace('{{quorum_percentage}}', $quorumPercentage, $template);
        $template = str_replace('{{quorum_millage}}', $quorumMillage, $template);
        $template = str_replace('{{total_millage}}', $totalMillage, $template);
        $template = str_replace('{{total_attendees_millage}}', number_format($totalAttendeesMillage, 4), $template);
        $template = str_replace('{{attendees_list}}', $attendeesList, $template);
        $template = str_replace('{{generation_date}}', $generationDate, $template);
        
        $endTime = !empty($assembly['ended_at']) ? date('H:i', strtotime($assembly['ended_at'])) : date('H:i');

        if (!empty($agendaPoints)) {
            // Assembly agenda from points
            $agendaFormatted = '<ul style="list-style-type: none; padding-left: 0;">';
            foreach ($agendaPoints as $p) {
                $agendaFormatted .= '<li style="margin: 8px 0;">• ' . htmlspecialchars($p['title'] ?? '') . '</li>';
            }
            $agendaFormatted .= '</ul>';
            $topicsById = [];
            foreach ($topics as $t) {
                $topicsById[(int)($t['id'] ?? 0)] = $t;
            }
            $topicsSections = '';
            $emojiN = ['3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣'];
            foreach ($agendaPoints as $i => $p) {
                $k = $i + 3;
                $emoji = $emojiN[$i] ?? '•';
                $topicsSections .= '<div class="section"><div class="section-number">' . $emoji . ' Ponto ' . $k . ' – ' . htmlspecialchars($p['title'] ?? '') . '</div>';
                if (!empty(trim($p['body'] ?? ''))) {
                    $topicsSections .= '<p>' . nl2br(htmlspecialchars($p['body'])) . '</p>';
                }
                if (!empty($p['vote_topic_id'])) {
                    $res = $voteResults[(int)$p['vote_topic_id']] ?? null;
                    $topic = $topicsById[(int)$p['vote_topic_id']] ?? null;
                    if ($res && $res['total_votes'] > 0 && $topic) {
                        $winningOption = '';
                        $winningPercentage = 0;
                        foreach ($res['options'] as $option => $data) {
                            if ($data['percentage_by_millage'] > $winningPercentage) {
                                $winningPercentage = $data['percentage_by_millage'];
                                $winningOption = $option;
                            }
                        }
                        $topicsSections .= '<p>Após discussão e votação, foi deliberado:</p>';
                        foreach ($res['options'] as $option => $data) {
                            $checked = ($option === $winningOption) ? '☑' : '☐';
                            $topicsSections .= '<p>' . $checked . ' ' . htmlspecialchars($option) . ' (' . $data['count'] . ' votos, ' . number_format($data['millage'], 4) . '‰, ' . number_format($data['percentage_by_millage'], 2) . '%)</p>';
                        }
                        $topicsSections .= '<p>por maioria de <span class="underline">' . number_format($winningPercentage, 2) . '%</span> do valor do prédio.</p>';
                        $topicsSections .= '<p><strong>Resultado (por permilagem):</strong> <strong>' . htmlspecialchars($winningOption) . '</strong> ganhou com ' . number_format($winningPercentage, 2) . '% da permilagem dos votos expressos.</p>';
                        $chartHtml = $this->generateVoteChartImageHtml($res);
                        if ($chartHtml !== '') {
                            $topicsSections .= '<div style="margin:12px 0;">' . $chartHtml . '</div>';
                        }
                        if (!empty($res['votes_by_fraction'])) {
                            $vf = $res['votes_by_fraction'];
                            usort($vf, function ($a, $b) { return strcmp($a['fraction_identifier'] ?? '', $b['fraction_identifier'] ?? ''); });
                            $topicsSections .= '<p><strong>Registo de votações por fração</strong></p><table style="width:100%; border-collapse:collapse; margin:10px 0;"><thead><tr style="background-color:#e9ecef;"><th style="padding:8px; border:1px solid #ddd;">Fração</th><th style="padding:8px; border:1px solid #ddd;">Condómino</th><th style="padding:8px; border:1px solid #ddd;">Voto</th><th style="padding:8px; border:1px solid #ddd;">Permilagem</th><th style="padding:8px; border:1px solid #ddd;">Observações</th></tr></thead><tbody>';
                            foreach ($vf as $row) {
                                $obs = isset($row['notes']) && trim((string)($row['notes'] ?? '')) !== '' ? htmlspecialchars($row['notes']) : '—';
                                $voto = $row['vote_option'] ?? $row['vote_value'] ?? '—';
                                $perm = isset($row['fraction_millage']) ? $row['fraction_millage'] . '‰' : '—';
                                $topicsSections .= '<tr><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['fraction_identifier'] ?? '') . '</td><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['user_name'] ?? '') . '</td><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($voto) . '</td><td style="padding:8px; border:1px solid #ddd;">' . $perm . '</td><td style="padding:8px; border:1px solid #ddd;">' . $obs . '</td></tr>';
                            }
                            $topicsSections .= '</tbody></table>';
                        }
                    } else {
                        $topicsSections .= '<p>Foi apresentado o tema para discussão.</p><p>Após análise e esclarecimentos, foi deliberado:</p><p>☐ Aprovado</p><p>☐ Rejeitado</p><p>por maioria de ______% do valor do prédio.</p>';
                    }
                } else {
                    $topicsSections .= '<p>Foi apresentado o tema para discussão.</p><p>Após análise e esclarecimentos, foi deliberado:</p><p>☐ Aprovado</p><p>☐ Rejeitado</p><p>por maioria de ______% do valor do prédio.</p>';
                }
                $topicsSections .= '</div>';
            }
            $n = count($agendaPoints);
            $ot = !empty(trim($assembly['minutes'] ?? '')) ? nl2br(htmlspecialchars($assembly['minutes'])) : '<p>Não foram discutidos outros assuntos.</p>';
            $od = '<p>Não foram tomadas outras deliberações.</p>';
            $topicsSections .= '<div class="section"><div class="section-number">• Ponto ' . (3 + $n) . ' – Outros assuntos</div><p>Foram discutidos os seguintes temas:</p>' . $ot . '<p>Tendo sido deliberado:</p>' . $od . '</div>';
            $topicsSections .= '<div class="section"><div class="section-number">• Ponto ' . (4 + $n) . ' – Encerramento</div><p>Nada mais havendo a tratar, foi a reunião encerrada pelas <span class="underline">' . htmlspecialchars($endTime) . '</span> horas, sendo lavrada a presente acta que, depois de lida e aprovada, vai ser assinada por todos os presentes.</p></div>';
        } else {
        // Format assembly agenda
        $agendaText = $assembly['description'] ?? $assembly['agenda'] ?? '';
        $agendaLines = explode("\n", $agendaText);
        $agendaFormatted = '';
        if (!empty($agendaLines) && trim($agendaText) !== '') {
            $agendaFormatted = '<ul style="list-style-type: none; padding-left: 0;">';
            foreach ($agendaLines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $agendaFormatted .= '<li style="margin: 8px 0;">• ' . htmlspecialchars($line) . '</li>';
                }
            }
            $agendaFormatted .= '</ul>';
        } else {
            $agendaFormatted = '<p>Apresentação e aprovação das contas do exercício anterior</p>';
            $agendaFormatted .= '<p>Aprovação do orçamento para o novo exercício</p>';
            $agendaFormatted .= '<p>Definição do valor das quotas de condomínio</p>';
            $agendaFormatted .= '<p>Eleição ou ratificação do administrador</p>';
            $agendaFormatted .= '<p>Outros assuntos de interesse para o condomínio</p>';
        }
        
        // Format topics sections (3️⃣ to 6️⃣)
        $topicsSections = '';
        $topicNumber = 3;
        $emojiNumbers = ['3️⃣', '4️⃣', '5️⃣', '6️⃣'];
        
        foreach ($topics as $index => $topic) {
            if ($topicNumber > 6) break; // Only show up to point 6
            
            $results = $voteResults[$topic['id']] ?? null;
            $emoji = $emojiNumbers[$index] ?? '•';
            
            $topicsSections .= '<div class="section">';
            $topicsSections .= '<div class="section-number">' . $emoji . ' Ponto ' . ($topicNumber - 2) . ' – ' . htmlspecialchars($topic['title']) . '</div>';
            
            if (!empty($topic['description'])) {
                $topicsSections .= '<p>' . nl2br(htmlspecialchars($topic['description'])) . '</p>';
            }
            
            if ($results && $results['total_votes'] > 0) {
                // Find winning option
                $winningOption = '';
                $winningPercentage = 0;
                foreach ($results['options'] as $option => $data) {
                    if ($data['percentage_by_millage'] > $winningPercentage) {
                        $winningPercentage = $data['percentage_by_millage'];
                        $winningOption = $option;
                    }
                }
                
                $topicsSections .= '<p>Após discussão e votação, foi deliberado:</p>';
                
                // Show checkboxes for each option
                foreach ($results['options'] as $option => $data) {
                    $checked = ($option === $winningOption) ? '☑' : '☐';
                    $topicsSections .= '<p>' . $checked . ' ' . htmlspecialchars($option) .
                        ' (' . $data['count'] . ' votos, ' . number_format($data['millage'], 4) . '‰, ' .
                        number_format($data['percentage_by_millage'], 2) . '%)</p>';
                }
                
                $topicsSections .= '<p>por maioria de <span class="underline">' . number_format($winningPercentage, 2) . '%</span> do valor do prédio.</p>';
                $topicsSections .= '<p><strong>Resultado (por permilagem):</strong> <strong>' . htmlspecialchars($winningOption) . '</strong> ganhou com ' . number_format($winningPercentage, 2) . '% da permilagem dos votos expressos.</p>';
                $chartHtml = $this->generateVoteChartImageHtml($results);
                if ($chartHtml !== '') {
                    $topicsSections .= '<div style="margin:12px 0;">' . $chartHtml . '</div>';
                }
                if (!empty($results['votes_by_fraction'])) {
                    $vf = $results['votes_by_fraction'];
                    usort($vf, function ($a, $b) { return strcmp($a['fraction_identifier'] ?? '', $b['fraction_identifier'] ?? ''); });
                    $topicsSections .= '<p><strong>Registo de votações por fração</strong></p>';
                    $topicsSections .= '<table style="width:100%; border-collapse:collapse; margin:10px 0;"><thead><tr style="background-color:#e9ecef;"><th style="padding:8px; border:1px solid #ddd;">Fração</th><th style="padding:8px; border:1px solid #ddd;">Condómino</th><th style="padding:8px; border:1px solid #ddd;">Voto</th><th style="padding:8px; border:1px solid #ddd;">Permilagem</th><th style="padding:8px; border:1px solid #ddd;">Observações</th></tr></thead><tbody>';
                    foreach ($vf as $row) {
                        $obs = isset($row['notes']) && trim((string)$row['notes']) !== '' ? htmlspecialchars($row['notes']) : '—';
                        $voto = $row['vote_option'] ?? $row['vote_value'] ?? '—';
                        $perm = isset($row['fraction_millage']) ? $row['fraction_millage'] . '‰' : '—';
                        $topicsSections .= '<tr><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['fraction_identifier'] ?? '') . '</td><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['user_name'] ?? '') . '</td><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($voto) . '</td><td style="padding:8px; border:1px solid #ddd;">' . $perm . '</td><td style="padding:8px; border:1px solid #ddd;">' . $obs . '</td></tr>';
                    }
                    $topicsSections .= '</tbody></table>';
                }
            } else {
                $topicsSections .= '<p>Foi apresentado o tema para discussão.</p>';
                $topicsSections .= '<p>Após análise e esclarecimentos, foi deliberado:</p>';
                $topicsSections .= '<p>☐ Aprovado</p>';
                $topicsSections .= '<p>☐ Rejeitado</p>';
                $topicsSections .= '<p>por maioria de ______% do valor do prédio.</p>';
            }
            
            $topicsSections .= '</div>';
            $topicNumber++;
        }
        
        // Fill remaining points if less than 4 topics
        while ($topicNumber <= 6) {
            $emoji = $emojiNumbers[$topicNumber - 3] ?? '•';
            $pointTitle = '';
            $pointContent = '';
            
            switch ($topicNumber) {
                case 3:
                    $pointTitle = 'Aprovação das contas';
                    $pointContent = '<p>Foram apresentadas as contas referentes ao período de ____ / ____ / ______ a ____ / ____ / ______, no montante total de € __________________.</p>';
                    $pointContent .= '<p>Após análise e esclarecimentos, as contas foram:</p>';
                    $pointContent .= '<p>☐ Aprovadas</p>';
                    $pointContent .= '<p>☐ Rejeitadas</p>';
                    $pointContent .= '<p>por maioria de ______% do valor do prédio.</p>';
                    break;
                case 4:
                    $pointTitle = 'Aprovação do orçamento';
                    $pointContent = '<p>Foi apresentado o orçamento para o novo exercício, no valor global de € __________________.</p>';
                    $pointContent .= '<p>O mesmo foi:</p>';
                    $pointContent .= '<p>☐ Aprovado</p>';
                    $pointContent .= '<p>☐ Rejeitado</p>';
                    $pointContent .= '<p>por maioria de ______%.</p>';
                    break;
                case 5:
                    $pointTitle = 'Fixação das quotas';
                    $pointContent = '<p>Foi aprovado que as quotas de condomínio passam a ser de € __________ por permilagem/unidade, a pagar com a seguinte periodicidade:</p>';
                    $pointContent .= '<p>☐ Mensal</p>';
                    $pointContent .= '<p>☐ Bimestral</p>';
                    $pointContent .= '<p>☐ Trimestral</p>';
                    $pointContent .= '<p>☐ Outra: _______________________</p>';
                    break;
                case 6:
                    $pointTitle = 'Administração do condomínio';
                    $pointContent = '<p>Foi deliberado:</p>';
                    $pointContent .= '<p>☐ Manter o atual administrador: ____________________________</p>';
                    $pointContent .= '<p>☐ Nomear novo administrador: _____________________________</p>';
                    $pointContent .= '<p>com mandato até ____ / ____ / ______.</p>';
                    break;
            }
            
            if (!empty($pointTitle)) {
                $topicsSections .= '<div class="section">';
                $topicsSections .= '<div class="section-number">' . $emoji . ' Ponto ' . ($topicNumber - 2) . ' – ' . $pointTitle . '</div>';
                $topicsSections .= $pointContent;
                $topicsSections .= '</div>';
            }
            $topicNumber++;
        }
        
        // Other topics and decisions
        $otherTopics = '';
        $otherDecisions = '';
        if (count($topics) > 4) {
            $remainingTopics = array_slice($topics, 4);
            foreach ($remainingTopics as $topic) {
                $otherTopics .= '<p>• ' . htmlspecialchars($topic['title']) . '</p>';
                if (!empty($topic['description'])) {
                    $otherTopics .= '<p style="margin-left: 20px;">' . htmlspecialchars($topic['description']) . '</p>';
                }
            }
        }
        
        // End time (use current time or assembly end time if available)
        $endTime = date('H:i');
        if (!empty($assembly['ended_at'])) {
            $endTime = date('H:i', strtotime($assembly['ended_at']));
        }
        
        // Replace remaining placeholders
        if (!isset($agendaFormatted)) {
            $agendaText = $assembly['description'] ?? $assembly['agenda'] ?? '';
            $agendaLines = explode("\n", $agendaText);
            $agendaFormatted = '';
            if (!empty($agendaLines) && trim($agendaText) !== '') {
                $agendaFormatted = '<ul style="list-style-type: none; padding-left: 0;">';
                foreach ($agendaLines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $agendaFormatted .= '<li style="margin: 8px 0;">• ' . htmlspecialchars($line) . '</li>';
                    }
                }
                $agendaFormatted .= '</ul>';
            } else {
                $agendaFormatted = '<p>Apresentação e aprovação das contas do exercício anterior</p>';
                $agendaFormatted .= '<p>Aprovação do orçamento para o novo exercício</p>';
                $agendaFormatted .= '<p>Definição do valor das quotas de condomínio</p>';
                $agendaFormatted .= '<p>Eleição ou ratificação do administrador</p>';
                $agendaFormatted .= '<p>Outros assuntos de interesse para o condomínio</p>';
            }
        }
        
        if (!isset($topicsSections)) {
            $topicsSections = '';
            $topicNumber = 3;
            $emojiNumbers = ['3️⃣', '4️⃣', '5️⃣', '6️⃣'];
            
            foreach ($topics as $index => $topic) {
                if ($topicNumber > 6) break;
                
                $results = $voteResults[$topic['id']] ?? null;
                $emoji = $emojiNumbers[$index] ?? '•';
                
                $topicsSections .= '<div class="section">';
                $topicsSections .= '<div class="section-number">' . $emoji . ' Ponto ' . ($topicNumber - 2) . ' – ' . htmlspecialchars($topic['title']) . '</div>';
                
                if (!empty($topic['description'])) {
                    $topicsSections .= '<p>' . nl2br(htmlspecialchars($topic['description'])) . '</p>';
                }
                
                if ($results && $results['total_votes'] > 0) {
                    $winningOption = '';
                    $winningPercentage = 0;
                    foreach ($results['options'] as $option => $data) {
                        if ($data['percentage_by_millage'] > $winningPercentage) {
                            $winningPercentage = $data['percentage_by_millage'];
                            $winningOption = $option;
                        }
                    }
                    
                    $topicsSections .= '<p>Após discussão e votação, foi deliberado:</p>';
                    
                    foreach ($results['options'] as $option => $data) {
                        $checked = ($option === $winningOption) ? '☑' : '☐';
                        $topicsSections .= '<p>' . $checked . ' ' . htmlspecialchars($option) . 
                            ' (' . $data['count'] . ' votos, ' . number_format($data['millage'], 4) . '‰, ' . 
                            number_format($data['percentage_by_millage'], 2) . '%)</p>';
                    }
                    
                    $topicsSections .= '<p>por maioria de <span class="underline">' . number_format($winningPercentage, 2) . '%</span> do valor do prédio.</p>';
                    $topicsSections .= '<p><strong>Resultado (por permilagem):</strong> <strong>' . htmlspecialchars($winningOption) . '</strong> ganhou com ' . number_format($winningPercentage, 2) . '% da permilagem dos votos expressos.</p>';
                    $chartHtml = $this->generateVoteChartImageHtml($results);
                    if ($chartHtml !== '') {
                        $topicsSections .= '<div style="margin:12px 0;">' . $chartHtml . '</div>';
                    }
                    if (!empty($results['votes_by_fraction'])) {
                        $vf = $results['votes_by_fraction'];
                        usort($vf, function ($a, $b) { return strcmp($a['fraction_identifier'] ?? '', $b['fraction_identifier'] ?? ''); });
                        $topicsSections .= '<p><strong>Registo de votações por fração</strong></p>';
                        $topicsSections .= '<table style="width:100%; border-collapse:collapse; margin:10px 0;"><thead><tr style="background-color:#e9ecef;"><th style="padding:8px; border:1px solid #ddd;">Fração</th><th style="padding:8px; border:1px solid #ddd;">Condómino</th><th style="padding:8px; border:1px solid #ddd;">Voto</th><th style="padding:8px; border:1px solid #ddd;">Permilagem</th><th style="padding:8px; border:1px solid #ddd;">Observações</th></tr></thead><tbody>';
                        foreach ($vf as $row) {
                            $obs = isset($row['notes']) && trim((string)$row['notes']) !== '' ? htmlspecialchars($row['notes']) : '—';
                            $voto = $row['vote_option'] ?? $row['vote_value'] ?? '—';
                            $perm = isset($row['fraction_millage']) ? $row['fraction_millage'] . '‰' : '—';
                            $topicsSections .= '<tr><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['fraction_identifier'] ?? '') . '</td><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['user_name'] ?? '') . '</td><td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($voto) . '</td><td style="padding:8px; border:1px solid #ddd;">' . $perm . '</td><td style="padding:8px; border:1px solid #ddd;">' . $obs . '</td></tr>';
                        }
                        $topicsSections .= '</tbody></table>';
                    }
                } else {
                    $topicsSections .= '<p>Foi apresentado o tema para discussão.</p>';
                    $topicsSections .= '<p>Após análise e esclarecimentos, foi deliberado:</p>';
                    $topicsSections .= '<p>☐ Aprovado</p>';
                    $topicsSections .= '<p>☐ Rejeitado</p>';
                    $topicsSections .= '<p>por maioria de ______% do valor do prédio.</p>';
                }

                $topicsSections .= '</div>';
                $topicNumber++;
            }

            while ($topicNumber <= 6) {
                $emoji = $emojiNumbers[$topicNumber - 3] ?? '•';
                $pointTitle = '';
                $pointContent = '';
                
                switch ($topicNumber) {
                    case 3:
                        $pointTitle = 'Aprovação das contas';
                        $pointContent = '<p>Foram apresentadas as contas referentes ao período de ____ / ____ / ______ a ____ / ____ / ______, no montante total de € __________________.</p>';
                        $pointContent .= '<p>Após análise e esclarecimentos, as contas foram:</p>';
                        $pointContent .= '<p>☐ Aprovadas</p>';
                        $pointContent .= '<p>☐ Rejeitadas</p>';
                        $pointContent .= '<p>por maioria de ______% do valor do prédio.</p>';
                        break;
                    case 4:
                        $pointTitle = 'Aprovação do orçamento';
                        $pointContent = '<p>Foi apresentado o orçamento para o novo exercício, no valor global de € __________________.</p>';
                        $pointContent .= '<p>O mesmo foi:</p>';
                        $pointContent .= '<p>☐ Aprovado</p>';
                        $pointContent .= '<p>☐ Rejeitado</p>';
                        $pointContent .= '<p>por maioria de ______%.</p>';
                        break;
                    case 5:
                        $pointTitle = 'Fixação das quotas';
                        $pointContent = '<p>Foi aprovado que as quotas de condomínio passam a ser de € __________ por permilagem/unidade, a pagar com a seguinte periodicidade:</p>';
                        $pointContent .= '<p>☐ Mensal</p>';
                        $pointContent .= '<p>☐ Bimestral</p>';
                        $pointContent .= '<p>☐ Trimestral</p>';
                        $pointContent .= '<p>☐ Outra: _______________________</p>';
                        break;
                    case 6:
                        $pointTitle = 'Administração do condomínio';
                        $pointContent = '<p>Foi deliberado:</p>';
                        $pointContent .= '<p>☐ Manter o atual administrador: ____________________________</p>';
                        $pointContent .= '<p>☐ Nomear novo administrador: _____________________________</p>';
                        $pointContent .= '<p>com mandato até ____ / ____ / ______.</p>';
                        break;
                }
                
                if (!empty($pointTitle)) {
                    $topicsSections .= '<div class="section">';
                    $topicsSections .= '<div class="section-number">' . $emoji . ' Ponto ' . ($topicNumber - 2) . ' – ' . $pointTitle . '</div>';
                    $topicsSections .= $pointContent;
                    $topicsSections .= '</div>';
                }
                $topicNumber++;
            }
        }
        
        if (!isset($otherTopics)) {
            $otherTopics = '';
            $otherDecisions = '';
            if (count($topics) > 4) {
                $remainingTopics = array_slice($topics, 4);
                foreach ($remainingTopics as $topic) {
                    $otherTopics .= '<p>• ' . htmlspecialchars($topic['title']) . '</p>';
                    if (!empty($topic['description'])) {
                        $otherTopics .= '<p style="margin-left: 20px;">' . htmlspecialchars($topic['description']) . '</p>';
                    }
                }
            }
        }
        
        if (!isset($endTime)) {
            $endTime = date('H:i');
            if (!empty($assembly['ended_at'])) {
                $endTime = date('H:i', strtotime($assembly['ended_at']));
            }
        }

            // Append Outros and Encerramento to topics_sections (old path)
            $ot = $otherTopics ?? '';
            $od = $otherDecisions ?? '';
            $topicsSections .= '<div class="section"><div class="section-number">7️⃣ Ponto 5 – Outros assuntos</div><p>Foram discutidos os seguintes temas:</p><p>' . ($ot ?: '<p>Não foram discutidos outros assuntos.</p>') . '</p><p>Tendo sido deliberado:</p><p>' . ($od ?: '<p>Não foram tomadas outras deliberações.</p>') . '</p></div>';
            $topicsSections .= '<div class="section"><div class="section-number">8️⃣ Encerramento</div><p>Nada mais havendo a tratar, foi a reunião encerrada pelas <span class="underline">' . htmlspecialchars($endTime) . '</span> horas, sendo lavrada a presente acta que, depois de lida e aprovada, vai ser assinada por todos os presentes.</p></div>';
        }
        
        $template = str_replace('{{assembly_agenda}}', $agendaFormatted, $template);
        $template = str_replace('{{topics_sections}}', $topicsSections, $template);
        $template = str_replace('{{generation_date}}', $generationDate, $template);
        
        return $template;
    }

    /**
     * Generate minutes PDF from HTML content
     */
    public function generateMinutesPdf(string $htmlContent, int $assemblyId): string
    {
        // Use DomPDF to generate PDF
        // Autoload should already be loaded via composer, but ensure it's available
        if (!class_exists('\Dompdf\Dompdf')) {
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }
        
        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($htmlContent);
            
            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');
            
            // Render PDF
            $dompdf->render();
            
            // Generate filename
            $filename = 'minutes_approved_' . $assemblyId . '_' . time() . '.pdf';
            $filepath = __DIR__ . '/../../storage/documents/' . $filename;
            
            $dir = dirname($filepath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Save PDF to file
            file_put_contents($filepath, $dompdf->output());
            
            return $filename;
        } catch (\Exception $e) {
            // Fallback to HTML if PDF generation fails
            error_log("PDF generation error: " . $e->getMessage());
            
            $filename = 'minutes_approved_' . $assemblyId . '_' . time() . '.html';
            $filepath = __DIR__ . '/../../storage/documents/' . $filename;
            
            $dir = dirname($filepath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            file_put_contents($filepath, $htmlContent);
            
            return $filename;
        }
    }

    /**
     * Generate receipt HTML
     */
    public function generateReceiptReceipt(array $fee, array $fraction, array $condominium, array $payment = null, string $type = 'partial'): string
    {
        // Get template ID (null means default template, which will be handled by getTemplatePath)
        $condominiumModel = new \App\Models\Condominium();
        $templateId = $condominiumModel->getDocumentTemplate($condominium['id']);
        if ($templateId === null) {
            $templateId = 1; // Default template
        }
        
        // Get logo path
        $logoPath = $condominiumModel->getLogoPath($condominium['id']);
        
        // Load template
        $templatePath = $this->getTemplatePath($templateId, 'receipt');
        $template = file_get_contents($templatePath);
        
        $period = '';
        if ($fee['period_month']) {
            $months = [
                1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
            ];
            $period = $months[$fee['period_month']] . '/' . $fee['period_year'];
        } else {
            $period = $fee['period_year'];
        }

        $amount = '€' . number_format((float)$fee['amount'], 2, ',', '.');
        $receiptTypeLabel = 'Recibo de Quota';

        $condominiumName = htmlspecialchars($condominium['name'] ?? '');
        $condominiumAddress = htmlspecialchars($condominium['address'] ?? '');
        $condominiumNif = htmlspecialchars($condominium['nif'] ?? '');
        $fractionIdentifier = htmlspecialchars($fraction['identifier'] ?? '');
        $feeReference = htmlspecialchars($fee['reference'] ?? '');

        // Get payment information if not provided
        $paymentDate = null;
        $paymentMethod = null;
        $paymentMethodLabel = null;
        
        if ($payment) {
            $paymentDate = $payment['payment_date'] ?? null;
            $paymentMethod = $payment['payment_method'] ?? null;
        } else {
            // Fetch the most recent payment for this fee
            try {
                global $db;
                if ($db) {
                    $stmt = $db->prepare("
                        SELECT payment_date, payment_method 
                        FROM fee_payments 
                        WHERE fee_id = :fee_id 
                        ORDER BY payment_date DESC, created_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([':fee_id' => $fee['id']]);
                    $latestPayment = $stmt->fetch();
                    if ($latestPayment) {
                        $paymentDate = $latestPayment['payment_date'];
                        $paymentMethod = $latestPayment['payment_method'];
                    }
                }
            } catch (\Exception $e) {
                // If database access fails, continue without payment info
                error_log("Error fetching payment info for receipt: " . $e->getMessage());
            }
        }
        
        // Map payment method to label
        $paymentMethodLabels = [
            'multibanco' => 'Multibanco',
            'mbway' => 'MB Way',
            'transfer' => 'Transferência Bancária',
            'cash' => 'Dinheiro',
            'card' => 'Cartão',
            'sepa' => 'SEPA'
        ];
        $paymentMethodLabel = $paymentMethod ? ($paymentMethodLabels[$paymentMethod] ?? ucfirst($paymentMethod)) : null;
        
        // Prepare payment info HTML (compact version)
        $paymentInfo = '';
        if ($paymentDate || $paymentMethodLabel) {
            $paymentInfo = '<div class="info-box" style="padding: 4px; margin: 4px 0;"><h2 style="font-size: 9px; margin-bottom: 2px; padding-bottom: 1px;">Dados do Pagamento</h2>';
            if ($paymentDate) {
                $paymentInfo .= '<div class="info-row" style="margin: 1px 0; padding: 1px 0;"><span class="info-label" style="font-size: 8pt;">Data de Pagamento:</span><span class="info-value" style="font-size: 8pt;">' . date('d/m/Y', strtotime($paymentDate)) . '</span></div>';
            }
            if ($paymentMethodLabel) {
                $paymentInfo .= '<div class="info-row" style="margin: 1px 0; padding: 1px 0;"><span class="info-label" style="font-size: 8pt;">Meio de Pagamento:</span><span class="info-value" style="font-size: 8pt;">' . htmlspecialchars($paymentMethodLabel) . '</span></div>';
            }
            $paymentInfo .= '</div>';
        }
        
        // Prepare NIF row
        $nifRow = '';
        if ($condominiumNif) {
            $nifRow = '<div class="info-row"><span class="info-label">NIF:</span><span class="info-value">' . $condominiumNif . '</span></div>';
        }
        
        // Replace placeholders in template
        $template = str_replace('{{LOGO_HTML}}', $this->getLogoHtml($logoPath), $template);
        $template = str_replace('{{RECEIPT_NUMBER}}', '{{RECEIPT_NUMBER}}', $template); // Keep placeholder for later replacement
        $template = str_replace('{{CONDOMINIUM_NAME}}', $condominiumName, $template);
        $template = str_replace('{{CONDOMINIUM_ADDRESS}}', $condominiumAddress, $template);
        $template = str_replace('{{CONDOMINIUM_NIF_ROW}}', $nifRow, $template);
        $template = str_replace('{{FRACTION_IDENTIFIER}}', $fractionIdentifier, $template);
        $template = str_replace('{{PERIOD}}', $period, $template);
        $template = str_replace('{{FEE_REFERENCE}}', $feeReference, $template);
        $template = str_replace('{{DUE_DATE}}', $fee['due_date'] ? date('d/m/Y', strtotime($fee['due_date'])) : '-', $template);
        $template = str_replace('{{AMOUNT}}', $amount, $template);
        $template = str_replace('{{PAYMENT_INFO}}', $paymentInfo, $template);
        $template = str_replace('{{GENERATION_DATE}}', date('d/m/Y H:i'), $template);
        
        return $template;
    }

    /**
     * Generate receipt PDF from HTML content
     */
    public function generateReceiptPdf(string $htmlContent, int $receiptId, string $receiptNumber, int $condominiumId): string
    {
        // Replace receipt number placeholder
        $htmlContent = str_replace('{{RECEIPT_NUMBER}}', $receiptNumber, $htmlContent);

        // Use DomPDF to generate PDF
        if (!class_exists('\Dompdf\Dompdf')) {
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }
        
        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($htmlContent);
            
            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');
            
            // Render PDF
            $dompdf->render();
            
            // Create folder structure: condominiums/{condominium_id}/receipts/{year}/{month}/
            $year = date('Y');
            $month = date('m');
            $basePath = __DIR__ . '/../../storage';
            $receiptsDir = $basePath . '/condominiums/' . $condominiumId . '/receipts/' . $year . '/' . $month;
            
            if (!is_dir($receiptsDir)) {
                mkdir($receiptsDir, 0755, true);
            }
            
            // Generate filename
            $filename = 'receipt_' . $receiptId . '_' . time() . '.pdf';
            $filepath = $receiptsDir . '/' . $filename;
            
            // Save PDF to file
            file_put_contents($filepath, $dompdf->output());
            
            // Return relative path from storage root
            return 'condominiums/' . $condominiumId . '/receipts/' . $year . '/' . $month . '/' . $filename;
        } catch (\Exception $e) {
            // Fallback to HTML if PDF generation fails
            error_log("PDF generation error: " . $e->getMessage());
            
            $year = date('Y');
            $month = date('m');
            $basePath = __DIR__ . '/../../storage';
            $receiptsDir = $basePath . '/condominiums/' . $condominiumId . '/receipts/' . $year . '/' . $month;
            
            if (!is_dir($receiptsDir)) {
                mkdir($receiptsDir, 0755, true);
            }
            
            $filename = 'receipt_' . $receiptId . '_' . time() . '.html';
            $filepath = $receiptsDir . '/' . $filename;
            
            file_put_contents($filepath, $htmlContent);
            
            return 'condominiums/' . $condominiumId . '/receipts/' . $year . '/' . $month . '/' . $filename;
        }
    }
}





