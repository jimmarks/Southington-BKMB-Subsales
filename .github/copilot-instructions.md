# GitHub Copilot Instructions

## Project Overview
This is a **React Native order management mobile app** with WordPress backend integration. The app features user authentication, Google Maps integration, and real-time order synchronization with a WordPress plugin.

## Architecture Overview

### Mobile App (`order-manager-mobile/`)
- **Tech Stack**: React Native 0.64 + TypeScript + Redux Toolkit + React Navigation
- **State Management**: Redux store with `authSlice` and `ordersSlice` (RTK patterns)
- **Navigation**: Stack-based navigation with 5 main screens (Auth, Orders, Order, Map, Reports)
- **Service Layer**: Separated into `authService`, `orderService`, and `wpSyncService`

### WordPress Integration (`wp-plugin/`)
- **Backend**: Custom WordPress plugin with REST API endpoints
- **API Base**: `/wp-json/order-manager/v1/` for order operations
- **Authentication**: JWT token-based auth via WordPress REST API

## Key Architectural Patterns

### Service Architecture
Services follow a consistent pattern with error handling and async/await:
```typescript
// Pattern: All services use axios with proper error handling
export const functionName = async (params) => {
    try {
        const response = await axios.method(url, data, config);
        return response.data;
    } catch (error) {
        console.error('Error description:', error);
        throw error;
    }
};
```

### State Management
- Use **Redux Toolkit slices** for state management
- Follow the existing slice pattern in `src/store/authSlice.ts`
- All slices export actions and reducer, imported in `src/store/index.ts`

### Component Structure
- **Screens** (`src/screens/`): Top-level navigation components
- **Components** (`src/components/`): Organized by feature (auth/, orders/, map/)
- **Shared Components**: Place reusable UI components in appropriate feature folders

### API Integration
- **Base URL**: Configure in `src/services/api/index.ts`
- **Authentication**: Multiple options:
  - Bearer tokens for WordPress users
  - `X-API-Key` header for system authentication
  - `X-Team-Name` + `X-Access-Code` headers for team authentication (mobile app login)
  - `X-Team-Email` + `X-Member-Access-Code` headers for individual team member authentication
- **Offline Support**: Use `AsyncStorage` for local persistence via `wpSyncService`

## Development Workflow

### Environment Setup
```bash
# Install dependencies
npm install

# iOS development
npm run ios

# Android development  
npm run android

# Start Metro bundler
npm start
```

### Critical Configuration Files
- **Environment**: Copy `.env.example` to `.env` (contains WordPress URL and API keys)
- **Google Services**: 
  - Android: `android/app/google-services.json`
  - iOS: `ios/App/GoogleService-Info.plist`

### WordPress Plugin Setup
1. Upload `wp-plugin/order-sync.php` to WordPress `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin 'Plugins' menu
3. Go to **Settings > Order Sync** to configure the plugin
4. Set an API key for mobile app authentication
5. Set a team name and access code for mobile app login
6. Add team members with appropriate roles (Member, Manager, Admin)
7. Configure sync interval (minimum 60 seconds)
8. Plugin creates database tables `wp_order_sync_orders` and `wp_order_sync_team_members` on activation
9. Ensure WordPress REST API is enabled

### WordPress Plugin Features
- **Admin Interface**: Settings page at WP Admin > Settings > Order Sync
- **Team Authentication**: Set team name and access code for mobile app login
- **Team Management**: Add/remove team members with different roles (Member, Manager, Admin)
- **Database**: Custom tables for order storage and team member management
- **API Security**: Multiple authentication methods (API key, team login, individual member credentials)
- **CRUD Operations**: Full REST API for orders and team management
- **Permission Control**: Requires `manage_options` capability for admin access
- **Mobile App Login**: Team name + access code required for mobile app authentication

## Project-Specific Conventions

### File Organization
- **Types**: All TypeScript interfaces in `src/types/index.ts`
- **Validators**: Form validation functions in `src/utils/validators.ts`
- **Hooks**: Custom hooks like `useAuth` in `src/hooks/`

### API URL Configuration
- **Mobile**: Update `API_BASE_URL` in `src/services/api/index.ts`
- **WordPress**: Replace placeholder URLs in `wpSyncService.ts`

### Google Maps Integration
- Maps component in `src/components/map/MapView.tsx`
- Used for address autofill in order forms
- Requires Google Maps API key in environment configuration

## Common Development Tasks

### Adding New Screens
1. Create screen component in `src/screens/`
2. Add to navigation stack in `src/App.tsx` and `src/navigation/AppNavigator.tsx`
3. Update TypeScript navigation types if needed

### Adding New API Endpoints
1. Add function to appropriate service file (`authService`, `orderService`, `wpSyncService`)
2. Update WordPress plugin `order-sync.php` with corresponding REST route
3. Update TypeScript interfaces in `src/types/index.ts`

### State Management Updates
1. Modify existing slice or create new slice in `src/store/`
2. Add reducer to store configuration in `src/store/index.ts`
3. Use RTK patterns with `createSlice` and proper TypeScript typing

## Integration Points

### WordPress Sync Strategy
- **Local First**: Orders saved locally via `AsyncStorage` for offline support
- **Background Sync**: `wpSyncService` handles bi-directional synchronization
- **Conflict Resolution**: Last-write-wins strategy for order updates

### Authentication Flow
- Login via WordPress JWT Auth plugin
- Token stored in Redux state and AsyncStorage for persistence
- All API calls include Bearer token for user context

## Testing Strategy
- Jest configuration in `package.json`
- Run tests with `npm test`
- Test files should follow React Native testing patterns