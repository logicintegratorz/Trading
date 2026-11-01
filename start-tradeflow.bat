@echo off
cd /d "%~dp0"
echo Starting Tradeflow at http://[::1]:8000
cd public
"..\.runtime\php.exe" -S "[::1]:8000" "..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"

