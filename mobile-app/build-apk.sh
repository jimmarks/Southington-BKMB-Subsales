#!/bin/bash

# Build script for React Native 0.64 APK with manual JavaScript bundling
# This script handles the compatibility issues with RN 0.64

set -e

echo "🚀 Building React Native 0.64 APK with manual bundling..."
echo

# Set up environment
export JAVA_HOME=/usr/lib/jvm/java-11-openjdk-amd64
export NODE_OPTIONS="--openssl-legacy-provider"

cd "$(dirname "$0")"

echo "📦 Step 1: Installing npm dependencies..."
npm install --legacy-peer-deps --silent

echo "🧹 Step 2: Cleaning previous builds..."
rm -rf android/app/build
rm -rf android/build
rm -rf android/app/src/main/assets/index.android.bundle

echo "📱 Step 3: Creating assets directory..."
mkdir -p android/app/src/main/assets

echo "� Step 4: Bundling JavaScript manually..."
npx react-native bundle \
  --platform android \
  --dev false \
  --entry-file index.js \
  --bundle-output android/app/src/main/assets/index.android.bundle \
  --assets-dest android/app/src/main/res

if [ ! -f "android/app/src/main/assets/index.android.bundle" ]; then
    echo "❌ Error: JavaScript bundle was not created!"
    exit 1
fi

echo "✅ JavaScript bundle created: $(du -h android/app/src/main/assets/index.android.bundle | cut -f1)"

echo "🔨 Step 5: Building APK..."
cd android
./gradlew clean
./gradlew assembleDebug

APK_PATH="app/build/outputs/apk/debug/app-debug.apk"
if [ -f "$APK_PATH" ]; then
    APK_SIZE=$(du -h "$APK_PATH" | cut -f1)
    echo
    echo "🎉 SUCCESS! APK built successfully!"
    echo "� Location: android/$APK_PATH"
    echo "📊 Size: $APK_SIZE"
    echo
    echo "📋 Next steps:"
    echo "1. Copy the APK to your device"
    echo "2. Enable 'Unknown Sources' in Android settings" 
    echo "3. Install and test the app"
else
    echo "❌ Error: APK was not created!"
    exit 1
fi