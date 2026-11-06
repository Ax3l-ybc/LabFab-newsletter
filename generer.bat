@echo off
setlocal
cd /d "%~dp0"
chcp 65001 >nul
title Newsletter du LabFab

if not exist ".venv\Scripts\python.exe" (
    echo La newsletter n'est pas encore installee : double-cliquez d'abord sur installer.bat
    pause
    exit /b 1
)

set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8

echo ==========================================================
echo    Newsletter du LabFab
echo ==========================================================
echo.
echo Pour quel mois ? Tapez par exemple 2026-12,
echo ou appuyez simplement sur Entree pour le mois en cours.
set "MOIS="
set /p "MOIS=Mois : "

set "OPTIONS="
if not "%MOIS%"=="" set "OPTIONS=--mois %MOIS%"
if exist "edito.txt" (
    echo Edito trouve : edito.txt sera ajoute en haut de la lettre.
    set "OPTIONS=%OPTIONS% --edito edito.txt"
)
echo.
echo Lecture du site labfab.fr...
echo.

".venv\Scripts\python.exe" -m newsletter %OPTIONS%
if errorlevel 1 (
    echo.
    echo ERREUR : la newsletter n'a pas pu etre generee. Lisez les messages ci-dessus.
    pause
    exit /b 1
)

rem Ouvre le dossier "sortie" pour retrouver le fichier HTML
if exist "sortie" start "" "sortie"

echo.
echo ==========================================================
echo    Le dossier "sortie" vient de s'ouvrir.
echo    - Double-cliquez sur le fichier .html pour voir la lettre.
echo    - Ouvrez-le avec le Bloc-notes pour copier le code
echo      a coller dans The Newsletter Plugin.
echo ==========================================================
pause
