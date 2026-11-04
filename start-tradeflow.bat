@echo off
cd /d "%~dp0"
echo Starting Tradeflow at http://127.0.0.1:8001
cd public
"..\.runtime\php.exe" -S "127.0.0.1:8001" "..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"

