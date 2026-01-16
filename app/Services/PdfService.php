<?php

namespace App\Services;

class PdfService
{
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
        $date = date('d/m/Y', strtotime($assembly['scheduled_date']));
        $time = date('H:i', strtotime($assembly['scheduled_date']));
        // Map type for display
        $type = ($assembly['type'] === 'extraordinary' || $assembly['type'] === 'extraordinaria') ? 'Extraordinária' : 'Ordinária';
        $quorum = $assembly['quorum_percentage'] ?? 50;
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Convocatória de Assembleia</title>
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
                .header .subtitle {
                    font-size: 14px;
                    color: #666;
                    margin-top: 10px;
                }
                .content { 
                    line-height: 1.8;
                    margin-bottom: 30px;
                }
                .info-box {
                    background-color: #f9f9f9;
                    border-left: 4px solid #007bff;
                    padding: 15px;
                    margin: 20px 0;
                }
                .info-box p {
                    margin: 5px 0;
                }
                .info-label {
                    font-weight: bold;
                    display: inline-block;
                    width: 150px;
                }
                .description {
                    margin-top: 20px;
                    padding: 15px;
                    background-color: #f5f5f5;
                    border-radius: 5px;
                }
                .footer { 
                    margin-top: 50px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    font-size: 11px;
                    color: #666;
                    text-align: center;
                }
                .quorum-info {
                    background-color: #e7f3ff;
                    padding: 10px;
                    border-radius: 5px;
                    margin: 15px 0;
                }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>CONVOCATÓRIA DE ASSEMBLEIA</h1>
                <div class='subtitle'>Sistema de Gestão de Condomínios</div>
            </div>
            <div class='content'>
                <div class='info-box'>
                    <p><span class='info-label'>Título:</span> {$assembly['title']}</p>
                    <p><span class='info-label'>Tipo:</span> {$type}</p>
                    <p><span class='info-label'>Data:</span> {$date}</p>
                    <p><span class='info-label'>Hora:</span> {$time}</p>
                    <p><span class='info-label'>Local:</span> " . ($assembly['location'] ?? 'A definir') . "</p>
                    <p><span class='info-label'>Quórum:</span> {$quorum}%</p>
                </div>
                
                <div class='quorum-info'>
                    <strong>Nota:</strong> Para que a assembleia seja válida, é necessário que esteja presente um mínimo de {$quorum}% da permilagem total do condomínio.
                </div>
                
                <div class='description'>
                    <h3 style='margin-top: 0;'>Ordem de Trabalhos:</h3>
                    <div>" . nl2br(htmlspecialchars($assembly['description'] ?? $assembly['agenda'] ?? 'A definir na assembleia')) . "</div>
                </div>
                
