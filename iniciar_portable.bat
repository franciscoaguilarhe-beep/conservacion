@echo off
title IMSS Conservacion - Entorno Portable
color 0A

echo ======================================================================
echo    INICIANDO PLATAFORMA DE GESTION DE CONSERVACION IMSS (PORTABLE)
echo ======================================================================
echo.

REM 1. Verificar si PHP esta disponible en el PATH o en carpeta local /tools/php
set PHP_CMD=php
if exist "tools\php\php.exe" (
    set PHP_CMD=tools\php\php.exe
    echo [*] Usando PHP portable local (tools\php\php.exe)
) else (
    echo [*] Usando PHP del sistema
)

REM 2. Verificar si MySQL portable local existe para levantarlo
if exist "tools\mariadb\bin\mysqld.exe" (
    echo [*] Levantando base de datos MariaDB portable...
    start "" /B "tools\mariadb\bin\mysqld.exe" --console --datadir="tools\mariadb\data"
    timeout /t 2 /nobreak >nul
)

echo [*] Arrancando Servidor Web PHP integrado en http://localhost:8080 ...
echo [*] Directorio raiz: public/
echo.
echo Presione CTRL+C en esta ventana cuando desee detener el servidor.
echo ======================================================================

start http://localhost:8080

%PHP_CMD% -S localhost:8080 -t public
