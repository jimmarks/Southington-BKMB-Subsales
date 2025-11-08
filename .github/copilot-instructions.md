# GitHub Copilot Instructions

## Project Overview
This is a **PWA that is meant to be served as part of a larger wordpress plugin** with WordPress backend integration for subsales management. The app features team-based authentication, Google Maps integration, and order synchronization with a comprehensive WordPress plugin.

## Architecture Overview

## WordPress Plugin (`wordpress-plugin/`)
- **Backend**: Full-featured WordPress plugin with professional admin interface and REST API
- **API Base**: `/wp-json/order-manager/v1/` for orders, auth, and config endpoints
- **Authentication**: Multi-team system with access codes (not JWT-based)
- **Database**: Custom tables for orders, teams, and team members

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
- **Active project**: `mobile-app/` (clean, renamed from `order-manager-mobile-1/`)
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

### Android Studio Testing (Recommended)
For the best Android testing experience with Android Studio Narwhal:

1. **Open in Android Studio**: Open `android/` folder in Android Studio
2. **Sync Project**: Let Gradle sync and download dependencies
3. **Create AVD**: Tools → AVD Manager → Create Virtual Device (API 30+ recommended)
4. **Start Metro**: `npm start` in the project root (dev container)
5. **Run App**: Use Android Studio's Run button or `npm run android`

### Troubleshooting Android Setup
- **Missing Android files**: If `android/` lacks Gradle files, run `npx react-native init TempProject` and copy Android structure
- **Google Services**: Ensure `android/app/google-services.json` exists for Maps functionality
- **Port forwarding**: Use `adb reverse tcp:8081 tcp:8081` if Metro connection fails

### Critical Configuration Files
- **Environment**: Copy `.env.example` to `.env` (contains WordPress URL - API keys fetched dynamically)
- **Google Services**: 
  - Android: `android/app/google-services.json`
  - iOS: `ios/App/GoogleService-Info.plist`
- **Dynamic Config**: Google Maps API key fetched from WordPress backend via `/config` endpoint after team authentication

### WordPress Plugin Setup
1. Upload `wordpress-plugin/bkmb-subsales-management.php` to WordPress `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin 'Plugins' menu
3. Navigate to **BKMB Subsales** in the main admin menu (located after Comments)
4. Configure Google Maps API key in Settings
# Copilot / Assistant Instructions

Purpose
-------
Short, actionable instructions for automated code assistants and new contributors working on this repository. The goal is to have clear, repo-specific guidance so edits are safe, consistent, and easy to validate.

Audience & tone
---------------
- Primary audience: automated assistants and new maintainers.
- Tone: concise and prescriptive — tell the assistant what to change, where, and how to validate.

Project snapshot (key facts)
---------------------------
- This repo is a WordPress plugin that ships a small PWA client. The plugin provides an admin UI, a REST/API surface, and a server-side ZIP-based address-extract generator used by the PWA for offline address completion.
- Canonical address input in the PWA is the plain text field with id `#address` (the client intentionally avoids fragile DOM heuristics).
- Admin ZIP extract generator (PHP) queries OpenStreetMap's Overpass API and writes per-ZIP JSON files to `wp-content/uploads/subsales-zipdata/<zip>.json`.
- Packaging script: `scripts/package-plugin.sh` creates a distributable plugin ZIP at the repo root.

Where to look first
-------------------
- Plugin bootstrap and admin pages: `wordpress-plugin/subsales-management.php` and `wordpress-plugin/includes/`.
- Admin JS for ZIP generation: `wordpress-plugin/assets/js/subsales-zip-admin.js`.
- PWA client entrypoint: `wordpress-plugin/pwa/app.js` (uses `#address`).
- Packaging: `scripts/package-plugin.sh` (builds `subsales-management.zip`).

Quick editing rules
-------------------
- Never commit secrets (API keys, service account files) — put them in environment variables or secure vaults and document usage in the README.
- Avoid adding long-running network calls in tests. Overpass queries are allowed from admin actions but not in automated unit tests.
- Make minimal, focused edits. Preserve existing style and indentation.

Change/PR checklist for assistants
---------------------------------
Before creating a PR or making a patch, run these checks locally (or in the dev container):

1. Lint/Typecheck
  - If you modify TypeScript in `mobile-app/`, run tsc (project has `tsconfig.json`).

2. Quick runtime validation (for PHP/admin changes)
  - If you edit admin pages or the ZIP generator, run the packaging script and then, if possible, test on a local WP install:

```bash
# create plugin zip
bash scripts/package-plugin.sh

# Inspect the zip exists
ls -lh subsales-management.zip
```

3. Functional smoke tests
  - For ZIP generator edits: in WP admin, Subsales → Address Extracts, save some test ZIPs and click Generate. Then verify `wp-content/uploads/subsales-zipdata/<zip>.json` exists and is valid JSON.

4. Unit tests
  - Run `npm test` in `mobile-app/` if you changed client code.

5. Commit messages / PR title
  - Use concise titles and reference issues when relevant. Example: "Add Overpass ZIP extract generator and admin UI".

When to ask a human
--------------------
- If a code change requires secrets or credentials (Google API keys, service account files).
- If a long-running data import is needed (OpenAddresses ingest) — discuss strategy before implementing.
- If changes affect DB schema or add new persistent tables.

Quality gates (minimal)
-----------------------
- Build: run `bash scripts/package-plugin.sh` and ensure it completes.
- Lint/Typecheck: run TypeScript checks for `mobile-app/` when relevant.
- Tests: run client unit tests with `npm test` when modifying JS/TS code.

Security & network policies
---------------------------
- Do not hard-code API keys or tokens in the repo.
- Overpass usage: allowed from admin actions. Be mindful of rate limits and timeouts. Do not call Overpass from CI tests.

Project-specific notes & conventions
----------------------------------
- The PWA relies on per-ZIP JSON extracts for offline address completion. The intended flow is:
  1) Admin saves served ZIPs in Subsales → Address Extracts.
  2) Admin triggers generation (server calls Overpass and writes `wp-content/uploads/subsales-zipdata/<zip>.json`).
  3) The PWA lazy-loads those files (client-side caching, IndexedDB) to provide offline suggestions.
- Canonical address field: `#address` is authoritative. Do not attempt to extract visible suggestion text from third-party widgets in production code — instead provide a controlled autocomplete that writes to `#address`.

Small changelog
---------------
- 2025-11-08: Updated to reflect the admin ZIP extract generator, Overpass usage, canonical `#address` behavior, and packaging script.

Next recommended improvements (for contributors)
-----------------------------------------------
1. Implement a lightweight client autocomplete module that lazy-loads per-ZIP JSON and caches it in IndexedDB. Keep the module small and testable.
2. Add a CI job that runs TypeScript checks and the packaging script (without deploying). Exclude Overpass calls.
3. Add a short integration doc: "How to test the ZIP generator locally" with screenshots or sample logs.

Contact / ownership
-------------------
If you're unsure about a non-trivial change, tag a human reviewer in the PR. Keep PRs small.

---
If you'd like I can now apply this update to `.github/copilot-instructions.md` and run two quick validations: (1) confirm `scripts/package-plugin.sh` exists and is executable, and (2) grep for the admin ZIP generator function name to ensure the file references are correct.