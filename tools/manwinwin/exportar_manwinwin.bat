@echo off
setlocal
rem Exporta as tabelas do ManWinWin para um .zip que o MZ Manutencao importa
rem (Configuracoes > Importacao). O banco e aberto so para leitura.
rem Uso: arraste o ManWinWin.mdb sobre este arquivo, ou: exportar_manwinwin.bat "C:\caminho\ManWinWin.mdb"
set "MDB=%~1"
if "%MDB%"=="" set /p "MDB=Caminho do ManWinWin.mdb: "
if not defined MDB (echo Informe o arquivo .mdb. & pause & exit /b 1)
set "MDB=%MDB:"=%"
rem O driver Jet 4.0 (Access 2000-2003) so existe em 32 bits: tenta primeiro o PowerShell 32 bits.
set "PS32=%SystemRoot%\SysWOW64\WindowsPowerShell\v1.0\powershell.exe"
set "PS64=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
if not exist "%PS32%" set "PS32=%PS64%"
"%PS32%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0exportar_manwinwin.ps1" -Mdb "%MDB%"
if %errorlevel%==2 (
  echo Tentando o PowerShell 64 bits ^(driver ACE do Office 64 bits^)...
  "%PS64%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0exportar_manwinwin.ps1" -Mdb "%MDB%"
)
if %errorlevel%==2 (
  echo.
  echo Instale o "Microsoft Access Database Engine 2016 Redistributable" ^(gratuito, site da Microsoft^)
  echo e rode de novo. Na mesma versao de bits do Office, se houver Office instalado.
)
echo.
pause
