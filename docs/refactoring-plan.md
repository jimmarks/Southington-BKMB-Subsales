# Plugin Refactoring Plan

**Current State:** 9,895 lines in single file `subsales-management.php`  
**Goal:** Modular, maintainable architecture following WordPress best practices  
**Estimated Time:** 3-4 hours  
**Risk Level:** Low (pure code reorganization, no functionality changes)

---

## Phase 1: Preparation (15 mins)

### Pre-flight Checklist
- [ ] Commit current working state to git
- [ ] Create new branch: `refactor/modular-architecture`
- [ ] Backup current plugin file: `cp subsales-management.php subsales-management.php.backup`
- [ ] Note current line count: 9,895 lines
- [ ] Create test checklist of critical functionality to verify after refactor

### Test Checklist (run after refactor)
- [ ] Plugin activates without errors
- [ ] Orders page loads and displays data
- [ ] Create new order works
- [ ] Edit order works
- [ ] Delete/restore order works
- [ ] Order history displays
- [ ] PDF generation works
- [ ] Tally feature works (filter, checkboxes, bulk operations)
- [ ] Teams admin works
- [ ] PWA serves correctly
- [ ] REST API endpoints respond
- [ ] Team member authentication works

---

## Phase 2: Create Directory Structure (5 mins)

```bash
cd /workspaces/Southington-BKMB-Subsales/wordpress-plugin/

mkdir -p includes
mkdir -p admin
```

### Target Structure
```
wordpress-plugin/
├── subsales-management.php          # Bootstrap (300-400 lines)
├── includes/
│   ├── class-database.php           # Database schema, migrations
│   ├── class-rest-api.php           # All REST endpoints
│   ├── class-orders.php             # Order CRUD operations
│   ├── class-teams.php              # Team management
│   ├── class-pdf-generator.php      # PDF generation
│   └── class-pwa.php                # PWA serving logic
└── admin/
    ├── class-admin-pages.php        # Admin page rendering
    └── class-admin-ajax.php         # AJAX handlers
```

---

## Phase 3: Code Extraction (2-3 hours)

### Step 1: Extract Database Class (~30 mins)
**File:** `includes/class-database.php`

**Extract these functions:**
- `subsales_create_tables()` → `Database::create_tables()`
- `subsales_migrate_add_products_config()` → `Database::migrate_products_config()`
- `subsales_migrate_add_user_id()` → `Database::migrate_user_id()`
- `subsales_migrate_add_team_member_password()` → `Database::migrate_team_password()`
- `subsales_migrate_add_sync_status()` → `Database::migrate_sync_status()`
- `subsales_migrate_add_tally_columns()` → `Database::migrate_tally_columns()`
- All other migration functions
- `subsales_log_order_change()` → `Database::log_order_change()`

**Lines:** ~1,200-1,500 (includes schema definitions)

---

### Step 2: Extract REST API Class (~45 mins)
**File:** `includes/class-rest-api.php`

**Extract these functions:**
- `subsales_register_rest_routes()` → `REST_API::register_routes()`
- `subsales_sync_orders()` → `REST_API::sync_orders()`
- `subsales_get_config()` → `REST_API::get_config()`
- `subsales_get_teams()` → `REST_API::get_teams()`
- `validate_order_data()` → `REST_API::validate_order_data()`
- `create_order()` → `Orders::create()` (move to Orders class)
- `update_order()` → `Orders::update()` (move to Orders class)
- `delete_order()` → `Orders::delete()` (move to Orders class)
- `restore_order()` → `Orders::restore()` (move to Orders class)
- `tally_orders()` → `Orders::tally()` (move to Orders class)
- `team_member_login()` → `Teams::member_login()` (move to Teams class)
- `team_member_logout()` → `Teams::member_logout()` (move to Teams class)

**Lines:** ~800-1,000

---

### Step 3: Extract Orders Class (~30 mins)
**File:** `includes/class-orders.php`

**Extract these functions:**
- `create_order()` → `Orders::create()`
- `update_order()` → `Orders::update()`
- `delete_order()` → `Orders::delete()`
- `restore_order()` → `Orders::restore()`
- `tally_orders()` → `Orders::tally()`
- `order_sync_get_products_config()` → `Orders::get_products_config()`
- Helper functions for order processing

**Lines:** ~600-800

---

### Step 4: Extract Teams Class (~20 mins)
**File:** `includes/class-teams.php`

**Extract these functions:**
- `team_member_login()` → `Teams::member_login()`
- `team_member_logout()` → `Teams::member_logout()`
- Team-related helper functions

**Lines:** ~200-300

---

### Step 5: Extract PDF Generator Class (~30 mins)
**File:** `includes/class-pdf-generator.php`

**Extract these functions:**
- `subsales_generate_packing_list_pdf()` → `PDF_Generator::generate_packing_list()`
- All DomPDF-related code
- PDF formatting helpers

**Lines:** ~400-600

---

### Step 6: Extract Admin Pages Class (~45 mins)
**File:** `admin/class-admin-pages.php`

