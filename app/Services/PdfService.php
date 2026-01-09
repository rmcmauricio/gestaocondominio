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
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Convocatória de Assembleia</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                .header { text-align: center; margin-bottom: 30px; }
                .content { line-height: 1.6; }
                .footer { margin-top: 40px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>CONVOCATÓRIA DE ASSEMBLEIA</h1>
            </div>
            <div class='content'>
                <p><strong>Título:</strong> {$assembly['title']}</p>
                <p><strong>Data:</strong> {$date}</p>
                <p><strong>Hora:</strong> {$time}</p>
                <p><strong>Local:</strong> " . ($assembly['location'] ?? 'A definir') . "</p>
                <p><strong>Tipo:</strong> " . ucfirst($assembly['type']) . "</p>
                <p><strong>Descrição:</strong></p>
                <p>" . nl2br($assembly['description'] ?? '') . "</p>
            </div>
            <div class='footer'>
                <p>Esta convocatória foi gerada automaticamente pelo sistema MeuPrédio.</p>
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
        
        $attendeesList = '';
        foreach ($attendees as $attendee) {
            $type = $attendee['attendance_type'] === 'proxy' ? ' (por procuração)' : '';
            $attendeesList .= "<li>{$attendee['fraction_identifier']} - {$attendee['user_name']}{$type}</li>";
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Atas da Assembleia</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                .header { text-align: center; margin-bottom: 30px; }
                .content { line-height: 1.6; }
                ul { list-style-type: none; padding-left: 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>ATAS DA ASSEMBLEIA</h1>
            </div>
            <div class='content'>
                <p><strong>Título:</strong> {$assembly['title']}</p>
                <p><strong>Data:</strong> {$date}</p>
                <p><strong>Presentes:</strong></p>
                <ul>{$attendeesList}</ul>
                <p><strong>Assuntos discutidos:</strong></p>
                <p>" . nl2br($assembly['description'] ?? '') . "</p>
            </div>
        </body>
        </html>";
    }
}





