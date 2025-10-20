@echo off
echo Installing React Native dependencies for Android Studio...
echo.
echo This script will:
echo 1. Install Node.js dependencies
echo 2. Set up React Native CLI tools
echo 3. Prepare project for Android Studio
echo.

if not exist node_modules (
    echo Installing npm dependencies with legacy peer deps...
    npm install --legacy-peer-deps
    if errorlevel 1 (
        echo.
        echo ERROR: npm install failed!
        echo.
        echo Please ensure:
        echo - Node.js 16.x is installed
        echo - You're in the mobile-app directory
        echo - Internet connection is available
        echo.
        pause
        exit /b 1
    )
) else (
    echo Dependencies already installed.
)

echo.
echo Checking React Native CLI tools...
if exist "node_modules\@react-native-community\cli-platform-android\native_modules.gradle" (
    echo ✓ React Native CLI tools found
    echo ✓ Project ready for Android Studio
    echo.
    echo Next steps:
    echo 1. Open Android Studio
    echo 2. Open the 'android' folder (not the whole project)
    echo 3. Let Gradle sync
    echo 4. Build your APK
) else (
    echo ✗ React Native CLI tools missing
    echo.
    echo Try running: npm install --legacy-peer-deps --force
    echo.
    echo If that fails, use the simplified settings.gradle:
    echo 1. Rename settings.gradle to settings.gradle.backup
    echo 2. Rename settings-simple.gradle to settings.gradle
)

echo.
pause