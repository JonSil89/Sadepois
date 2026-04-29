# Sadepois Core Plugin

**Multi-tenant WordPress plugin** with NDA enforcement, audit logging, and partner-based data isolation (RBAC Lite).

---

## 📋 Owner's Guide

### What is Sadepois?

Sadepois Core is a WordPress plugin that enables **multi-tenant architecture** for organizations managing multiple partners or subsidiaries. It ensures:

- ✅ **Partner Isolation** - Each user only sees their partner's data
- ✅ **Audit Logging** - All user actions tracked (login, partner changes)
- ✅ **NDA Enforcement** - Admins accept terms before access
- ✅ **User Management** - Assign users to partner organizations

---

### Installation & Activation

1. **Upload plugin** to `/wp-content/plugins/sadepois-core/`
   ```bash
   # Via FTP/FileZilla
   /wp-content/plugins/sadepois-core/sadepois-core.php
   ```

2. **Activate in WordPress Admin**
   - Go to: **Plugins → Installed Plugins**
   - Find: **Sadepois Core**
   - Click: **Activate**

3. **Verify installation**
   - Check database for new table: `wp_sadepois_audit_log`
   - Log in as admin (audit log entry should appear)

---

### Quick Start: Assigning Partners to Users

#### Step 1: Create/Edit a User
1. Go to **WordPress Admin → Users**
2. Click **Edit** on a user OR create new user

#### Step 2: Assign Partner ID
1. Scroll to **"Partner Settings"** section (admin only)
2. Enter **Partner ID** (e.g., `PARTNER_A`, `ACME_CORP`, `SUBSIDIARY_1`)
3. Click **Update Profile**

#### Step 3: Verify Isolation
1. Log in as that user (non-admin)
2. Go to **Users** list
3. They will **only see users with the same Partner ID**

---

### Audit Log Monitoring

View all user activity in the database:

```sql
SELECT * FROM wp_sadepois_audit_log 
ORDER BY created_at DESC;
```

**Example audit log entries:**
```
| id | user_id | event_type       | meta                                      | created_at          |
|----|---------|------------------|-------------------------------------------|---------------------|
| 1  | 2       | login            | {"username":"partner_a_user"}             | 2026-04-29 10:30:00 |
| 2  | 2       | partner_update   | {"old_partner_id":null,"new_partner_id":"PARTNER_A"} | 2026-04-29 10:31:00 |
| 3  | 3       | login            | {"username":"partner_b_user"}             | 2026-04-29 10:32:00 |
```

---

### Common Use Cases

#### 1. Multi-Office Setup
```
Partner ID: "OFFICE_NYC"    → 5 users in New York office
Partner ID: "OFFICE_LA"     → 3 users in Los Angeles office
Partner ID: "OFFICE_LONDON" → 4 users in London office
```
Each office only sees their team.

#### 2. Franchisor + Franchisees
```
Partner ID: "FRANCHISOR"    → Corporate admins (see all)
Partner ID: "FRANCHISEE_01" → Franchise owner + staff
Partner ID: "FRANCHISEE_02" → Another franchise
```

#### 3. Reseller Network
```
Partner ID: "RESELLER_US_WEST"
Partner ID: "RESELLER_US_EAST"
Partner ID: "RESELLER_EU"
```

---

### Troubleshooting

#### ❌ Partner Settings field not appearing?
- **Cause:** Not logged in as admin
- **Fix:** Log in with admin account

#### ❌ Users seeing all users?
- **Cause:** Admin account (bypass is intentional)
- **Fix:** Use non-admin account to test isolation

#### ❌ User has no partner_id assigned?
- **Cause:** Partner field left blank
- **Fix:** Assign a Partner ID in user profile

#### ❌ Audit logs not appearing?
- **Cause:** Database table not created
- **Fix:** Reactivate plugin to trigger table creation

---

### Support & Debugging

