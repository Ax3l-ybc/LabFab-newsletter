@echo off
setlocal
cd /d "%~dp0"
title Installation - Newsletter du LabFab

echo ==========================================================
echo    Installation de la newsletter du LabFab
echo    (a faire une seule fois, comptez 1 a 2 minutes)
echo ==========================================================
echo.

rem ---- 1. Outil "uv" (installe Python automatiquement) ----
where uv >nul 2>nul
if not errorlevel 1 goto uv_ok
echo [1/3] Installation de l'outil uv...
powershell -NoProfile -ExecutionPolicy Bypass -Command "irm https://astral.sh/uv/install.ps1 | iex"
set "PATH=%USERPROFILE%\.local\bin;%PATH%"
where uv >nul 2>nul
if errorlevel 1 (
    echo.
    echo ERREUR : l'outil uv n'a pas pu etre installe.
    echo Verifiez votre connexion internet puis relancez installer.bat.
    pause
    exit /b 1
)
:uv_ok
echo [1/3] Outil uv : OK

rem ---- 2. Python 3.12 dans un dossier .venv ----
echo [2/3] Preparation de Python...
if not exist ".venv\Scripts\python.exe" (
    uv venv .venv --python 3.12
    if errorlevel 1 goto erreur
)

rem ---- 3. Bibliotheques ----
echo [3/3] Telechargement des bibliotheques...
uv pip install --python .venv\Scripts\python.exe -r requirements.txt
if errorlevel 1 goto erreur

echo.
echo ==========================================================
echo    Installation terminee !
echo    Double-cliquez sur generer.bat pour creer la newsletter.
echo ==========================================================
pause
exit /b 0

:erreur
echo.
echo ERREUR pendant l'installation. Verifiez votre connexion internet
echo puis relancez installer.bat (il reprendra la ou il s'est arrete).
pause
exit /b 1
