# React Native 0.64 Compatibility Fix

## Problem
The error you encountered is due to React Native 0.64 using deprecated Gradle APIs that were removed in newer versions:

```
Cannot use @TaskAction annotation on method IncrementalTask.taskAction$gradle() because interface org.gradle.api.tasks.incremental.IncrementalTaskInputs is not a valid parameter to an action method.
```

## Solution Applied
I've downgraded the Gradle and Android Gradle Plugin versions to be compatible with React Native 0.64:

### Changes Made:
1. **Gradle Version**: Downgraded from 8.0 → 7.5.1
2. **Android Gradle Plugin**: Downgraded from 7.4.2 → 7.2.2

### Compatible Version Matrix for React Native 0.64:
- **Gradle**: 7.5.1 (max 7.6.x)
- **Android Gradle Plugin**: 7.2.2 (max 7.4.x)
- **Java**: 11 (already configured)
- **Node.js**: 16.x or 18.x (avoid Node.js 20+)

## Next Steps

### 1. Install Node.js (Required)
Since you don't have npm installed, you need Node.js first:

**For Windows:**
1. Go to https://nodejs.org/
2. Download Node.js 18.x LTS (avoid 20+ for React Native 0.64)
3. Run the installer
4. Restart your command prompt/Android Studio

### 2. Install Dependencies
After Node.js is installed, in your project directory:
```bash
cd /path/to/Southington-BKMB-Subsales-main/mobile-app
npm install --legacy-peer-deps
```

### 3. Android Studio Setup
1. Open **mobile-app/android/** folder in Android Studio
2. Let Gradle sync (it will download Gradle 7.5.1 automatically)
3. Create an AVD (Android Virtual Device)
4. Run the app

## Why This Happens
- React Native 0.64 was released in March 2021
- It uses older Gradle APIs that were deprecated/removed in newer versions
- Newer Android Studio versions default to newer Gradle versions
- The fix ensures compatibility by using versions that work together

## Alternative: Upgrade React Native
If you want to use modern tooling, consider upgrading to React Native 0.72+ (requires significant code changes).