**Check plugin status:**
```php
// In WordPress CLI
wp plugin status sadepois-core

// Or in PHP (functions.php)
if ( class_exists( 'SadePois_Core' ) ) {
    echo "✅ Sadepois Core is active";
}
```

**View audit logs via WordPress CLI:**
```bash
wp db query "SELECT * FROM wp_sadepois_audit_log LIMIT 10;"
```

---

## 🔧 Developer Manual

### Architecture Overview

```
sadepois-core/
├── sadepois-core.php          # Main plugin file (only file needed)
├── README.md                   # This file
└── DEVELOPER.md               # Developer guide
```

**No dependencies.** Single PHP file. No external libraries.

---

### Core Classes & Functions

#### Class: `SadePois_Core`

Singleton pattern. Initialize once:

```php
$sadepois = SadePois_Core::get_instance();
```

---

### Key Methods

#### 1. **Get User's Partner ID**

```php
$partner_id = sp_get_user_partner_id( $user_id );
// Returns: string or null
```

**Usage:**
```php
$partner = sp_get_user_partner_id( 42 );
if ( $partner ) {
    echo "User is part of: " . $partner;
} else {
    echo "User has no partner assigned";
}
```

---

#### 2. **Check if Users Are Same Partner**

```php
$is_same = sp_is_same_partner( $user_id_1, $user_id_2 );
// Returns: bool
```

**Usage:**
```php
if ( sp_is_same_partner( 42, 43 ) ) {
    echo "Both users belong to same partner";
} else {
    echo "Users are in different partners";
}
```

---

#### 3. **Assign Partner to User**

```php
sp_set_user_partner_id( $user_id, $partner_id );
// Returns: bool (success)
// Automatically: Logs to audit_log
```

**Usage:**
```php
if ( sp_set_user_partner_id( 42, 'PARTNER_A' ) ) {
    echo "✅ Partner assigned";
} else {
    echo "❌ Failed to assign partner";
}
```

---

### Hooks & Filters

#### **Filter: `pre_get_users`**

Automatically filters users list for non-admins.

**Current behavior:**
```php
// Non-admin sees only:
get_users( array(
    'include' => [ users with same sp_partner_id ]
) );

// Admin sees: all users
```

**To extend:** Hook into `pre_get_users`

```php
add_filter( 'pre_get_users', function( $args ) {
    // Custom logic here
    return $args;
} );
```

---

#### **Action: `wp_login`**

Automatically logs every successful login to audit log.

**Current behavior:**
```php
// On login, audit log records:
event_type: "login"
meta: { "username": "user_login" }
```

---

### Database Schema

#### Table: `wp_sadepois_audit_log`

```sql
CREATE TABLE wp_sadepois_audit_log (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    user_id bigint(20) NOT NULL,
    event_type varchar(50) NOT NULL,
    meta longtext DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY event_type (event_type)
);
```

**Columns:**
- `id` - Unique audit entry ID
- `user_id` - WordPress user ID
- `event_type` - Type of action (e.g., "login", "partner_update")
- `meta` - JSON metadata (stored as string)
- `created_at` - Timestamp

---

### User Meta Fields

#### `sp_partner_id`

- **Key:** `sp_partner_id`
- **Type:** String
- **Max length:** 255 characters
- **Format:** Any alphanumeric string (no special chars)

**Retrieve:**
```php
$partner = get_user_meta( $user_id, 'sp_partner_id', true );
```

**Set:**
```php
update_user_meta( $user_id, 'sp_partner_id', 'PARTNER_A' );
```

---

### Code Examples

#### Example 1: Bulk Assign Partners

```php
// Assign 5 users to PARTNER_A
$user_ids = [ 2, 3, 4, 5, 6 ];
foreach ( $user_ids as $user_id ) {
    sp_set_user_partner_id( $user_id, 'PARTNER_A' );
}
```

---

#### Example 2: Get All Users in a Partner

