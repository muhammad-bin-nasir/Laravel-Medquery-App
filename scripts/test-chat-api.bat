@echo off
setlocal EnableExtensions DisableDelayedExpansion

pushd "%~dp0.." >nul 2>&1
if errorlevel 1 (
  echo Failed to map working directory from UNC path.
  exit /b 1
)

set "BASE_URL=http://127.0.0.1:8000"
set "EMAIL=admin@acme.test"
set "PASSWORD=Admin@12345"
set "BUSINESS_CLIENT_ID=acme"
set "WORKSPACE_ID=test"
set "USER_ID=admin@acme.test"
set "QUERY=Explain blood pressure in 3 lines."

echo [1/4] Logging in...
set "LOGIN_JSON={\"email\":\"%EMAIL%\",\"password\":\"%PASSWORD%\"}"
set "LOGIN_TMP=%TEMP%\test-app-login.json"
set "TOKEN_TMP=%TEMP%\test-app-token.txt"

curl.exe -s -X POST "%BASE_URL%/api/auth/login" -H "Content-Type: application/json" -d "%LOGIN_JSON%" > "%LOGIN_TMP%"

powershell -NoProfile -Command "$d = Get-Content -Raw '%LOGIN_TMP%' | ConvertFrom-Json; $t = ''; if($d.session.access_token){$t = $d.session.access_token.Trim()}; [System.IO.File]::WriteAllText('%TOKEN_TMP%', $t)"
set /p TOKEN=<"%TOKEN_TMP%"

if "%TOKEN%"=="" (
  echo Login failed. Response:
  type "%LOGIN_TMP%"
  del /q "%LOGIN_TMP%" >nul 2>&1
  del /q "%TOKEN_TMP%" >nul 2>&1
  popd >nul 2>&1
  exit /b 1
)

del /q "%LOGIN_TMP%" >nul 2>&1
del /q "%TOKEN_TMP%" >nul 2>&1

echo Token acquired.
echo.

echo [2/4] Testing /api/chat/generate...
set "CHAT_ID=test-chat-1"
set "CHAT_TITLE=Blood pressure test"
set "GENERATE_JSON={\"business_client_id\":\"%BUSINESS_CLIENT_ID%\",\"workspace_id\":\"%WORKSPACE_ID%\",\"user_id\":\"%USER_ID%\",\"query\":\"%QUERY%\",\"chat_id\":\"%CHAT_ID%\",\"chat_title\":\"%CHAT_TITLE%\"}"
curl.exe -s -X POST "%BASE_URL%/api/chat/generate" -H "Authorization: Bearer %TOKEN%" -H "Content-Type: application/json" -d "%GENERATE_JSON%"
echo.
echo.

echo [3/4] Testing /api/chat/headers/me...
curl.exe -s -X GET "%BASE_URL%/api/chat/headers/me" -H "Authorization: Bearer %TOKEN%"
echo.
echo.

echo [4/4] Testing /api/chat/stream (SSE)...
set "STREAM_JSON={\"business_client_id\":\"%BUSINESS_CLIENT_ID%\",\"workspace_id\":\"%WORKSPACE_ID%\",\"user_id\":\"%USER_ID%\",\"query\":\"%QUERY%\"}"
curl.exe -N -X POST "%BASE_URL%/api/chat/stream" -H "Authorization: Bearer %TOKEN%" -H "Content-Type: application/json" -d "%STREAM_JSON%"
echo.
echo Done.

popd >nul 2>&1
endlocal
