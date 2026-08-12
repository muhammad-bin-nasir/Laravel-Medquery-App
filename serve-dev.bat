@echo off
cd /d "%~dp0"
set PHPRC=%~dp0php-local.ini
php artisan serve --host=127.0.0.1 --port=8001
