# Android Studio Gradle Build Error Fix

## Error: `org.gradle.api.artifacts.Dependency` module() method

This error indicates a Gradle API compatibility issue with React Native 0.64.

## Solution Options:

### Option 1: Downgrade Gradle Wrapper (RECOMMENDED)
The issue might be that Windows downloaded a newer Gradle version. Let's force Gradle 7.5.1:

1. **Check your Gradle wrapper version:**
   ```
   C:\Users\jim\Downloads\Southington-BKMB-Subsales-main\mobile-app\android\gradle\wrapper\gradle-wrapper.properties
   ```

2. **Ensure it contains:**
   ```properties
   distributionUrl=https\://services.gradle.org/distributions/gradle-7.5.1-all.zip
   ```

3. **In Android Studio:**
   - File → Settings → Build, Execution, Deployment → Build Tools → Gradle
   - Select "Use Gradle from: 'gradle-wrapper.properties file'"
   - Clean and rebuild

### Option 2: Use Simplified Build File
If the main build.gradle has compatibility issues:

1. **Backup original:**
   ```cmd
   cd C:\Users\jim\Downloads\Southington-BKMB-Subsales-main\mobile-app\android\app
   copy build.gradle build.gradle.backup
   copy build-simple.gradle build.gradle
   ```

2. **Try building again**

### Option 3: Clean Everything
Sometimes cached files cause issues:

1. **In Android Studio:**
   - Build → Clean Project
   - Build → Rebuild Project

2. **Delete caches:**
   ```cmd
   rmdir /s android\.gradle
   rmdir /s android\app\build
   ```

3. **Sync project again**

### Option 4: Check Java Version in Android Studio
Make sure Android Studio is using Java 11:

1. **File → Project Structure → SDK Location**
2. **JDK Location should point to Java 11**
3. **If not, browse to:** `C:\Program Files\Eclipse Adoptium\jdk-11.x.x.x-hotspot\`

## Root Cause
React Native 0.64 uses older Gradle APIs that may conflict with:
- Newer Gradle versions (7.6+)
- Newer Android Gradle Plugin versions (7.3+)
- Java versions newer than 11

The project has been configured for compatibility, but your local Android Studio environment may have newer versions cached.

## Last Resort: Manual APK Build
If Android Studio continues to fail, you can try building via command line:

1. **Open Command Prompt in the android folder**
2. **Run:**
   ```cmd
   gradlew assembleDebug
   ```

This will use the project's exact Gradle configuration without Android Studio's interference.