# Order Management Mobile App

This project is a mobile application for managing orders, built using React Native. It allows users to log in, place orders, and sync data with a WordPress backend.

## Features

- User authentication using a code and team name.
- Order management including:
  - Inputting customer details (name, address, phone number).
  - Selecting from three order options: Turkey, Ham, or Combo.
  - Specifying quantities for each item.
  - Adding a donation amount.
  - Calculating total amount to collect based on a $10/unit price plus donations.
  - Documenting payment method (cash or check).
- Integration with Google Maps API for address autofill.
- Offline order storage until successful sync with the backend.

## Project Structure

- `src/`: Contains the main application code.
  - `components/`: Reusable components for authentication and order management.
  - `screens/`: Different screens of the application.
  - `services/`: API and service-related functions.
  - `store/`: Redux store setup and slices.
  - `hooks/`: Custom hooks for managing state.
  - `utils/`: Utility functions for validation.
  - `types/`: TypeScript types and interfaces.

- `android/`: Android-specific configuration files.
- `ios/`: iOS-specific configuration files.
- `wp-plugin/`: WordPress plugin for order synchronization.
- `.env.example`: Example environment configuration.
- `package.json`: Project dependencies and scripts.
- `tsconfig.json`: TypeScript configuration.

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

4. Set up environment variables by copying `.env.example` to `.env` and updating the values.

5. Run the application:
   ```
   npm run start
   ```

## WordPress Plugin

The WordPress plugin located in the `wp-plugin` directory handles the backend synchronization of orders. It includes API endpoints for managing order data and settings for configuration.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request for any enhancements or bug fixes.

## License

This project is licensed under the MIT License. See the LICENSE file for details.