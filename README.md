# BKMB Subsales Management System

A complete subsales management solution consisting of a React Native mobile app and WordPress plugin for the Southington BKMB band organization.

## Project Structure

```
Southington-BKMB-Subsales/
├── wordpress-plugin/          # WordPress plugin for backend API
│   ├── bkmb-subsales-management.php
│   ├── README.md
│   └── CHANGELOG.md
├── mobile-app/               # React Native mobile application
│   ├── src/                  # Source code
│   ├── android/              # Android project files
│   ├── ios/                  # iOS project files
│   ├── package.json
│   └── README.md
├── docs/                     # Documentation
│   └── expo-migration-guide.md
└── .github/                  # GitHub configuration
    └── copilot-instructions.md
```

## Components

### WordPress Plugin (`wordpress-plugin/`)
- **File**: `bkmb-subsales-management.php`
- **Purpose**: Backend API for order management, team authentication, and configuration
- **Features**: Multi-team management, Google Maps API integration, order synchronization
- **API**: `/wp-json/order-manager/v1/` endpoints

### Mobile App (`mobile-app/`)
- **Framework**: React Native 0.64 + TypeScript
- **Purpose**: Mobile order entry and management
- **Features**: Team-based login, smart address input, offline support, order synchronization

## Quick Start

### WordPress Plugin Setup
1. Upload `wordpress-plugin/bkmb-subsales-management.php` to `/wp-content/plugins/`
2. Activate through WordPress admin
3. Configure teams and settings in **BKMB Subsales** admin menu

### Mobile App Development
1. Navigate to `mobile-app/` directory
2. Install dependencies: `npm install`
3. Copy `.env.example` to `.env` and configure WordPress URL
4. For Android: Open `android/` in Android Studio
5. Start Metro: `npm start`
6. Run: `npm run android` or `npm run ios`

## Documentation

- **WordPress Plugin**: See `wordpress-plugin/README.md`
- **Mobile App**: See `mobile-app/README.md`
- **AI Development**: See `.github/copilot-instructions.md`
- **Migration Notes**: See `docs/expo-migration-guide.md`

## Development Workflow

1. **WordPress Backend**: Develop and test the plugin locally
2. **Mobile Frontend**: Use Android Studio or Xcode for mobile development
3. **Integration**: Test mobile app against WordPress API endpoints
4. **Deployment**: WordPress plugin to production, mobile app via app stores

## Support

For issues and feature requests, please use the GitHub issue tracker.

## License

MIT License - See LICENSE file for details.