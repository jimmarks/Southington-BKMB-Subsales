# Android Studio Environment Setup

## In Android Studio Terminal (View → Tool Windows → Terminal):

```bash
# Navigate to project root
cd ..

# Set up environment file
cp .env.example .env

# Edit .env file with your WordPress URL:
# WORDPRESS_API_URL=http://your-ip-address/wp-json/order-manager/v1
# SYNC_INTERVAL=60000

# Install Node.js dependencies
npm install --legacy-peer-deps
```

## Configure .env file:
1. Open mobile-app/.env in Android Studio
2. Replace WORDPRESS_API_URL with your actual WordPress site URL
3. Example: WORDPRESS_API_URL=http://192.168.1.100/wordpress/wp-json/order-manager/v1

## Build Configuration:
- Target SDK: 33
- Min SDK: 21
- Build Tools: 33.0.0
- Gradle: 8.0
- AGP: 7.2.2