**Extract these functions:**
- `subsales_admin_menu()` → `Admin_Pages::register_menus()`
- `subsales_admin_dashboard()` → `Admin_Pages::render_dashboard()`
- `subsales_admin_orders()` → `Admin_Pages::render_orders()`
- `subsales_admin_teams()` → `Admin_Pages::render_teams()`
- `subsales_admin_settings()` → `Admin_Pages::render_settings()`
- `subsales_admin_reports()` → `Admin_Pages::render_reports()`
- All HTML rendering for admin pages

**Lines:** ~3,000-4,000 (largest chunk - mostly HTML)

---

### Step 7: Extract Admin AJAX Class (~20 mins)
**File:** `admin/class-admin-ajax.php`

**Extract these functions:**
- `order_sync_fetch_orders_ajax()` → `Admin_AJAX::fetch_orders()`
- `order_sync_save_team_ajax()` → `Admin_AJAX::save_team()`
- `order_sync_delete_team_ajax()` → `Admin_AJAX::delete_team()`
- `order_sync_save_team_member_ajax()` → `Admin_AJAX::save_team_member()`
- All other AJAX handlers

**Lines:** ~600-800

---

### Step 8: Extract PWA Class (~30 mins)
**File:** `includes/class-pwa.php`

**Extract these functions:**
- `subsales_serve_pwa()` → `PWA::serve()`
- `subsales_register_pwa_scripts()` → `PWA::register_scripts()`
- PWA-related helper functions
- Service worker logic

**Lines:** ~400-600

---

### Step 9: Create Bootstrap File (~30 mins)
**File:** `subsales-management.php` (rewritten)

**Keep only:**
- Plugin header comment
- Constants (SUBSALES_VERSION, SUBSALES_PLUGIN_DIR, etc.)
- Class autoloader
- Activation/deactivation hooks
- Main initialization function
- Hook registrations that delegate to classes

**Target Lines:** 300-400

**Example Structure:**
```php
<?php
/**
 * Plugin Name: BKMB Subsales Management
 * Version: 3.0.0
 * ...
 */

if (!defined('ABSPATH')) exit;

// Constants
define('SUBSALES_VERSION', '3.0.0');
define('SUBSALES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUBSALES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function($class) {
    $prefix = 'Subsales_';
    if (strpos($class, $prefix) !== 0) return;
    
    $class_file = str_replace('_', '-', strtolower(substr($class, strlen($prefix))));
    $paths = [
        SUBSALES_PLUGIN_DIR . 'includes/class-' . $class_file . '.php',
        SUBSALES_PLUGIN_DIR . 'admin/class-' . $class_file . '.php',
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

// Activation/Deactivation
register_activation_hook(__FILE__, 'subsales_activate');
register_deactivation_hook(__FILE__, 'subsales_deactivate');

function subsales_activate() {
    Subsales_Database::create_tables();
    Subsales_Database::run_migrations();
}

function subsales_deactivate() {
    // Cleanup if needed
}

// Initialize
add_action('plugins_loaded', 'subsales_init');

function subsales_init() {
    // Initialize components
    new Subsales_REST_API();
    new Subsales_PWA();
    
    if (is_admin()) {
        new Subsales_Admin_Pages();
        new Subsales_Admin_AJAX();
    }
}
```

---

## Phase 4: Testing & Validation (30 mins)

### Automated Checks
```bash
# PHP syntax check all new files
find wordpress-plugin/includes -name "*.php" -exec php -l {} \;
find wordpress-plugin/admin -name "*.php" -exec php -l {} \;
php -l wordpress-plugin/subsales-management.php

# Verify file structure
tree wordpress-plugin/
```

### Manual Testing
- [ ] Run through test checklist (from Phase 1)
- [ ] Check WordPress debug log for errors
- [ ] Test each admin page
- [ ] Test each REST endpoint
- [ ] Test PWA functionality
- [ ] Test PDF generation
- [ ] Test tally operations

### Validation Criteria
- ✅ No PHP errors
- ✅ All tests pass
- ✅ No functionality lost
- ✅ Same behavior as before refactor
- ✅ Faster IDE performance (noticeable when opening files)

---

## Phase 5: Cleanup & Documentation (15 mins)

### File Cleanup
- [ ] Delete `subsales-management.php.backup` (if all tests pass)
- [ ] Update CHANGELOG.md with refactoring notes
- [ ] Add docblocks to each class
- [ ] Add file-level comments explaining each class's purpose

### Package & Deploy
```bash
# Package the refactored plugin
bash scripts/package-plugin.sh

# Verify package integrity
unzip -l subsales-management.zip | grep "includes/"
unzip -l subsales-management.zip | grep "admin/"
```

