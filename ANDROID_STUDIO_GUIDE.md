# Android Studio Setup Guide

## 1. Open Project in Android Studio
- **File → Open** 
- Navigate to: `/workspaces/Southington-BKMB-Subsales/mobile-app/android/`
- Select the **android** folder (not mobile-app)
- Click **Open**

## 2. Wait for Gradle Sync
- Android Studio will automatically sync Gradle
- Wait for "Gradle sync finished" notification
- Install any missing SDK components when prompted

## 3. Configure Environment
In Android Studio's terminal (bottom panel):
```bash
# Go to project root
cd ..

# Copy environment template
cp .env.example .env

# Install dependencies
npm install --legacy-peer-deps
```

## 4. Edit .env File
Open `mobile-app/.env` and configure:
```bash
WORDPRESS_API_URL=http://your-ip-address/wp-json/order-manager/v1
SYNC_INTERVAL=60000
```

## 5. Create Virtual Device (AVD)
- **Tools → AVD Manager**
- Click **Create Virtual Device**
- Choose **Pixel 4** or similar
- Select **API 30+** system image
- Click **Finish**

## 6. Run the App
- Start your AVD emulator
- Click the green **Run** button (▶️)
- Select your running emulator
- Wait for app to build and install

## 7. Start Metro Bundler
In a separate terminal:
```bash
cd /workspaces/Southington-BKMB-Subsales/mobile-app
npm start
```

## Project Configuration:
- **Application ID**: com.ordermanagermobile
- **Target SDK**: 33
- **Min SDK**: 21
- **Gradle**: 8.0
- **Build Tools**: 33.0.0