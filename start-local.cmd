@echo off
rem Local test run: web panel + Telegram bot (+ fake router with "fake" argument).
rem Close the opened windows to stop.
set PHP=C:\Users\Public\tim-routers\php\php.exe
cd /d "%~dp0server"
start "tim-routers: web panel (port 8080)" cmd /k "chcp 65001 >nul & %PHP% -S 0.0.0.0:8080 -t . dev-router.php"
start "tim-routers: Telegram bot" cmd /k "chcp 65001 >nul & %PHP% poll.php"
if /i "%~1"=="fake" start "tim-routers: fake router" cmd /k "chcp 65001 >nul & %PHP% ..\tools\fake-router.php run http://127.0.0.1:8080"
timeout /t 3 >nul
start "" http://127.0.0.1:8080/
