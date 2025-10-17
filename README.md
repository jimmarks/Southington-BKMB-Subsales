# My Order App

## Overview
My Order App is a mobile application designed for managing orders with a user-friendly interface. It allows users to log in, place orders, and manage their preferences seamlessly. The app integrates with Google Maps API for address autofill and supports offline storage capabilities.

## Features
- User login via code and team name
- Order documentation including name, address, and phone number
- Item selection for Turkey, Ham, or Combo with quantity selection
- Donation input feature
- Total amount calculation based on $10/unit plus donation
- Payment documentation for cash or check
- Cloud database synchronization with offline storage capabilities

## Project Structure
```
my_order_app
├── android
├── ios
├── lib
│   ├── main.dart
│   ├── app.dart
│   ├── models
│   │   ├── user.dart
│   │   ├── order.dart
│   │   ├── item.dart
│   │   └── payment.dart
│   ├── screens
│   │   ├── login_screen.dart
│   │   ├── order_form_screen.dart
│   │   ├── order_summary_screen.dart
│   │   ├── orders_list_screen.dart
│   │   └── settings_screen.dart
│   ├── widgets
│   │   ├── address_autocomplete.dart
│   │   ├── quantity_selector.dart
│   │   ├── donation_input.dart
│   │   └── payment_method_tile.dart
│   ├── services
│   │   ├── auth_service.dart
│   │   ├── maps_service.dart
│   │   ├── orders_service.dart
│   │   ├── sync_service.dart
│   │   └── local_database.dart
│   └── utils
│       ├── constants.dart
│       └── currency.dart
├── test
│   └── widget_test.dart
├── pubspec.yaml
├── analysis_options.yaml
├── .gitignore
├── .env.example
└── README.md
```

## Setup Instructions
1. Clone the repository:
   ```
   git clone <repository-url>
   ```
2. Navigate to the project directory:
   ```
   cd my_order_app
   ```
3. Install dependencies:
   ```
   flutter pub get
   ```
4. Set up environment variables by creating a `.env` file based on `.env.example`.
5. Run the application:
   ```
   flutter run
   ```

## Usage
- Launch the app and log in using your code and team name.
- Fill in the order form with your details and select your items.
- Review your order summary and choose your payment method.
- Place your order and enjoy!

## Contributing
Contributions are welcome! Please open an issue or submit a pull request for any enhancements or bug fixes.

## License
This project is licensed under the MIT License. See the LICENSE file for details.