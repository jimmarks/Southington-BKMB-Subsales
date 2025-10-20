# Android Studio Setup Guide for Windows

## Error: `native_modules.gradle` does not exist

This error occurs because the `node_modules` directory is missing on your Windows PC.

## Solution Steps:

### 1. Install Node.js (Required)
- Download **Node.js 16.x LTS** from https://nodejs.org/
- **IMPORTANT**: Use version 16.x, not 18+ or 20+ (React Native 0.64 compatibility)
- Install and restart your command prompt

### 2. Install npm Dependencies
Open Command Prompt in the project directory:
```cmd
cd C:\Users\jim\Downloads\Southington-BKMB-Subsales-main\mobile-app
npm install --legacy-peer-deps
```

### 3. Verify Installation
Check that this file exists:
```
C:\Users\jim\Downloads\Southington-BKMB-Subsales-main\mobile-app\node_modules\@react-native-community\cli-platform-android\native_modules.gradle
```

### 4. Alternative: Use Simplified Settings (If npm fails)
If npm installation continues to fail, use the backup settings file:

1. Rename `settings.gradle` to `settings.gradle.backup`
2. Rename `settings-simple.gradle` to `settings.gradle`
3. Try building in Android Studio

## Why This Happens
- Android Studio needs React Native CLI tools
- These tools are installed via npm in `node_modules`
- Without npm install, the native modules script is missing

## After Installing Dependencies
1. Close Android Studio
2. Reopen Android Studio
3. Open the `android` folder (not the whole project)
4. Let Gradle sync
5. Build → Generate Signed Bundle/APK