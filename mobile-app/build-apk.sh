#!/bin/bash

# Simple APK Build Script for React Native with Java 21 compatibility
# This script creates a basic debug APK

echo "🚀 Starting APK build process..."

# Set up environment
export ANDROID_HOME=/opt/android-sdk
export PATH=$PATH:$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools

# Navigate to project
cd /workspaces/Southington-BKMB-Subsales/mobile-app

echo "📦 Installing npm dependencies..."
npm install --force

echo "🔧 Preparing Android build..."

# Create a minimal index.js if it doesn't exist
if [ ! -f "index.js" ]; then
    echo "Creating index.js..."
    cat > index.js << 'EOF'
import {AppRegistry} from 'react-native';
import App from './src/App';
import {name as appName} from './package.json';

AppRegistry.registerComponent(appName, () => App);
EOF
fi

echo "🏗️ Building JavaScript bundle..."
npx react-native bundle \
    --platform android \
    --dev false \
    --entry-file index.js \
    --bundle-output android/app/src/main/assets/index.android.bundle \
    --assets-dest android/app/src/main/res/

echo "📱 Creating APK..."
cd android

# Create assets directory
mkdir -p app/src/main/assets

# Try to build with specific Java version settings
export JAVA_OPTS="-XX:MaxMetaspaceSize=512m"
export GRADLE_OPTS="-Dorg.gradle.daemon=false -Dorg.gradle.jvmargs=-Xmx2048m"

# Build APK
./gradlew assembleDebug --no-daemon --warning-mode all

if [ $? -eq 0 ]; then
    echo "✅ APK build successful!"
    echo "📍 APK location: android/app/build/outputs/apk/debug/app-debug.apk"
    ls -la app/build/outputs/apk/debug/ 2>/dev/null || echo "APK files not found in expected location"
else
    echo "❌ APK build failed. Trying alternative approach..."
    
    # Alternative: Try with bundled JS approach
    echo "🔄 Trying React Native run-android with build-only flag..."
    cd ..
    npx react-native run-android --no-packager
fi