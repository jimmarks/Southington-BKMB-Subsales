# Expo Migration Guide for iOS Testing

## Why Expo for iOS Testing?

Expo allows you to build and test iOS apps without needing a Mac, using their cloud build service (EAS Build).

## Migration Steps:

### 1. Install Expo CLI
```bash
npm install -g @expo/cli
```

### 2. Initialize Expo in existing project
```bash
cd order-manager-mobile-1
npx expo init --template blank-typescript
# Choose to merge with existing project
```

### 3. Configure app.json
```json
{
  "expo": {
    "name": "BKMB Subsales",
    "slug": "bkmb-subsales",
    "version": "1.0.0",
    "orientation": "portrait",
    "icon": "./assets/icon.png",
    "splash": {
      "image": "./assets/splash.png",
      "resizeMode": "contain",
      "backgroundColor": "#ffffff"
    },
    "ios": {
      "bundleIdentifier": "com.bkmb.subsales",
      "supportsTablet": false
    },
    "android": {
      "package": "com.bkmb.subsales"
    }
  }
}
```

### 4. Install EAS CLI
```bash
npm install -g eas-cli
```

### 5. Configure EAS Build
```bash
eas build:configure
```

### 6. Build for iOS
```bash
# Development build (for testing)
eas build --platform ios --profile development

# Production build
eas build --platform ios --profile production
```

### 7. Install on iOS Device
- Download Expo Go app from App Store
- Scan QR code from development build
- Or install the .ipa file via TestFlight/Apple Configurator