# Sadepois Core: Technical Architecture

**Document Version:** 1.1.0  
**Last Updated:** 2026-04-30  
**Status:** PHASE 1 Implementation  
**Architecture Pattern:** Single-file WordPress plugin with singleton pattern

---

## 📋 Table of Contents

1. [System Overview](#system-overview)
2. [Architecture Principles](#architecture-principles)
3. [Core Components](#core-components)
4. [Data Flow Diagrams](#data-flow-diagrams)
5. [Security Model](#security-model)
6. [Database Schema](#database-schema)
7. [API & Hooks](#api--hooks)
8. [Extensibility Points](#extensibility-points)
9. [Performance Considerations](#performance-considerations)
10. [Deployment Architecture](#deployment-architecture)

---

## 🏗️ System Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Installation                    │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌─────────────────────────────────────────────────────┐   │
│  │         Sadepois Core Plugin (1.1.0)                │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │                                                       │   │
│  │  ┌──────────────────┐     ┌──────────────────────┐  │   │
│  │  │  User Profile    │     │  Audit Logging       │  │   │
│  │  │  Management      │────▶│  System              │  │   │
│  │  │                  │     │                      │  │   │
│  │  │ • Nonce validate │     │ • Event tracking     │  │   │
│  │  │ • Input sanitize │     │ • JSON metadata      │  │   │
│  │  │ • Capability chk │     │ • Timestamp          │  │   │
│  │  └──────────────────┘     └──────────────────────┘  │   │
│  │          ▲                          ▼                │   │
│  │          │                     wp_sadepois_         │   │
│  │          │                     audit_log table      │   │
│  │          │                                          │   │
│  │  ┌──────────────────┐     ┌──────────────────────┐  │   │
│  │  │  Partner        │     │  User Filtering      │  │   │
│  │  │  Isolation      │────▶│  (pre_get_users)     │  │   │
│  │  │                  │     │                      │  │   │
│  │  │ • Same partner   │     │ • Admin bypass       │  │   │
│  │  │   check          │     │ • Partner match      │  │   │
│  │  │ • Data isolation │     │ • Empty partner fail │  │   │
│  │  └──────────────────┘     └──────────────────────┘  │   │
│  │                                                       │   │
│  │  Global Helper Functions:                            │   │
│  │  • sp_get_user_partner_id()                          │   │
│  │  • sp_is_same_partner()                              │   │
│  │  • sp_set_user_partner_id()                          │   │
│  │                                                       │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                               │
│  ┌─────────────────────────────────────────────────────┐   │
│  │         WordPress Core                               │   │
│  │  • Users table (wp_users)                            │   │
│  │  • User meta (wp_usermeta) [sp_partner_id]         │   │
│  │  • Hooks (wp_login, admin_init, etc)               │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 🎯 Architecture Principles

### **1. Singleton Pattern**

**Purpose:** Ensures only one instance of SadePois_Core exists throughout plugin lifecycle.

```php
private static $instance = null;

public static function get_instance() {
    if ( self::$instance === null ) {
        self::$instance = new self();
    }
    return self::$instance;
}
```

**Benefits:**
- ✅ Memory efficient
- ✅ Prevents duplicate hook registration
- ✅ Safe initialization order

### **2. Single File Architecture**

**Why:** WordPress best practice for simple plugins (<500 lines).

```
sadepois-core/
├── sadepois-core.php    (All code in one file)
└── README.md            (Documentation)
```

**Advantages:**
- ✅ No dependencies management
- ✅ Easy to deploy
- ✅ Clear entry point
- ✅ No autoloader needed

### **3. Hook-Based Integration**

**No core WordPress modification** - All integration via hooks:

```php
register_activation_hook()       // Plugin activation
add_action( 'admin_init' )       // Admin initialization
add_action( 'wp_login' )         // Login tracking
add_filter( 'pre_get_users' )    // User filtering
add_action( 'show_user_profile' ) // Profile UI
```

### **4. Metadata-Based Storage**

**Partner assignments stored in user meta:**

```php
// Stored as:
wp_usermeta.meta_key = 'sp_partner_id'
wp_usermeta.meta_value = 'PARTNER_A'

// Accessed as:
get_user_meta( $user_id, 'sp_partner_id', true )
```

**Advantages:**
- ✅ No schema modification
- ✅ Backward compatible
- ✅ Easy to extend (add more meta fields in PHASE 2+)

---

## 🔧 Core Components

### **1. SadePois_Core Class**

**Primary class containing all plugin logic.**

#### **Constructor & Hooks Setup**

```php
public function __construct() {
    $this->setup_hooks();
    $this->create_tables();
}

private function setup_hooks() {
    register_activation_hook( __FILE__, array( $this, 'activate' ) );
    add_action( 'admin_init', array( $this, 'check_nda_acceptance' ) );
    add_action( 'show_user_profile', array( $this, 'sp_user_profile_fields' ) );
    add_action( 'personal_options_update', array( $this, 'sp_save_user_profile_fields' ) );
    add_filter( 'pre_get_users', array( $this, 'sp_filter_users_list' ) );
    add_action( 'wp_login', array( $this, 'audit_log_login' ), 10, 2 );
}
```

**Hooks registered:**
- `register_activation_hook` - Table creation on plugin activate
- `admin_init` - NDA check (PHASE 2+)
- `show_user_profile` / `edit_user_profile` - Partner field in profile
- `personal_options_update` / `edit_user_profile_update` - Save partner field
- `pre_get_users` - Filter user listings
- `wp_login` - Log login events

#### **Component: Audit Logging**

```php
private function sp_audit_log( $user_id, $event_type, $meta = array() ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sadepois_audit_log';
    
    $wpdb->insert(
        $table_name,
        array(
            'user_id'   => $user_id,
            'event_type' => $event_type,
            'meta'      => wp_json_encode( $meta ),
            'created_at' => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s' )
    );
}
```

**Features:**
- ✅ Uses prepared statements (SQL injection safe)
- ✅ JSON metadata for extensibility
- ✅ WordPress timezone handling
- ✅ Type specifications for security

#### **Component: Input Validation (PHASE 1)**

```php
public function sp_validate_partner_id( $partner_id ) {
    $partner_id = trim( $partner_id );
    
    // Check 1: Empty
    if ( empty( $partner_id ) ) {
        return array( 'valid' => false, 'error' => 'Partner ID cannot be empty' );
    }
    
    // Check 2: Length
    if ( strlen( $partner_id ) > 255 ) {
        return array( 'valid' => false, 'error' => 'Partner ID exceeds 255 characters' );
    }
    
    // Check 3: Format (alphanumeric + underscore/dash)
    if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $partner_id ) ) {
        return array( 'valid' => false, 'error' => 'Invalid format' );
    }
    
    return array( 'valid' => true, 'error' => null );
}
```

**Validation layer prevents:**
- SQL injection attempts
- XSS injection
- Invalid data format

#### **Component: Partner Isolation**

```php
public function sp_filter_users_list( $args ) {
    $current_user = wp_get_current_user();
    
    // Admin bypass
    if ( $current_user->has_cap( 'manage_options' ) ) {
        return $args;
    }
    
    // Get current user's partner
    $current_partner = $this->sp_get_user_partner_id( $current_user->ID );
    
    // Fail-safe: if no partner, show nothing
    if ( empty( $current_partner ) ) {
        return $args;
    }
    
    // Query: Get all users with same partner
    $same_partner_users = get_users( array(
        'meta_key' => 'sp_partner_id',
        'meta_value' => $current_partner,
        'fields' => 'ID',
    ) );
    
    // Apply filter (array(-1) returns empty result set)
    $args['include'] = ! empty( $same_partner_users ) ? $same_partner_users : array( -1 );
    
    return $args;
}
```

**Data isolation mechanism:**
- ✅ Non-admin sees only same-partner users
- ✅ Admin sees all users
- ✅ Fail-safe if user has no partner

---

## 📊 Data Flow Diagrams

### **Flow 1: User Login with Audit**

```
┌──────────────┐
│  User Login  │
└──────┬───────┘
       │ (wp_login hook triggered)
       ▼
┌──────────────────────────────┐
│  audit_log_login()           │
├──────────────────────────────┤
│ • Extract: user_login,       │
│   user object                │
│ • Call: sp_audit_log()       │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│  sp_audit_log()              │
├──────────────────────────────┤
│ • Sanitize data              │
│ • JSON encode metadata       │
│ • Prepared statement insert  │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│  wp_sadepois_audit_log       │
│  (Database)                  │
├──────────────────────────────┤
│ id: 1                        │
│ user_id: 2                   │
│ event_type: 'login'          │
│ meta: {"username":"john"}    │
│ created_at: 2026-04-29 10:30 │
└──────────────────────────────┘
```

### **Flow 2: Partner ID Update (PHASE 1)**

```
┌─────────────────────────┐
│  Admin edits user       │
│  Sets partner_id field  │
└────────────┬────────────┘
             │ (personal_options_update hook)
             ▼
┌─────────────────────────────┐
│ sp_save_user_profile_fields │
├─────────────────────────────┤
│ 1. Check admin capability   │
│    ❌ Not admin? wp_die()    │
│ 2. Verify nonce (CSRF)      │
│    ❌ Invalid? wp_die()      │
│ 3. Get POST data            │
│ 4. Call sp_set_user_...()   │
└────────────┬────────────────┘
             │
             ▼
┌──────────────────────────────┐
│ sp_set_user_partner_id()     │
├──────────────────────────────┤
│ 1. Call sp_validate_...()    │
│    ❌ Invalid? Return error   │
│ 2. Get old partner_id        │
│ 3. Sanitize new value        │
│ 4. update_user_meta()        │
│ 5. Call sp_audit_log_...()   │
│ 6. Return success array      │
└────────────┬─────────────────┘
             │
             ├────────────────────┐
             │                    │
             ▼                    ▼
┌──────────────────┐   ┌──────────────────────┐
│ wp_usermeta      │   │ wp_sadepois_         │
│ (Update)         │   │ audit_log (Insert)   │
├──────────────────┤   ├──────────────────────┤
│ user_id: 2       │   │ user_id: 2           │
│ meta_key: ...    │   │ event_type: ...      │
│ meta_value: ...  │   │ meta: old→new        │
│                  │   │ created_at: now      │
└──────────────────┘   └──────────────────────┘
```

### **Flow 3: User List Filtering**

```
┌──────────────────────┐
│ Partner user clicks  │
│ "Users" in admin     │
└──────────┬───────────┘
           │ (pre_get_users hook)
           ▼
┌──────────────────────────────┐
│ sp_filter_users_list()       │
├──────────────────────────────┤
│ 1. Get current user          │
│ 2. Is admin?                 │
│    ✅ Yes? Return unfiltered │
│    ❌ No? Continue...         │
│ 3. Get current user's partner│
│ 4. Query: users with partner │
│ 5. Return filtered args      │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ WordPress WP_User_Query      │
│ Filters results              │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Admin sees:                  │
│ ✅ User A (same partner)     │
│ ✅ User B (same partner)     │
│ ❌ User C (different partner)│
│ ❌ User D (no partner)       │
└──────────────────────────────┘
```

---

## 🔐 Security Model

### **1. OWASP Top 10 Mitigation**

| OWASP Risk | Attack | Mitigation | Code |
|-----------|--------|-----------|------|
| A03:2021 – Injection | SQL Injection | Prepared statements | `$wpdb->insert( $table, $data, $types )` |
| A03:2021 – Injection | XSS (Stored) | Output escaping | `esc_attr()`, `esc_html()` |
| A04:2021 – CSRF | CSRF attacks | Nonce validation | `wp_nonce_field()`, `wp_verify_nonce()` |
| A01:2021 – Broken AC | Unauthorized access | Capability checks | `current_user_can( 'manage_options' )` |
| A07:2021 – ID Testing | Invalid input | Format validation | Regex `[A-Za-z0-9_-]+` |

### **2. Defense in Depth**

```
┌─────────────────────────────────────────┐
│  Layer 1: Client-side Validation        │
│  • HTML5 pattern attribute              │
│  • maxlength="255"                      │
│  • User feedback before submission      │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│  Layer 2: HTTP Layer                    │
│  • CSRF nonce validation                │
│  • Capability check                     │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│  Layer 3: Application Layer             │
│  • Input sanitization                   │
│  • Format validation (regex)            │
│  • Length validation                    │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│  Layer 4: Database Layer                │
│  • Prepared statements                  │
│  • Type specifications                  │
│  • Immutable audit logs                 │
└─────────────────────────────────────────┘
```

### **3. Access Control Model**

```
┌────────────────────────────────────┐
│          Operation                 │
├────────────────────────────────────┤
│ View Partner ID field             │
│  └─ Capability: manage_options     │
│                                    │
│ Edit Partner ID field             │
│  ├─ Capability: manage_options     │
│  ├─ Nonce: sp_save_partner_{id}   │
│  └─ Validation: format & length    │
│                                    │
│ See other users                    │
│  ├─ Non-admin:                     │
│  │  └─ Same partner only           │
│  └─ Admin:                         │
│     └─ All users                   │
│                                    │
│ View Audit Logs                    │
│  └─ Capability: manage_options     │
│     (+ filters in PHASE 2)         │
└────────────────────────────────────┘
```

---

## 📋 Database Schema

### **Audit Log Table**

```sql
CREATE TABLE wp_sadepois_audit_log (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    user_id bigint(20) NOT NULL,
    event_type varchar(50) NOT NULL,
    meta longtext DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY event_type (event_type),
    KEY created_at (created_at)
);
```

**Index Strategy:**

| Index | Purpose | Query Example |
|-------|---------|---------------|
| `PRIMARY KEY (id)` | Unique identifier | Direct row access |
| `KEY user_id` | All events for user | `WHERE user_id = 5` |
| `KEY event_type` | All events of type | `WHERE event_type = 'login'` |
| `KEY created_at` | Date range queries | `WHERE created_at > '2026-04-01'` |

### **User Meta Storage**

```sql
wp_usermeta:
user_id    | meta_key      | meta_value
-----------|---------------|----------------
2          | sp_partner_id | PARTNER_A
3          | sp_partner_id | PARTNER_B
5          | sp_partner_id | PARTNER_A
```

**Why user meta?**
- ✅ No schema modification required
- ✅ Scales horizontally (indexed queries)
- ✅ Backward compatible
- ✅ Easy to extend in PHASE 2+ (add more meta fields)

---

## 🔗 API & Hooks

### **Global Helper Functions**

#### **1. `sp_get_user_partner_id( $user_id )`**

```php
$partner = sp_get_user_partner_id( 42 );
// Returns: 'PARTNER_A' or null
```

**Usage:**
```php
if ( $partner ) {
    echo "User belongs to: " . $partner;
}
```

#### **2. `sp_is_same_partner( $user_id_1, $user_id_2 )`**

```php
$same = sp_is_same_partner( 42, 43 );
// Returns: true or false
```

**Usage:**
```php
if ( sp_is_same_partner( 42, 43 ) ) {
    echo "Users can see each other";
}
```

#### **3. `sp_set_user_partner_id( $user_id, $partner_id )`**

```php
$result = sp_set_user_partner_id( 42, 'PARTNER_A' );
// Returns: array( 'success' => bool, 'message' => string )
```

**Usage:**
```php
$result = sp_set_user_partner_id( 42, 'PARTNER_A' );
if ( $result['success'] ) {
    echo "✅ " . $result['message'];
} else {
    echo "❌ " . $result['message'];
}
```

### **WordPress Hooks**

#### **Filters**

**`pre_get_users` (Priority: default)**

Automatically filters user listings for non-admin users.

```php
// Non-admin sees only:
apply_filters( 'pre_get_users', $args );
// Modifies: $args['include'] = [users with same partner]

// Admin sees: all users (no modification)
```

**Extending:**

```php
add_filter( 'pre_get_users', function( $args ) {
    // Custom logic after Sadepois filter
    // $args['include'] already contains same-partner users
    return $args;
}, 11 ); // Priority > 10 (Sadepois runs at 10)
```

#### **Actions**

**`wp_login` (Priority: 10)**

Logs every successful user login.

```php
do_action( 'wp_login', $user_login, $user );
// Triggers: sp_audit_log( $user->ID, 'login', [...] )
```

**Extending:**

```php
add_action( 'wp_login', function( $user_login, $user ) {
    // Custom login handling
    do_something_with_user( $user->ID );
}, 11, 2 ); // Priority > 10, 2 parameters
```

---

## 🔌 Extensibility Points

### **PHASE 2: NDA Enforcement**

**New hooks to add:**

```php
// After PHASE 2 implementation:
do_action( 'sp_nda_accepted', $user_id, $nda_version );
apply_filters( 'sp_nda_content', $nda_text, $version );
```

### **PHASE 3: User Archival**

**New functions to add:**

```php
sp_archive_user( $user_id );
sp_restore_user( $user_id );
sp_is_user_archived( $user_id );
```

### **PHASE 4: Partner Entity**

**New table:**

```sql
CREATE TABLE wp_sadepois_partners (
    id INT PRIMARY KEY AUTO_INCREMENT,
    partner_id VARCHAR(255) UNIQUE NOT NULL,
    partner_name VARCHAR(255),
    contact_email VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### **PHASE 5: Data Isolation**

**Extend to posts, metadata:**

```php
add_filter( 'pre_get_posts', array( $this, 'sp_filter_posts_list' ) );
add_filter( 'user_has_cap', array( $this, 'sp_check_post_access' ), 10, 4 );
```

---

## ⚡ Performance Considerations

### **1. Database Query Optimization**

**Current indexes:**

```
wp_sadepois_audit_log:
├── PRIMARY KEY (id)           - O(1) lookup
├── KEY user_id (user_id)      - O(log n) for user queries
├── KEY event_type (event_type) - O(log n) for event type queries
└── KEY created_at (created_at) - O(log n) for date range

wp_usermeta:
├── PRIMARY (umeta_id)
├── KEY user_id (user_id)      - Built-in WordPress index
└── KEY meta_key, meta_value   - Built-in WordPress index
```

**Query Performance:**

| Query | Index Used | Estimated Rows | Time |
|-------|-----------|-----------------|------|
| `WHERE user_id = 5` | user_id | 50-100 | <1ms |
| `WHERE event_type = 'login'` | event_type | 10K+ | <10ms |
| `WHERE created_at BETWEEN x AND y` | created_at | 100-1K | <5ms |
| Full table scan | None | ALL | >1s |

### **2. Caching Strategy** (PHASE 2+)

```php
// Current: Direct queries every time
$partner = get_user_meta( $user_id, 'sp_partner_id', true );

// PHASE 2+: Add caching
$partner = wp_cache_get( "sp_partner_$user_id" );
if ( false === $partner ) {
    $partner = get_user_meta( $user_id, 'sp_partner_id', true );
    wp_cache_set( "sp_partner_$user_id", $partner, 'sadepois', 12 * HOUR_IN_SECONDS );
}
```

### **3. Audit Log Pruning** (PHASE 3+)

```bash
# Monthly archival (CRON job)
wp db query "
  DELETE FROM wp_sadepois_audit_log 
  WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
"
```

---

## 🚀 Deployment Architecture

### **System Requirements**

| Component | Requirement | Notes |
|-----------|-------------|-------|
| **PHP** | 7.2+ | WordPress minimum |
| **MySQL** | 5.7+ | For JSON handling |
| **WordPress** | 5.0+ | For nonce API |
| **Plugins** | None | No dependencies |
| **Server** | Standard | Single-server compatible |

### **Deployment Checklist**

```
PRE-DEPLOYMENT:
├─ [ ] Code review (security audit)
├─ [ ] Test on staging (PHASE 1 tests)
├─ [ ] Backup production database
├─ [ ] Plan rollback procedure
└─ [ ] Notify users of deployment window

DEPLOYMENT:
├─ [ ] Upload plugin file
├─ [ ] Activate plugin (triggers table creation)
├─ [ ] Verify audit table created
├─ [ ] Test nonce validation
├─ [ ] Test partner filtering
└─ [ ] Run compliance checklist

POST-DEPLOYMENT:
├─ [ ] Monitor error logs
├─ [ ] Test all user roles
├─ [ ] Verify audit logging works
├─ [ ] Check database performance
└─ [ ] Document deployment date
```

---

## 📞 Architecture Support

For technical questions:
- 📖 Code: https://github.com/JonSil89/Sadepois/blob/main/sadepois-core/sadepois-core.php
- 📖 Database: See "Database Schema" section
- 📖 Security: See "Security Model" section
- 📖 Extensibility: See "Extensibility Points" section

---

**Document Version:** 1.1.0  
**Last Updated:** 2026-04-30  
**Architecture Pattern:** Single-file WordPress plugin with singleton pattern  
**Status:** PHASE 1 Complete ✅
