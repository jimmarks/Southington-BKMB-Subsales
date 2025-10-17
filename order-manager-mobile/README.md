# Order Manager Mobile App

## Overview
The Order Manager mobile app is designed for both Android and iOS platforms, providing an efficient order management system. It features user authentication, order documentation, Google Maps API integration for address autofill, and synchronization with a WordPress backend for order management and reporting.

## Features
- **User Authentication**: Users can register and log in to manage their orders securely.
- **Order Management**: Users can create, view, and manage their orders through a user-friendly interface.
- **Google Maps Integration**: The app utilizes Google Maps API to help users autofill address fields, enhancing the order placement experience.
- **WordPress Backend**: The app synchronizes orders with a WordPress backend, allowing for real-time updates and reporting.

## Project Structure
```
order-manager-mobile
├── src
│   ├── App.tsx
│   ├── assets
│   ├── components
│   │   ├── auth
│   │   │   ├── Login.tsx
│   │   │   └── Register.tsx
│   │   ├── orders
│   │   │   ├── OrderList.tsx
│   │   │   ├── OrderDetail.tsx
│   │   │   └── OrderForm.tsx
│   │   └── map
│   │       └── MapView.tsx
│   ├── screens
│   │   ├── AuthScreen.tsx
│   │   ├── OrdersScreen.tsx
│   │   ├── OrderScreen.tsx
│   │   ├── MapScreen.tsx
│   │   └── ReportsScreen.tsx
│   ├── navigation
│   │   └── AppNavigator.tsx
│   ├── services
│   │   ├── api
│   │   │   └── index.ts
│   │   ├── authService.ts
│   │   ├── orderService.ts
│   │   └── wpSyncService.ts
│   ├── store
│   │   ├── index.ts
│   │   ├── authSlice.ts
│   │   └── ordersSlice.ts
│   ├── hooks
│   │   └── useAuth.ts
│   ├── utils
│   │   └── validators.ts
│   └── types
│       └── index.ts
├── android
│   └── app
│       └── google-services.json
├── ios
│   └── App
│       └── GoogleService-Info.plist
├── wp-plugin
│   ├── order-sync.php
│   └── README.md
├── .env.example
├── package.json
├── tsconfig.json
├── babel.config.js
├── metro.config.js
└── README.md
```

## Installation
1. Clone the repository:
   ```
   git clone <repository-url>
   ```
2. Navigate to the project directory:
   ```
   cd order-manager-mobile
   ```
3. Install dependencies:
   ```
   npm install
   ```
4. Set up environment variables by copying `.env.example` to `.env` and filling in the required values.

## Running the App
- For Android:
  ```
  npm run android
  ```
- For iOS:
  ```
  npm run ios
  ```

## Contributing
Contributions are welcome! Please open an issue or submit a pull request for any enhancements or bug fixes.

## License
This project is licensed under the MIT License. See the LICENSE file for details.