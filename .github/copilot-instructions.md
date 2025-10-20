# GitHub Copilot Instructions

## Project Overview
This is a **React Native order management mobile app** with WordPress backend integration for subsales management. The app features team-based authentication, Google Maps integration, and order synchronization with a comprehensive WordPress plugin.

## Architecture Overview

### Mobile App (`order-manager-mobile-1/`)
- **Tech Stack**: React Native 0.64 + TypeScript + Redux (legacy) + React Navigation 6
- **State Management**: Basic Redux store with `authSlice` and `ordersSlice` (needs RTK migration)
- **Navigation**: Stack-based navigation with 4 main screens (Auth, Orders, OrderDetail, Reports)
- **Service Layer**: Basic services in `authService`, `orderService`, and `wpSyncService`

### WordPress Plugin (`bkmb-subsales-management/`)
- **Backend**: Full-featured WordPress plugin with admin interface and REST API
- **API Base**: `/wp-json/order-manager/v1/` for order operations
- **Authentication**: Multi-team system with access codes (not JWT-based)

## Key Architectural Patterns

### Service Architecture
Services use basic axios/fetch patterns with error handling:
```typescript
// Pattern: Basic service structure in src/services/
export const functionName = async (params) => {
    try {
        const response = await fetch(url, options);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error description:', error);
        throw error;
    }
};
```

### State Management
- **Basic Redux**: Simple slice patterns without RTK
- **Critical**: `package.json` shows Redux but slices use RTK syntax - needs dependency alignment
- Auth state: `isAuthenticated`, `teamName`, `code` (no token-based auth)
- Orders state: `orders[]`, `isSyncing` flag

### Mobile App Structure
- **Active project**: `order-manager-mobile-1/` (not `order-manager-mobile/`)
- **Missing dependencies**: Redux Toolkit not in package.json but used in slices
- **Outdated React Native**: Version 0.64.0 (consider upgrading)
- **Google Maps**: Uses `react-native-google-places-autocomplete` (needs API key setup)

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
3. Navigate to **BKMB Subsales** in the main admin menu (located after Comments)
4. Configure Google Maps API key in Settings
5. Create teams with unique names and access codes in Teams section
6. Add team members with appropriate roles (Member, Manager, Admin)
7. Configure sync interval (minimum 60 seconds)
8. Plugin creates database tables `wp_order_sync_orders`, `wp_order_sync_teams`, and `wp_order_sync_team_members` on activation
9. Teams can access Google Maps API key via mobile app after authentication

### WordPress Plugin Features
- **Top-Level Admin Menu**: Located after Comments with separators for easy access
- **Multi-Team Management**: Support for unlimited teams with unique access codes
- **Google Maps Integration**: API key management with sharing to mobile clients
- **Professional Admin Interface**: Dashboard with statistics and team management
- **Database**: Custom tables for orders, teams, and team member management
- **API Security**: Team-based authentication with Google Maps API sharing
- **CRUD Operations**: Full REST API for orders and multi-team management
- **Permission Control**: Requires `manage_options` capability for admin access
- **Mobile App Support**: Team-based login with Google Maps API key delivery

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
- Team-based login with access codes (not JWT tokens)
- Team credentials stored in Redux state and AsyncStorage
- All API calls include team headers for authentication

## Testing Strategy
- Jest configuration in `package.json`
- Run tests with `npm test`
- Test files should follow React Native testing patterns