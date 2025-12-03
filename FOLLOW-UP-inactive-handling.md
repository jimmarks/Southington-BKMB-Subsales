# Follow-Up: Inactive Users & Teams Handling

**Created:** December 2, 2025  
**Status:** Planned for future implementation  
**Priority:** Medium

## Overview
This document outlines the planned enhancements for handling inactive users and teams throughout the Subsales Management system.

## Current State (v2.0.0)

### Import/Export System
- ✅ Users and teams can have `status` field: `active` or `inactive`
- ✅ Status is preserved during export
- ✅ Status can be updated via CSV import
- ✅ Status is stored in database

### Current Limitations
- ❌ Admin UI doesn't differentiate inactive users/teams visually
- ❌ Inactive users can still log into PWA
- ❌ No filtering/toggling of inactive records in admin
- ❌ Reports include inactive users/teams without distinction

## Planned Enhancements

### 1. Admin UI - User Management Page

#### Display Changes
- **Default View**: Show only active users
- **Toggle Control**: Add "Show Inactive Users" checkbox/toggle
- **Visual Distinction**: 
  - Grey out inactive users in lists
  - Add "(Inactive)" badge next to user names
  - Use different background color (light grey) for inactive rows

#### Filtering
```
[✓ Show Active] [  Show Inactive] [  Show All]
```

### 2. Admin UI - Team Management Page

#### Display Changes
- **Default View**: Show only active teams
- **Toggle Control**: Add "Show Inactive Teams" checkbox/toggle
- **Visual Distinction**:
  - Grey out inactive teams
  - Add "(Inactive)" badge
  - Different background color

### 3. PWA Login Restrictions

#### Behavior for Inactive Users
- **Block Login**: Inactive users cannot log into PWA
- **Error Message**: "Your account has been deactivated. Please contact your administrator."
- **API Change**: `/auth` endpoint checks `status` field
- **Session Handling**: Active sessions are terminated when user becomes inactive

#### Implementation
```php
// In REST API auth endpoint
if ( $user['status'] === 'inactive' ) {
    return new WP_Error( 'user_inactive', 'Your account has been deactivated.', array( 'status' => 403 ) );
}
```

### 4. Team Restrictions

#### Inactive Team Behavior
- **Access Code**: Inactive team access codes are rejected during login
- **Existing Sessions**: Users on inactive teams can complete current session but cannot start new ones
- **Member Assignments**: Can still assign users to inactive teams (for historical purposes)

### 5. Reports & Dashboard

#### Order Reports
- **Filter Option**: "Include Inactive Users/Teams" checkbox (default: unchecked)
- **Visual Indicators**: Mark orders from inactive users/teams with icon/badge
- **Separate Totals**: Show active vs inactive sales separately

#### Dashboard Widgets
- **Users Widget**: Show active count by default, with toggle for inactive
- **Teams Widget**: Show active count by default, with toggle for inactive

### 6. Import/Export Enhancements

#### CSV Structure (No changes needed - already implemented)
```csv
# Status field already supports active/inactive
John Doe, 2035551234, john@example.com, inactive, "Team Alpha"
```

#### Validation
- ✅ Already validates status must be 'active' or 'inactive'

### 7. Database Schema

#### Current Schema (No changes needed)
```sql
-- ss_team_members table
`status` varchar(20) DEFAULT 'active'

-- ss_teams table  
`status` varchar(20) DEFAULT 'active'
```

## Implementation Phases

### Phase 1: Admin UI (High Priority)
- [ ] Add status toggles to user/team list pages
- [ ] Implement visual distinction (grey out, badges)
- [ ] Add default filter to show active only
- [ ] Update user/team count widgets

### Phase 2: PWA Access Control (High Priority)
- [ ] Block inactive users from logging in
- [ ] Invalidate existing sessions for newly inactive users
- [ ] Add appropriate error messages
- [ ] Update REST API auth endpoint

### Phase 3: Reporting (Medium Priority)
- [ ] Add inactive filters to reports
- [ ] Visual indicators in order lists
- [ ] Separate active/inactive totals in dashboard

### Phase 4: Advanced Features (Low Priority)
- [ ] Bulk status change actions
- [ ] Audit log for status changes
- [ ] Reactivation workflow
- [ ] Email notifications when deactivated

## UI Mockups

### User List with Status Toggle
```
┌─────────────────────────────────────────────────────────┐
│ User & Team Management                    [Export][Import]│
├─────────────────────────────────────────────────────────┤
│ [Users] [Teams]                                         │
│                                                         │
│ Show: [✓ Active] [ Inactive] [ All]                    │
│                                                         │
│ ┌─────────────────────────────────────────────────────┐│
│ │ Name          | Phone        | Email      | Teams   ││
│ ├─────────────────────────────────────────────────────┤│
│ │ John Doe      | 203-555-1234 | john@...   | Alpha   ││
│ │ Jane Smith    | 203-555-5678 | jane@...   | Beta    ││
│ └─────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────┘
```

### With Inactive Users Shown
```
┌─────────────────────────────────────────────────────────┐
│ Show: [ Active] [✓ Inactive] [ All]                    │
│                                                         │
│ ┌─────────────────────────────────────────────────────┐│
│ │ Name          | Phone        | Email      | Teams   ││
│ ├─────────────────────────────────────────────────────┤│
│ │ 🚫 Bob Smith (Inactive) | 203-555-9999 | bob@...    ││
│ │    [Reactivate] [Edit]                              ││
│ └─────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────┘
```

## Technical Considerations

### Performance
- Index on `status` column for faster filtering
- Cache active user/team counts

### Data Integrity
- Never delete inactive records (preserve order history)
- Maintain referential integrity in orders table

### Migration
- No database migration needed (status field already exists)
- Existing records default to 'active'

## Testing Checklist

### Admin UI
- [ ] Toggle shows/hides inactive records correctly
- [ ] Visual styling applied to inactive records
- [ ] Counts update correctly

### PWA
- [ ] Inactive users cannot log in
- [ ] Appropriate error message shown
- [ ] Active sessions terminated on status change

### Import/Export
- [ ] Status preserved during export
- [ ] Status updated correctly during import
- [ ] Validation prevents invalid status values

### Reports
- [ ] Filters work correctly
- [ ] Inactive records clearly marked
- [ ] Totals calculated correctly

## Related Files

### Files to Modify (Phase 1 & 2)
- `wordpress-plugin/subsales-management.php` - Admin UI and filters
- `wordpress-plugin/includes/class-rest-api.php` - Auth endpoint changes
- `wordpress-plugin/includes/class-pwa.php` - Session handling
- `wordpress-plugin/assets/css/admin-dashboard.css` - Inactive styling

### Files Already Updated (v2.0.0)
- ✅ Import/Export handlers support status field
- ✅ Database tables have status column

## Notes
- This is a follow-up to the Import/Export feature implemented in v2.0.0
- Import/Export system fully supports inactive status
- Implementation can be done incrementally by phase
- No breaking changes required
