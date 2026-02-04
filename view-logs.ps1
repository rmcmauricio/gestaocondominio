# Script para visualizar logs do PHP
# Uso: .\view-logs.ps1

$logFile = "logs\php_error.log"
$apacheLog = "C:\xampp\apache\logs\error.log"

Write-Host "=== Logs do PHP (Aplicação) ===" -ForegroundColor Green
if (Test-Path $logFile) {
    Write-Host "Últimas 50 linhas de $logFile`n" -ForegroundColor Yellow
    Get-Content $logFile -Tail 50
} else {
    Write-Host "Ficheiro de log não encontrado: $logFile" -ForegroundColor Red
    Write-Host "Os logs serão criados automaticamente quando houver erros ou mensagens de debug.`n" -ForegroundColor Yellow
}

Write-Host "`n=== Logs do Apache ===" -ForegroundColor Green
if (Test-Path $apacheLog) {
    Write-Host "Últimas 50 linhas de $apacheLog`n" -ForegroundColor Yellow
    Get-Content $apacheLog -Tail 50
} else {
    Write-Host "Ficheiro de log não encontrado: $apacheLog" -ForegroundColor Red
}

Write-Host "`n=== Para ver logs em tempo real ===" -ForegroundColor Cyan
Write-Host "Get-Content logs\php_error.log -Tail 50 -Wait" -ForegroundColor White
