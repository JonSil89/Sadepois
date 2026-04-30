# Sadepois Core: Compliance & Audit Framework

**Document Version:** 1.0.0  
**Last Updated:** 2026-04-30  
**Status:** Production Implementation (PHASE 1 Security Hardening)

---

## 📋 Table of Contents

1. [Executive Summary](#executive-summary)
2. [Compliance Framework](#compliance-framework)
3. [Audit Logging System](#audit-logging-system)
4. [Data Governance](#data-governance)
5. [Security Controls](#security-controls)
6. [Regulatory Alignment](#regulatory-alignment)
7. [Audit Trail Queries](#audit-trail-queries)
8. [Incident Response](#incident-response)
9. [Retention & Archival](#retention--archival)
10. [Compliance Checklist](#compliance-checklist)

---

## 🎯 Executive Summary

**Sadepois Core** is a WordPress plugin designed for **multi-tenant B2B operations** requiring:
- ✅ **Data isolation** between partner organizations
- ✅ **Complete audit trails** for compliance audits
- ✅ **Access control enforcement** with role-based boundaries
- ✅ **NDA acceptance** with timestamped records
- ✅ **Security validation** at all entry points

**Target Industries:**
- Automotive aftermarket (ceramic coatings distributor partnerships)
- Reseller networks
- Franchise operations
- Multi-office professional services

**Compliance Scope:**
- GDPR (data subject rights, data minimization)
- SOC 2 Type II (audit logging, access controls)
- ISO 27001 (information security management)
- General data protection best practices

---

## 🔒 Compliance Framework

### 1. **Data Classification**

| Level | Data Type | Partner Access | Admin Access | Audit Required |
|-------|-----------|-----------------|--------------|-----------------|
| **Public** | Organization metadata | Own partner only | All | Yes |
| **Partner Confidential** | Partner-specific data | Partner team only | All | Yes |
| **Sensitive** | Financial, user credentials | Admin/Owner only | All | Yes |
| **System** | Audit logs, system events | Read-only | Full access | Always |

### 2. **Access Control Matrix**

```
┌─────────────────────────────────────────────────────────────┐
│                  SADEPOIS RBAC MODEL                         │
├──────────────┬──────────────┬──────────────┬────────────────┤
│ Role         │ Users List   │ Partner Mgmt  │ Audit Logs     │
├──────────────┼──────────────┼──────────────┼────────────────┤
│ Admin        │ All users    │ Create/Edit  │ Full read      │
│ Partner User │ Same partner │ View own     │ Own events     │
│ Guest        │ None         │ None         │ None           │
└──────────────┴──────────────┴──────────────┴────────────────┘
```

### 3. **Principle of Least Privilege (PoLP)**

**Implementation:**
- Non-admin users see **only their own partner's team**
- Partner ID field **admin-only edit**
- Audit logs **readable** but not modifiable
- Delete operations **prevented** (archive instead in PHASE 3)

**Enforcement:**
```php
// Every sensitive operation checks:
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Insufficient permissions' );
}
```

---

## 📊 Audit Logging System

### 1. **Database Schema**

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

**Retention:** Minimum 90 days (configurable per regulation)

### 2. **Logged Events**

#### **Authentication Events**

| Event Type | Trigger | Data Logged | Compliance Use |
|-----------|---------|------------|-----------------|
| `login` | User successful login | `username`, `timestamp` | Failed access detection |
| `logout` | User session end | `user_id`, `session_duration` | Session tracking |
| `failed_login` | Failed login attempt | `username`, `IP` (PHASE 2) | Intrusion detection |

**Example Log Entry:**
```json
{
  "id": 1,
  "user_id": 2,
  "event_type": "login",
  "meta": {"username": "partner_a_user"},
  "created_at": "2026-04-29 10:30:00"
}
```

#### **Partner Assignment Events**

| Event Type | Trigger | Data Logged | Compliance Use |
|-----------|---------|------------|-----------------|
| `partner_update` | Admin changes user's partner | `old_partner_id`, `new_partner_id`, `changed_by`, `timestamp` | Data movement tracking |
| `partner_removal` | Partner ID cleared | `removed_partner_id`, `reason` (PHASE 3) | Offboarding tracking |

**Example Log Entry:**
```json
{
  "id": 2,
  "user_id": 2,
  "event_type": "partner_update",
  "meta": {
    "old_partner_id": null,
    "new_partner_id": "PARTNER_A",
    "changed_by": 1,
    "reason": "New employee onboarding"
  },
  "created_at": "2026-04-29 10:31:00"
}
```

#### **NDA Acceptance Events** (PHASE 2)

| Event Type | Trigger | Data Logged | Compliance Use |
|-----------|---------|------------|-----------------|
| `nda_accepted` | User accepts NDA | `user_id`, `nda_version`, `timestamp`, `ip_address` | Consent records |
| `nda_rejected` | User rejects NDA | `user_id`, `reason`, `timestamp` | Rejection tracking |

**Example Log Entry:**
```json
{
  "id": 3,
  "user_id": 5,
  "event_type": "nda_accepted",
  "meta": {
    "nda_version": "2.0",
    "timestamp": "2026-04-29 11:00:00",
    "ip_address": "192.168.1.100"
  },
  "created_at": "2026-04-29 11:00:00"
}
```

#### **Admin Actions** (PHASE 3+)

| Event Type | Trigger | Data Logged | Compliance Use |
|-----------|---------|------------|-----------------|
| `user_archived` | Admin archives user | `user_id`, `archived_at`, `reason` | Offboarding evidence |
| `audit_log_exported` | Admin exports logs | `exported_by`, `date_range`, `record_count` | SOC 2 compliance |

---

### 3. **Audit Log Query Examples**

#### **Query 1: All logins by user (GDPR data subject request)**
```sql
SELECT user_id, event_type, meta, created_at
FROM wp_sadepois_audit_log
WHERE user_id = 5 AND event_type IN ('login', 'logout')
ORDER BY created_at DESC;
```

**Output:**
```
user_id | event_type | meta | created_at
--------|-----------|------|-------------------
5       | login     | {…}  | 2026-04-29 10:30:00
5       | login     | {…}  | 2026-04-28 09:15:00
```

#### **Query 2: All partner changes (audit trail)**
```sql
SELECT id, user_id, event_type, meta, created_at
FROM wp_sadepois_audit_log
WHERE event_type = 'partner_update'
ORDER BY created_at DESC
LIMIT 100;
```

#### **Query 3: Failed access attempts (security)**
```sql
SELECT user_id, COUNT(*) as attempts, MAX(created_at) as last_attempt
FROM wp_sadepois_audit_log
WHERE event_type = 'insufficient_permissions'
  AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY user_id;
```

#### **Query 4: NDA acceptance records (compliance)**
```sql
SELECT user_id, meta, created_at
FROM wp_sadepois_audit_log
WHERE event_type = 'nda_accepted'
  AND created_at >= '2026-01-01'
ORDER BY created_at DESC;
```

---

## 📋 Data Governance

### 1. **Data Subject Rights (GDPR)**

#### **Right to Access**
- **How:** Admin exports audit logs for specific user
- **Tool:** WP CLI command (PHASE 2+)
  ```bash
  wp sadepois export-user-data --user_id=5
  ```
- **Output:** JSON file with all events for user_id=5

#### **Right to Deletion**
- **How:** Archive user (PHASE 3), then purge after retention period
- **Limitation:** Audit logs retained for compliance
- **Process:**
  1. Admin archives user (soft delete)
  2. User_id replaced with `[DELETED_USER_ID]` in logs
  3. After 90 days, logs can be purged

#### **Right to Rectification**
- **How:** Admin corrects partner_id assignment
- **Audit Trail:** Old value logged in `partner_update` event
- **Process:**
  1. Admin updates partner_id
  2. Audit log records `old_partner_id` → `new_partner_id`
  3. No direct data modification (immutable logs)

#### **Right to Restrict Processing**
- **How:** Archive user (PHASE 3)
- **Effect:** User cannot login, but audit logs retained
- **Reversal:** Admin can reactivate

### 2. **Data Minimization**

**Principle:** Only collect data necessary for partner isolation & audit.

| Data Point | Collected | Purpose | Retention |
|-----------|-----------|---------|-----------|
| `user_id` | ✅ | Identify user | Audit period |
| `sp_partner_id` | ✅ | Isolation | User lifecycle |
| `ip_address` | ⚠️ (PHASE 2+) | Security | 30 days |
| `password` | ❌ | Not collected | N/A |
| `email` | ✅ (WP default) | Contact | User lifecycle |

---

## 🔐 Security Controls

### 1. **Input Validation (PHASE 1)**

**Partner ID Validation:**
- ✅ Format: `[A-Za-z0-9_-]+` (alphanumeric, underscore, dash)
- ✅ Length: 1-255 characters
- ✅ Sanitization: `sanitize_text_field()`
- ✅ No special chars, quotes, or HTML

**Code:**
```php
public function sp_validate_partner_id( $partner_id ) {
    if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $partner_id ) ) {
        return array( 'valid' => false, 'error' => 'Invalid format' );
    }
    if ( strlen( $partner_id ) > 255 ) {
        return array( 'valid' => false, 'error' => 'Too long' );
    }
    return array( 'valid' => true );
}
```

### 2. **Nonce Protection (PHASE 1)**

**Form Nonce:**
```php
wp_nonce_field( 'sp_save_partner_' . $user->ID, 'sp_partner_nonce' );
```

**Verification:**
```php
if ( ! wp_verify_nonce( $_POST['sp_partner_nonce'], 'sp_save_partner_' . $user_id ) ) {
    wp_die( 'Security check failed' );
}
```

**Purpose:** Prevent CSRF attacks on partner ID modifications.

### 3. **Capability Checks (PHASE 1)**

**Admin-only Operations:**
```php
if ( ! current_user_can( 'manage_options' ) ) {
    return; // Silent fail for non-admin
}
```

**Applied to:**
- Partner ID field display
- Partner ID save
- Audit log export (PHASE 2+)

### 4. **Output Escaping**

**HTML Context:**
```php
echo esc_html( $partner_id ); // Safe HTML display
```

**Attribute Context:**
```php
<input value="<?php echo esc_attr( $partner_id ); ?>" />
```

**Applied to:**
- Partner ID display in forms
- Audit log exports
- Admin dashboards

### 5. **Database Hardening**

**Prepared Statements:**
```php
$wpdb->insert(
    $table_name,
    array( 'user_id' => $user_id, 'event_type' => $event_type ),
    array( '%d', '%s' ) // Type specifiers prevent SQL injection
);
```

**Indexes for Performance & Audit:**
```sql
KEY user_id (user_id),
KEY event_type (event_type),
KEY created_at (created_at)
```

---

## 📜 Regulatory Alignment

### **1. GDPR Compliance**

| GDPR Article | Requirement | Sadepois Implementation | Status |
|--------------|-------------|------------------------|--------|
| Art. 5 | Lawful basis & transparency | NDA acceptance (PHASE 2) | ✅ Planned |
| Art. 6 | Legal basis for processing | Partner onboarding documentation | ✅ External |
| Art. 12-15 | Data subject rights (access, rectification) | Audit logs enable tracking | ✅ Implemented |
| Art. 17 | Right to deletion | Archive user (PHASE 3) + retention policy | ✅ Planned |
| Art. 32 | Security measures | Nonce, sanitization, access control | ✅ PHASE 1 |
| Art. 33 | Breach notification | Audit logs support incident response | ✅ Implemented |

### **2. SOC 2 Type II Compliance**

| Criterion | Requirement | Sadepois Implementation |
|-----------|-------------|------------------------|
| **CC6.1** | Logical access controls | Capability checks, partner isolation |
| **CC6.2** | Access restrictions | Admin-only Partner ID field |
| **CC7.1** | System monitoring | Audit log for all sensitive operations |
| **CC7.2** | System testing | Audit log queries for incident investigation |

### **3. ISO 27001 Controls**

| Control | Objective | Sadepois Implementation |
|---------|-----------|------------------------|
| **A.6.1.1** | Information security policies | This document |
| **A.7.1.1** | Authorization of information access | Capability matrix (PHASE 1) |
| **A.8.3.1** | Information and other assets | Partner data isolation |
| **A.9.2.1** | Access management | User lifecycle (PHASE 3) |
| **A.12.4.1** | Event logging | Audit logging system |

---

## 🔍 Audit Trail Queries

### **For Compliance Officers**

#### **Generate Monthly Audit Report**
```sql
SELECT 
    DATE(created_at) as date,
    event_type,
    COUNT(*) as event_count
FROM wp_sadepois_audit_log
WHERE created_at >= '2026-03-01'
  AND created_at < '2026-04-01'
GROUP BY DATE(created_at), event_type
ORDER BY date DESC;
```

**Use Case:** SOC 2 monthly reporting

#### **Identify Unusual Access Patterns**
```sql
SELECT 
    user_id,
    COUNT(*) as login_count,
    MIN(created_at) as first_login,
    MAX(created_at) as last_login
FROM wp_sadepois_audit_log
WHERE event_type IN ('login', 'failed_login')
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY user_id
HAVING login_count > 10
ORDER BY login_count DESC;
```

**Use Case:** Intrusion detection

#### **Track Data Movement Between Partners**
```sql
SELECT 
    user_id,
    meta,
    created_at
FROM wp_sadepois_audit_log
WHERE event_type = 'partner_update'
  AND created_at >= '2026-01-01'
ORDER BY user_id, created_at DESC;
```

**Use Case:** Track user reassignments, detect unauthorized transfers

### **For Security Teams**

#### **Failed Permission Attempts**
```sql
SELECT 
    user_id,
    COUNT(*) as attempt_count,
    MAX(created_at) as latest_attempt
FROM wp_sadepois_audit_log
WHERE event_type = 'insufficient_permissions'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY user_id
ORDER BY attempt_count DESC;
```

**Use Case:** Detect privilege escalation attempts

#### **Audit Log Export Activity**
```sql
SELECT 
    user_id,
    meta,
    created_at
FROM wp_sadepois_audit_log
WHERE event_type = 'audit_log_exported'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY created_at DESC;
```

**Use Case:** Track who exports audit data (compliance auditor activities)

---

## 🚨 Incident Response

### **1. Incident Detection**

**Trigger Events:**
- ✅ Multiple failed logins (brute force)
- ✅ User accessing non-partner data
- ✅ Unusual audit log export patterns
- ✅ Partner ID changes without authorization

**Detection Query:**
```sql
-- Find suspicious partner changes
SELECT user_id, meta, created_at
FROM wp_sadepois_audit_log
WHERE event_type = 'partner_update'
  AND JSON_EXTRACT(meta, '$.changed_by') NOT IN (1, 2, 3) -- Authorized admins
  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

### **2. Incident Investigation**

**Step 1: Identify affected user**
```sql
SELECT * FROM wp_sadepois_audit_log
WHERE user_id = 5
  AND created_at >= '2026-04-29 10:00:00'
  AND created_at <= '2026-04-29 12:00:00'
ORDER BY created_at ASC;
```

**Step 2: Find all related events**
```sql
SELECT * FROM wp_sadepois_audit_log
WHERE meta LIKE '%PARTNER_A%'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY created_at DESC;
```

**Step 3: Export for forensics**
```bash
wp db export sadepois-forensics-2026-04-29.sql
```

### **3. Incident Response Workflow**

```
1. DETECT: Alert on suspicious event
   └─ Query: Failed logins or unauthorized partner changes
   
2. ISOLATE: Disable affected user (PHASE 3)
   └─ Command: wp user disable-2fa <user_id>
   
3. ANALYZE: Review audit trail
   └─ Query: All events for user_id in time window
   
4. CONTAIN: Revoke sessions, rotate credentials
   └─ Action: Manual WP session termination
   
5. ERADICATE: Reset user credentials
   └─ Command: wp user update <user_id> --prompt=user_pass
   
6. RECOVER: Restore normal operations
   └─ Action: Re-enable user after validation
   
7. DOCUMENT: Create incident report
   └─ Export: Audit logs + metadata
```

---

## 📦 Retention & Archival

### **1. Retention Policy**

| Data Type | Retention Period | Reason | Archive Method |
|-----------|------------------|--------|-----------------|
| **Audit Logs** | 90 days (default) | Compliance & forensics | Database table |
| **NDA Records** | 7 years | Legal requirement | Separate archive |
| **Failed Logins** | 30 days | Security monitoring | Purge after period |
| **Partner Changes** | Indefinite | Business audit trail | Historical database |

### **2. Archival Process** (PHASE 3+)

**Monthly Archival Script:**
```bash
#!/bin/bash
# Archive audit logs older than 90 days

CUTOFF_DATE=$(date -d "90 days ago" +%Y-%m-%d)

# Export to compressed JSON
wp db query "
  SELECT * FROM wp_sadepois_audit_log 
  WHERE created_at < '$CUTOFF_DATE'
" > audit_archive_$(date +%Y%m%d).json

# Compress for storage
gzip audit_archive_$(date +%Y%m%d).json

# Delete from active table
wp db query "
  DELETE FROM wp_sadepois_audit_log 
  WHERE created_at < '$CUTOFF_DATE'
"
```

### **3. Secure Storage**

**Archive Location:**
```
/var/backups/sadepois-audits/
├── audit_archive_20260329.json.gz
├── audit_archive_20260228.json.gz
└── README.txt (retention policy)
```

**Permissions:**
```bash
chmod 600 /var/backups/sadepois-audits/*
chown root:root /var/backups/sadepois-audits/
```

**Off-site Backup:**
- S3 with versioning enabled
- AES-256 encryption
- Cross-region replication

---

## ✅ Compliance Checklist

### **Pre-Deployment**

- [ ] Audit logging enabled and tested
- [ ] Nonce validation in place (PHASE 1)
- [ ] Input validation regex passes test cases
- [ ] Admin capability checks enforced
- [ ] Database table created with indexes
- [ ] Output escaping applied to all user-facing data

### **Post-Deployment**

- [ ] Audit logs show login events within 1 minute
- [ ] Partner ID changes logged with old/new values
- [ ] Nonce failures rejected with error message
- [ ] Non-admin users cannot see other partners' users
- [ ] Admin can export audit logs

### **Compliance Audits**

#### **Monthly Checklist**
- [ ] Review failed login attempts (Query 1)
- [ ] Confirm NDA acceptance records exist
- [ ] Verify all partner changes logged
- [ ] Check for orphaned user records
- [ ] Archive old logs (>90 days)

#### **Quarterly Checklist**
- [ ] SOC 2 log review
- [ ] GDPR data subject request handling test
- [ ] Incident response drill
- [ ] Access control matrix validation
- [ ] Security control effectiveness review

#### **Annual Checklist**
- [ ] Full SOC 2 Type II audit
- [ ] GDPR compliance assessment
- [ ] ISO 27001 control verification
- [ ] Retention policy enforcement
- [ ] Disaster recovery test

---

## 📞 Support & Escalation

### **Compliance Issues**

| Issue | Contact | Escalation |
|-------|---------|-----------|
| Audit log export | Sadepois admin | CISO |
| GDPR data request | Privacy team | Legal |
| Failed login pattern | Security team | SOC |
| NDA acceptance | Partner manager | Compliance officer |

### **Resources**

- 📖 GDPR: https://gdpr-info.eu/
- 📖 SOC 2: https://www.aicpa.org/interestareas/informationmanagement/sodp-soc2report.html
- 📖 ISO 27001: https://www.iso.org/isoiec-27001-information-security-management.html
- 🔗 Repository: https://github.com/JonSil89/Sadepois

---

## 🔄 Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-04-30 | Copilot | Initial PHASE 1 compliance framework |

---

**Last Reviewed:** 2026-04-30  
**Next Review:** 2026-07-30  
**Classification:** Internal - Compliance Use Only