                <p style='margin-top: 30px;'><strong>Instruções:</strong></p>
                <ul>
                    <li>Por favor, confirme a sua presença através do sistema online</li>
                    <li>Em caso de impossibilidade de presença, pode nomear um representante mediante procuração</li>
                    <li>A assembleia iniciará pontualmente no horário indicado</li>
                </ul>
            </div>
            <div class='footer'>
                <p>Esta convocatória foi gerada automaticamente pelo sistema de gestão de condomínios.</p>
                <p>Data de geração: " . date('d/m/Y H:i') . "</p>
            </div>
        </body>
        </html>";
    }

    /**
     * Get minutes HTML
     */
    protected function getMinutesHtml(array $assembly, array $attendees, array $votes): string
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
            $totalMillage += $millage;
            $typeLabel = $attendee['attendance_type'] === 'proxy' ? ' (por procuração)' : '';
            $attendeesList .= "<tr><td>{$attendee['fraction_identifier']}</td><td>{$attendee['user_name']}</td><td>{$millage}‰</td><td>{$typeLabel}</td></tr>";
        }
        
        // Get vote topics and results
        global $db;
        $voteTopicModel = new \App\Models\VoteTopic();
        $voteModel = new \App\Models\Vote();
        $topics = $voteTopicModel->getByAssembly($assembly['id']);
        
        $votesSection = '';
        if (!empty($topics)) {
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
                <div class='minutes-text'>" . nl2br(htmlspecialchars($assembly['description'] ?? $assembly['agenda'] ?? '')) . "</div>
                
                {$votesSection}
                
                " . (!empty($minutesText) ? "<h3>Resumo e Decisões</h3><div class='minutes-text'>" . nl2br(htmlspecialchars($minutesText)) . "</div>" : "") . "
            </div>
            <div class='footer'>
                <p>Atas geradas automaticamente pelo sistema de gestão de condomínios.</p>
                <p>Data de geração: " . date('d/m/Y H:i') . "</p>
            </div>
        </body>
        </html>";
    }

    /**
     * Populate minutes template with assembly data
     */
    public function populateMinutesTemplate(array $assembly, array $attendees, array $topics, array $voteResults): string
    {
        // Load template base
        $templatePath = __DIR__ . '/../Templates/minutes_template.html';
        if (!file_exists($templatePath)) {
            throw new \Exception("Template file not found: {$templatePath}");
        }
        
        $template = file_get_contents($templatePath);
        
        // Get condominium info
        $condominiumModel = new \App\Models\Condominium();
        $condominium = $condominiumModel->findById($assembly['condominium_id']);
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
            $totalMillage += $millage;
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
        
        $template = str_replace('{{assembly_agenda}}', $agendaFormatted, $template);
        $template = str_replace('{{topics_sections}}', $topicsSections, $template);
        $template = str_replace('{{other_topics}}', $otherTopics ?: '<p>Não foram discutidos outros assuntos.</p>', $template);
        $template = str_replace('{{other_decisions}}', $otherDecisions ?: '<p>Não foram tomadas outras deliberações.</p>', $template);
        $template = str_replace('{{end_time}}', $endTime, $template);
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

        $amount = $fee['amount'];
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

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Recibo de Quota</title>
            <style>
                @page { margin: 1cm; }
                body { 
                    font-family: 'Times New Roman', serif; 
                    margin: 0;
                    padding: 5px;
                    line-height: 1.2;
                    color: #333;
                    font-size: 9pt;
                }
                .header { 
                    text-align: center; 
                    margin-bottom: 8px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 5px;
                }
                .header h1 { 
                    color: #1a1a1a;
                    font-size: 16px;
                    margin: 0;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    font-weight: bold;
                }
                .header .subtitle {
                    font-size: 10px;
                    color: #666;
                    margin-top: 2px;
                    font-weight: normal;
                }
                .receipt-info {
                    margin: 8px 0;
                }
                .info-box {
                    background-color: #f9f9f9;
                    border: 1px solid #333;
                    padding: 6px;
                    margin: 6px 0;
                }
                .info-box h2 {
                    margin-top: 0;
                    font-size: 11px;
                    border-bottom: 1px solid #333;
                    padding-bottom: 2px;
                    margin-bottom: 4px;
                }
                .info-row {
                    display: flex;
                    margin: 2px 0;
                    padding: 2px 0;
                    border-bottom: 1px dotted #ccc;
                }
                .info-label {
                    font-weight: bold;
                    width: 120px;
                    flex-shrink: 0;
                    font-size: 8.5pt;
                }
                .info-value {
                    flex: 1;
                    font-size: 8.5pt;
                }
                .amount-box {
                    background-color: #e7f3ff;
                    border: 2px solid #007bff;
                    padding: 8px;
                    margin: 8px 0;
                    text-align: center;
                }
                .amount-box .label {
                    font-size: 10px;
                    color: #666;
                    margin-bottom: 2px;
                }
                .amount-box .value {
                    font-size: 20px;
                    font-weight: bold;
                    color: #007bff;
                }
                .footer { 
                    margin-top: 10px;
                    padding-top: 5px;
                    border-top: 1px solid #333;
                    font-size: 7px;
                    color: #666;
                    text-align: center;
                }
                .signature-section {
                    margin-top: 12px;
                    display: flex;
                    justify-content: space-between;
                }
                .signature-box {
                    width: 45%;
                    text-align: center;
                    border-top: 1px solid #333;
                    padding-top: 3px;
                    margin-top: 20px;
                    font-size: 8pt;
                }
                .receipt-number {
                    text-align: right;
                    font-size: 9px;
                    color: #666;
                    margin-bottom: 5px;
                }
            </style>
        </head>
        <body>
            <div class='receipt-number'>
                <strong>Nº Recibo:</strong> {{RECEIPT_NUMBER}}
            </div>
            <div class='header'>
                <h1>RECIBO DE QUOTA</h1>
            </div>
            
            <div class='info-box'>
                <h2>Dados do Condomínio</h2>
                <div class='info-row'>
                    <span class='info-label'>Condomínio:</span>
                    <span class='info-value'>{$condominiumName}</span>
                </div>
                <div class='info-row'>
                    <span class='info-label'>Morada:</span>
                    <span class='info-value'>{$condominiumAddress}</span>
                </div>
                " . ($condominiumNif ? "<div class='info-row'>
                    <span class='info-label'>NIF:</span>
                    <span class='info-value'>{$condominiumNif}</span>
                </div>" : "") . "
            </div>

            <div class='info-box'>
                <h2>Dados da Quota</h2>
                <div class='info-row'>
                    <span class='info-label'>Fração:</span>
                    <span class='info-value'><strong>{$fractionIdentifier}</strong></span>
                </div>
                <div class='info-row'>
                    <span class='info-label'>Período:</span>
                    <span class='info-value'>{$period}</span>
                </div>
                <div class='info-row'>
                    <span class='info-label'>Referência:</span>
                    <span class='info-value'>{$feeReference}</span>
                </div>
                <div class='info-row'>
                    <span class='info-label'>Data de Vencimento:</span>
                    <span class='info-value'>" . ($fee['due_date'] ? date('d/m/Y', strtotime($fee['due_date'])) : '-') . "</span>
                </div>
            </div>

            <div class='amount-box'>
                <div class='label'>Valor Recebido</div>
                <div class='value'>€" . number_format((float)$amount, 2, ',', '.') . "</div>
            </div>

            " . ($paymentDate || $paymentMethodLabel ? "<div class='info-box'>
                <h2>Dados do Pagamento</h2>
                " . ($paymentDate ? "<div class='info-row'>
                    <span class='info-label'>Data de Pagamento:</span>
                    <span class='info-value'>" . date('d/m/Y', strtotime($paymentDate)) . "</span>
                </div>" : "") . "
                " . ($paymentMethodLabel ? "<div class='info-row'>
                    <span class='info-label'>Meio de Pagamento:</span>
                    <span class='info-value'>{$paymentMethodLabel}</span>
                </div>" : "") . "
            </div>" : "") . "

            <div class='info-box' style='background-color: #d4edda; border-color: #28a745; padding: 4px;'>
                <p style='margin: 0; text-align: center; font-size: 10px;'><strong>Quota totalmente liquidada</strong></p>
            </div>

            <div class='footer'>
                <p style='margin: 2px 0;'>Este recibo foi gerado automaticamente pelo sistema de gestão de condomínios.</p>
                <p style='margin: 2px 0;'>Data de geração: " . date('d/m/Y H:i') . "</p>
                <p style='margin-top: 4px; margin-bottom: 0;'>Este documento tem valor fiscal e comprovativo do pagamento da quota de condomínio.</p>
            </div>

            <div class='signature-section'>
                <div class='signature-box'>
                    <p style='margin: 0;'><strong>Condómino</strong></p>
                </div>
                <div class='signature-box'>
                    <p style='margin: 0;'><strong>Administrador do Condomínio</strong></p>
                </div>
            </div>
        </body>
        </html>";
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





