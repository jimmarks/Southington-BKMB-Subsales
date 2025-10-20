@echo off
echo Fixing Gradle configuration for React Native 0.64...
echo.

cd /d "%~dp0android"

echo Checking Gradle wrapper configuration...
if exist "gradle\wrapper\gradle-wrapper.properties" (
    echo Found gradle-wrapper.properties
    
    echo.
    echo Current configuration:
    type gradle\wrapper\gradle-wrapper.properties
    
    echo.
    echo Ensuring Gradle 7.5.1 is configured...
    
    (
    echo distributionBase=GRADLE_USER_HOME
    echo distributionPath=wrapper/dists
    echo distributionUrl=https\://services.gradle.org/distributions/gradle-7.5.1-all.zip
    echo zipStoreBase=GRADLE_USER_HOME
    echo zipStorePath=wrapper/dists
    ) > gradle\wrapper\gradle-wrapper.properties
    
    echo ✓ Gradle wrapper updated to version 7.5.1
) else (
    echo ✗ gradle-wrapper.properties not found!
    echo Make sure you're in the correct directory.
    pause
    exit /b 1
)

echo.
echo Cleaning previous build artifacts...
if exist ".gradle" rmdir /s /q ".gradle"
if exist "app\build" rmdir /s /q "app\build"
if exist "build" rmdir /s /q "build"
echo ✓ Build cache cleaned

echo.
echo Configuration updated!
echo.
echo Next steps:
echo 1. Close Android Studio completely
echo 2. Reopen Android Studio
echo 3. Open the 'android' folder
echo 4. Let Gradle sync (may take a few minutes)
echo 5. Try building again
echo.
echo If you still get errors, try:
echo - File → Invalidate Caches and Restart
echo - Build → Clean Project → Rebuild Project
echo.
pause