### Git Workflow
```bash
git add .
git commit -m "refactor: Modularize plugin architecture for maintainability

- Split 9,895 line monolith into focused classes
- Created includes/ directory for core functionality
- Created admin/ directory for admin-specific code
- Implemented autoloader for clean class loading
- No functionality changes, pure reorganization

File structure:
- includes/class-database.php (schema, migrations)
- includes/class-rest-api.php (REST endpoints)
- includes/class-orders.php (order CRUD)
- includes/class-teams.php (team management)
- includes/class-pdf-generator.php (PDF generation)
- includes/class-pwa.php (PWA serving)
- admin/class-admin-pages.php (admin UI)
- admin/class-admin-ajax.php (AJAX handlers)
- subsales-management.php (bootstrap, ~350 lines)"

# Merge to main (after testing)
git checkout main
git merge refactor/modular-architecture
```

---

## Benefits After Refactoring

### Immediate Benefits
- **Faster navigation** - Jump to specific class instead of searching 10K lines
- **Better IDE performance** - Smaller files = faster intellisense
- **Clearer organization** - Know exactly where to look for specific functionality
- **Easier debugging** - Isolated components are easier to reason about

### Long-term Benefits
- **Easier feature additions** - Know exactly which class to extend
- **Better code reviews** - Smaller diffs, focused changes
- **Unit testing** - Can test classes in isolation
- **Onboarding** - New developers understand structure faster
- **Reduced merge conflicts** - Changes more likely to be in different files

### Maintenance Wins
- **Find bugs faster** - Smaller files = faster grep/search
- **Safer refactoring** - Changes isolated to specific classes
- **Documentation** - Each class has clear single responsibility
- **Professional appearance** - Shows architectural maturity

---

## Potential Issues & Solutions

### Issue 1: Global Functions Still Referenced
**Problem:** Existing code may call functions by old names  
**Solution:** Keep function wrappers in bootstrap that delegate to classes

**Example:**
```php
// In subsales-management.php
function subsales_create_tables() {
    return Subsales_Database::create_tables();
}
```

### Issue 2: Variable Scope
**Problem:** Some functions rely on global variables  
**Solution:** Pass dependencies explicitly or use class properties

### Issue 3: Hook Registration
**Problem:** WordPress hooks scattered throughout code  
**Solution:** Centralize in class constructors or dedicated init methods

### Issue 4: Backwards Compatibility
**Problem:** Other code might extend/hook into plugin  
**Solution:** Keep public API identical, only internal structure changes

---

## Rollback Plan

If something breaks:

1. **Immediate rollback:**
   ```bash
   git checkout main
   cp subsales-management.php.backup subsales-management.php
   ```

2. **Test the backup:**
   ```bash
   php -l subsales-management.php
   bash scripts/package-plugin.sh
   ```

3. **Deploy backup if needed**

4. **Debug refactored version offline** - Fix issues before re-attempting

---

## Success Metrics

### Before Refactor
- Lines: 9,895
- Files: 1
- Average function length: ~50 lines
- Time to find specific functionality: 2-3 minutes (search/scroll)
- IDE lag when opening file: Noticeable

### After Refactor (Target)
- Lines: 9,895 (same total, distributed)
- Files: 9
- Average class size: 400-600 lines
- Time to find specific functionality: <30 seconds (know which file)
- IDE lag: None (files <1,500 lines each)

### Quality Gates
- [ ] All tests pass
- [ ] No new PHP warnings/errors
- [ ] Package builds successfully
- [ ] Plugin activates without errors
- [ ] All features work identically

---

## Timeline Estimate

| Phase | Task | Time |
|-------|------|------|
| 1 | Preparation | 15 min |
| 2 | Directory structure | 5 min |
| 3.1 | Extract Database class | 30 min |
| 3.2 | Extract REST API class | 45 min |
| 3.3 | Extract Orders class | 30 min |
| 3.4 | Extract Teams class | 20 min |
| 3.5 | Extract PDF Generator | 30 min |
| 3.6 | Extract Admin Pages | 45 min |
| 3.7 | Extract Admin AJAX | 20 min |
| 3.8 | Extract PWA class | 30 min |
| 3.9 | Create bootstrap | 30 min |
| 4 | Testing & validation | 30 min |
| 5 | Cleanup & documentation | 15 min |
| **Total** | | **~4 hours** |

---

## Notes

- **Do this when:** You have a 4-hour block of uninterrupted time
- **Don't rush it:** Better to do it right than fast
- **Test thoroughly:** This touches every part of the plugin
- **Keep backup:** Until you're 100% confident
- **Document issues:** Note anything unexpected for future reference

---

## Next Steps (After Refactor)

Once modular structure is in place, consider:

1. **Add unit tests** - Each class can now be tested independently
2. **Add namespace** - Move to `\BKMB\Subsales\` namespace
3. **Use Composer autoloading** - More standard than custom autoloader
4. **Extract inline JavaScript** - Move large JS blocks to separate files
5. **Add interfaces** - Define contracts for classes
6. **Dependency injection** - Pass dependencies instead of global access

---

**Ready to start?** Begin with Phase 1 and work systematically through each phase. Commit after each major class extraction so you can rollback to specific checkpoints if needed.