```php
function get_partner_users( $partner_id ) {
    return get_users( array(
        'meta_key' => 'sp_partner_id',
        'meta_value' => $partner_id,
        'fields' => 'ID',
    ) );
}

$partner_a_users = get_partner_users( 'PARTNER_A' );
// Returns: [ 2, 3, 4, 5, 6 ]
```

---

#### Example 3: Custom Partner Filter in WP_Query

```php
function get_posts_for_partner( $partner_id ) {
    $users = get_partner_users( $partner_id );
    
    return get_posts( array(
        'author__in' => $users,
        'posts_per_page' => -1,
    ) );
}

$posts = get_posts_for_partner( 'PARTNER_A' );
```

---

#### Example 4: Audit Log Query

```php
global $wpdb;

$table = $wpdb->prefix . 'sadepois_audit_log';

$logs = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM $table WHERE event_type = %s ORDER BY created_at DESC LIMIT %d",
    'login',
    50
) );

foreach ( $logs as $log ) {
    echo $log->user_id . " logged in at " . $log->created_at . "\n";
}
```

---

### Safety & Security

#### Input Sanitization

All partner IDs are sanitized:
```php
$partner_id = sanitize_text_field( $partner_id );
// Removes all HTML, scripts, special chars
```

#### Output Escaping

All displayed values are escaped:
```php
echo esc_attr( $partner_id );  // In HTML attributes
echo esc_html_e( $partner_id ); // In HTML content
```

#### Admin Capability Check

Partner field only editable by admins:
```php
if ( ! current_user_can( 'manage_options' ) ) {
    return;
}
```

#### Fail-Safe Empty Partner Handling

If user has no partner_id, they see no other users:
```php
$args['include'] = ! empty( $same_partner_users ) ? $same_partner_users : array( -1 );
// array(-1) returns empty result set
```

---

### Testing

#### Test 1: Partner Isolation

```php
// Create test users
$user_a = wp_create_user( 'user_a', 'pass', 'a@test.com' );
$user_b = wp_create_user( 'user_b', 'pass', 'b@test.com' );

// Assign different partners
sp_set_user_partner_id( $user_a, 'PARTNER_A' );
sp_set_user_partner_id( $user_b, 'PARTNER_B' );

// Verify isolation
assert( sp_is_same_partner( $user_a, $user_b ) === false );
echo "✅ Partner isolation works";
```

---

#### Test 2: Audit Logging

```php
global $wpdb;

$table = $wpdb->prefix . 'sadepois_audit_log';

// Set partner (triggers audit log)
sp_set_user_partner_id( 2, 'PARTNER_A' );

// Check audit log
$logs = $wpdb->get_results( "SELECT * FROM $table WHERE event_type = 'partner_update'" );
assert( count( $logs ) > 0 );
echo "✅ Audit logging works";
```

---

#### Test 3: Admin Bypass

```php
// Login as admin
wp_set_current_user( 1 ); // Assuming ID 1 is admin

// Get users (should return all)
$users = get_users();
echo "✅ Admin sees all users";
```

---

### Adding Custom Partner Logic

To extend functionality, hook into existing filters:

```php
add_filter( 'pre_get_users', function( $args ) {
    // Add custom partner filtering logic
    // Runs AFTER Sadepois default filter
    return $args;
}, 11 ); // Priority 11 (after Sadepois at 10)
```

---

### Performance Considerations

- **User Meta Queries:** Indexed on `meta_key` + `meta_value`
- **Audit Log:** Indexed on `user_id` and `event_type`
- **No N+1 queries:** Helper functions use single meta queries

---

### Roadmap (Future)

- [ ] REST API endpoints for partner management
- [ ] Advanced permission matrix (full RBAC)
- [ ] Partner-level data aggregation dashboards
- [ ] Integration with WooCommerce orders
- [ ] Bulk partner assignment UI

---

### Support & Contributions

**Repository:** https://github.com/JonSil89/Sadepois  
**Issues:** https://github.com/JonSil89/Sadepois/issues

---

### License

GPL-2.0+
