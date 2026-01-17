# Logs Directory

Este diretório contém os logs da aplicação.

## Ficheiros de Log

- `php_error.log` - Logs de erros e mensagens do PHP (usando `error_log()`)
- Os logs são criados automaticamente quando a aplicação é executada em modo de desenvolvimento

## Visualizar Logs

### No Windows (PowerShell):
```powershell
Get-Content logs\php_error.log -Tail 50 -Wait
```

### No Windows (CMD):
```cmd
type logs\php_error.log
```

### No Windows (Notepad):
Abra o ficheiro `logs\php_error.log` diretamente no Notepad ou outro editor de texto.

## Logs do XAMPP

Os logs do Apache também estão disponíveis em:
- `C:\xampp\apache\logs\error.log` - Logs de erros do Apache
- `C:\xampp\php\logs\php_error_log` - Logs do PHP (se configurado)

## Limpar Logs

Para limpar os logs, simplesmente apague o conteúdo do ficheiro ou o ficheiro completo.
