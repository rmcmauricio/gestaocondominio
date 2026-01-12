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
}





