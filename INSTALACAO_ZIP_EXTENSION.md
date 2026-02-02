# Como Ativar a Extensão ZIP no XAMPP

Para importar ficheiros Excel (.xlsx, .xls), é necessário ter a extensão PHP `zip` ativada.

## Passos para Ativar:

1. **Localize o ficheiro php.ini**
   - No XAMPP, normalmente está em: `C:\xampp\php\php.ini`
   - Ou abra o XAMPP Control Panel → Apache → Config → PHP (php.ini)

2. **Abra o ficheiro php.ini num editor de texto** (Notepad++, VS Code, etc.)

3. **Procure pela linha:**
   ```ini
   ;extension=zip
   ```
   
   Ou procure por "zip" usando Ctrl+F

4. **Remova o ponto e vírgula (;) no início da linha:**
   ```ini
   extension=zip
   ```

5. **Salve o ficheiro**

6. **Reinicie o Apache no XAMPP Control Panel**
   - Clique em "Stop" no Apache
   - Aguarde alguns segundos
   - Clique em "Start" novamente

7. **Verifique se está ativado:**
   - Crie um ficheiro `test.php` na pasta `htdocs` com:
   ```php
   <?php
   phpinfo();
   ?>
   ```
   - Abra no browser: `http://localhost/test.php`
   - Procure por "zip" na página
   - Deve aparecer uma secção "zip" se estiver ativado

## Alternativa: Verificar via linha de comando

Abra o terminal (CMD) e execute:
```bash
php -m | findstr zip
```

Se aparecer "zip", está ativado. Se não aparecer nada, precisa ativar seguindo os passos acima.

## Nota

Se mesmo após seguir estes passos a extensão não funcionar, verifique:
- Se está a editar o php.ini correto (pode haver múltiplos ficheiros php.ini)
- Se reiniciou o Apache após a alteração
- Se a extensão zip.dll existe na pasta `C:\xampp\php\ext\`
