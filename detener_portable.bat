@echo off
title Detener Entorno Portable
color 0C

echo ======================================================================
echo    DETENIENDO SERVICIOS PORTABLES DE IMSS CONSERVACION
echo ======================================================================
echo.

echo [*] Cerrando servidor PHP...
taskkill /f /im php.exe >nul 2>&1

echo [*] Cerrando base de datos portable...
taskkill /f /im mysqld.exe >nul 2>&1

echo.
echo [OK] Todos los servicios han sido detenidos correctamente.
echo No queda ningun proceso activo ni residente en su equipo.
echo.
pause
