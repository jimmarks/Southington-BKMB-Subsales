# REST API Endpoints Reference

**⚠️ IMPORTANT: Before deleting ANY function, check this list to ensure it's not a REST endpoint callback!**

All endpoints are registered in `includes/class-rest-api.php` under the namespace `/wp-json/order-manager/v1/`

---

## Orders API

| Endpoint | Method | Callback Function | Location | Permission |
|----------|--------|-------------------|----------|------------|
| `/orders` | GET | `Subsales_Orders::get_orders()` | `includes/class-orders.php` | `order_sync_check_permissions` |
| `/orders` | POST | `Subsales_Orders::create_order()` | `includes/class-orders.php` | `order_sync_check_permissions` |
| `/orders/{id}` | GET | `Subsales_Orders::get_order_by_id()` | `includes/class-orders.php` | `order_sync_check_permissions` |
| `/orders/{id}` | PUT | `Subsales_Orders::update_order()` | `includes/class-orders.php` | `order_sync_check_permissions` |
| `/orders/{id}` | DELETE | `Subsales_Orders::delete_order()` | `includes/class-orders.php` | `order_sync_check_permissions` |
| `/orders/{id}/history` | GET | `Subsales_Orders::get_order_history()` | `includes/class-orders.php` | `order_sync_check_admin_permissions` |
| `/orders/{id}/restore` | POST | `Subsales_Orders::restore_order()` | `includes/class-orders.php` | `order_sync_check_admin_permissions` |
| `/orders/tally` | POST | `Subsales_Orders::tally_orders()` | `includes/class-orders.php` | `order_sync_check_admin_permissions` |

---

## Authentication API

| Endpoint | Method | Callback Function | Location | Permission |
|----------|--------|-------------------|----------|------------|
| `/auth/login` | POST | `Subsales_Teams::team_member_login()` | `includes/class-teams.php` | `__return_true` (public) |
| `/auth/verify` | POST | `Subsales_Teams::verify_team_access()` | `includes/class-teams.php` | `__return_true` (public) |

---

## Configuration API

| Endpoint | Method | Callback Function | Location | Permission |
|----------|--------|-------------------|----------|------------|
| `/config` | GET | `get_app_config()` | `subsales-management.php` | `__return_true` (public) |
| `/time` | GET | `order_manager_get_server_time()` | `subsales-management.php` | `__return_true` (public) |
| `/zip-index` | GET | `subsales_get_zip_index_api()` | `subsales-management.php` | `__return_true` (public) |

---

## Teams API

| Endpoint | Method | Callback Function | Location | Permission |
|----------|--------|-------------------|----------|------------|
| `/teams/members` | GET | `Subsales_Teams::get_team_members_endpoint()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/teams/{id}/assign` | POST | `Subsales_Teams::assign_user_to_team()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/teams/{id}/users` | GET | `Subsales_Teams::get_team_users()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/teams/{id}/users/{userId}` | DELETE | `Subsales_Teams::remove_user_from_team()` | `includes/class-teams.php` | `order_sync_check_permissions` |

---

## User Management API

| Endpoint | Method | Callback Function | Location | Permission |
|----------|--------|-------------------|----------|------------|
| `/users` | GET | `Subsales_Teams::get_users()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/users` | POST | `Subsales_Teams::create_user()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/users/{id}` | GET | `Subsales_Teams::get_user_by_id()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/users/{id}` | PUT | `Subsales_Teams::update_user()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/users/{id}` | DELETE | `Subsales_Teams::delete_user()` | `includes/class-teams.php` | `order_sync_check_permissions` |
| `/users/search` | GET | `Subsales_Teams::search_users()` | `includes/class-teams.php` | `__return_true` (public) |
| `/users/{id}/teams` | GET | `Subsales_Teams::get_user_teams()` | `includes/class-teams.php` | `order_sync_check_permissions` |

---

## PWA Session Tracking API

| Endpoint | Method | Callback Function | Location | Permission |
|----------|--------|-------------------|----------|------------|
| `/pwa-session/start` | POST | `Subsales_REST_API::start_pwa_session()` | `includes/class-rest-api.php` | `__return_true` (public) |
| `/pwa-session/heartbeat` | POST | `Subsales_REST_API::update_pwa_heartbeat()` | `includes/class-rest-api.php` | `__return_true` (public) |
| `/pwa-session/end` | POST | `Subsales_REST_API::end_pwa_session()` | `includes/class-rest-api.php` | `__return_true` (public) |
| `/pwa-session/active` | GET | `Subsales_REST_API::get_active_sessions()` | `includes/class-rest-api.php` | `order_sync_check_admin_permissions` |

---

## PWA Logging API

| Endpoint | Method | Callback Function | Location | Permission |
|----------|--------|-------------------|----------|------------|
| `/log` | POST | `Subsales_REST_API::pwa_log()` | `includes/class-rest-api.php` | `__return_true` (public) |

---

## Permission Callbacks

These permission callback functions are also required and must not be deleted:

- `order_sync_check_permissions()` - Basic team/user authentication check (in `subsales-management.php`)
- `order_sync_check_admin_permissions()` - WordPress admin-only check (in `subsales-management.php`)

---

## Checklist for Code Cleanup

Before deleting any function, verify:

1. ✅ Function name is NOT in the "Callback Function" column above
2. ✅ Function is not a permission callback (`order_sync_check_*`)
3. ✅ Function is not used as a string reference anywhere (search for `'function_name'`)
4. ✅ Function is not called via `call_user_func`, `apply_filters`, `do_action`, or similar dynamic calls

---

**Last Updated**: December 9, 2024  
**Plugin Version**: 2.2.1.55
