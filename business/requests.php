<?php
/* FieldPlx Service Requests Page - Version 3.1.0 - 2026-09-09 - Canonical Invoice Reference UI */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Service Requests';
$activePage = 'requests';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['requests_csrf_token'])) {
    $_SESSION['requests_csrf_token'] = bin2hex(random_bytes(32));
}

$requestsCsrfToken = (string)$_SESSION['requests_csrf_token'];

/*
 * Request dashboard statistics are calculated directly on this page.
 * The existing api/requests.php endpoint remains responsible only for
 * the searchable/paginated request table and request CRUD interactions.
 */
$requestStats = array(
    'needs_approval' => 0,
    'new' => 0,
    'assessment_completed' => 0,
    'overdue' => 0,
    'unscheduled' => 0,
    'new_requests_30' => 0,
    'previous_new_requests_30' => 0,
    'new_requests_change' => 0.0,
    'conversion_rate' => 0.0,
    'previous_conversion_rate' => 0.0,
    'conversion_change' => 0.0,
    'converted_requests_30' => 0,
    'requests_30' => 0,
    'current_period_label' => date('M j', strtotime('-29 days')) . ' - ' . date('M j'),
    'previous_period_label' => date('M j', strtotime('-59 days')) . ' - ' . date('M j', strtotime('-30 days'))
);

$requestStatsTenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$requestStatsPdo = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $requestStatsPdo = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $requestStatsPdo = $db;
}

if ($requestStatsTenantId > 0 && $requestStatsPdo instanceof PDO) {
    try {
        $summaryStmt = $requestStatsPdo->prepare(
            "SELECT
                SUM(CASE WHEN r.status = 'quote_required' THEN 1 ELSE 0 END) AS needs_approval,
                SUM(CASE WHEN r.status = 'new' THEN 1 ELSE 0 END) AS new_count,
                SUM(CASE
                    WHEN r.status NOT IN ('converted','closed','cancelled')
                     AND EXISTS (
                        SELECT 1
                        FROM assessments a
                        WHERE a.tenant_id = r.tenant_id
                          AND a.request_id = r.id
                          AND a.status = 'completed'
                     )
                    THEN 1 ELSE 0 END) AS assessment_completed,
                SUM(CASE
                    WHEN r.status NOT IN ('converted','closed','cancelled')
                     AND r.preferred_date IS NOT NULL
                     AND r.preferred_date < CURDATE()
                    THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE
                    WHEN r.status NOT IN ('converted','closed','cancelled')
                     AND r.preferred_date IS NULL
                    THEN 1 ELSE 0 END) AS unscheduled_count,
                SUM(CASE
                    WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                     AND r.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    THEN 1 ELSE 0 END) AS requests_30,
                SUM(CASE
                    WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
                     AND r.created_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                    THEN 1 ELSE 0 END) AS previous_requests_30,
                SUM(CASE
                    WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                     AND r.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                     AND (
                        EXISTS (SELECT 1 FROM quotes q WHERE q.tenant_id = r.tenant_id AND q.request_id = r.id)
                        OR EXISTS (SELECT 1 FROM jobs j WHERE j.tenant_id = r.tenant_id AND j.request_id = r.id AND j.deleted_at IS NULL)
                     )
                    THEN 1 ELSE 0 END) AS converted_30,
                SUM(CASE
                    WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
                     AND r.created_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                     AND (
                        EXISTS (SELECT 1 FROM quotes q2 WHERE q2.tenant_id = r.tenant_id AND q2.request_id = r.id)
                        OR EXISTS (SELECT 1 FROM jobs j2 WHERE j2.tenant_id = r.tenant_id AND j2.request_id = r.id AND j2.deleted_at IS NULL)
                     )
                    THEN 1 ELSE 0 END) AS previous_converted_30
             FROM service_requests r
             WHERE r.tenant_id = :tenant_id"
        );
        $summaryStmt->execute(array(':tenant_id' => $requestStatsTenantId));
        $row = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            $requestStats['needs_approval'] = (int)($row['needs_approval'] ?? 0);
            $requestStats['new'] = (int)($row['new_count'] ?? 0);
            $requestStats['assessment_completed'] = (int)($row['assessment_completed'] ?? 0);
            $requestStats['overdue'] = (int)($row['overdue_count'] ?? 0);
            $requestStats['unscheduled'] = (int)($row['unscheduled_count'] ?? 0);
            $requestStats['new_requests_30'] = (int)($row['requests_30'] ?? 0);
            $requestStats['previous_new_requests_30'] = (int)($row['previous_requests_30'] ?? 0);
            $requestStats['converted_requests_30'] = (int)($row['converted_30'] ?? 0);
            $requestStats['requests_30'] = (int)($row['requests_30'] ?? 0);

            $previousConverted = (int)($row['previous_converted_30'] ?? 0);
            $previousRequests = (int)($row['previous_requests_30'] ?? 0);

            if ($requestStats['previous_new_requests_30'] > 0) {
                $requestStats['new_requests_change'] = (($requestStats['new_requests_30'] - $requestStats['previous_new_requests_30']) / $requestStats['previous_new_requests_30']) * 100;
            } elseif ($requestStats['new_requests_30'] > 0) {
                $requestStats['new_requests_change'] = 100.0;
            }

            if ($requestStats['requests_30'] > 0) {
                $requestStats['conversion_rate'] = ($requestStats['converted_requests_30'] / $requestStats['requests_30']) * 100;
            }
            if ($previousRequests > 0) {
                $requestStats['previous_conversion_rate'] = ($previousConverted / $previousRequests) * 100;
            }
            $requestStats['conversion_change'] = $requestStats['conversion_rate'] - $requestStats['previous_conversion_rate'];
        }
    } catch (Throwable $requestStatsError) {
        // Keep zero-value cards if the dashboard statistic query cannot run.
    }
}

function fieldplxRequestTrendClass($value) {
    if ($value > 0.0001) return 'up';
    if ($value < -0.0001) return 'down';
    return 'flat';
}

function fieldplxRequestTrendArrow($value) {
    if ($value > 0.0001) return '↑';
    if ($value < -0.0001) return '↓';
    return '';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Service Requests - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <style>
        :root {
  --fieldplx-primary: #6d28d9;
  --fieldplx-primary-dark: #5b21b6;
  --fieldplx-text: #1f2937;
  --fieldplx-muted: #6b7280;
  --fieldplx-border: #e5e7eb;
  --fieldplx-surface: #ffffff;
  --fieldplx-background: #f7f7fb;
  --fieldplx-topbar-height: 64px;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  min-height: 100vh;
  overflow-x: hidden;
  background: var(--fieldplx-background);
  color: var(--fieldplx-text);
  font-family: "Inter", sans-serif;
  font-size: 13px;
}

.fieldplx-topbar {
  position: sticky;
  top: 0;
  z-index: 1030;
  min-height: var(--fieldplx-topbar-height);
  background: rgba(255, 255, 255, 0.96);
  border-bottom: 1px solid var(--fieldplx-border);
  backdrop-filter: blur(12px);
}

.fieldplx-topbar-inner {
  min-height: var(--fieldplx-topbar-height);
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 8px 18px;
}

.fieldplx-brand-mobile {
  display: none;
  align-items: center;
  gap: 9px;
  min-width: 0;
  text-decoration: none;
  color: var(--fieldplx-text);
}

.fieldplx-brand-logo {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  border-radius: 9px;
  object-fit: contain;
  background: #f3f0ff;
}

.fieldplx-brand-placeholder {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  color: #ffffff;
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  font-size: 15px;
  font-weight: 700;
}

.fieldplx-brand-name {
  max-width: 170px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 14px;
  font-weight: 700;
}

.fieldplx-menu-toggle {
  width: 36px;
  height: 36px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--fieldplx-border);
  border-radius: 9px;
  background: #ffffff;
  color: #4b5563;
  font-size: 19px;
}

.fieldplx-menu-toggle:hover {
  color: var(--fieldplx-primary);
  border-color: #d8ccfb;
  background: #faf8ff;
}

.fieldplx-page-heading {
  min-width: 0;
  margin-right: auto;
}

.fieldplx-page-title {
  margin: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: #111827;
  font-size: 15px;
  font-weight: 700;
}

.fieldplx-page-subtitle {
  margin-top: 2px;
  color: var(--fieldplx-muted);
  font-size: 11px;
}

.fieldplx-search-wrap {
  width: min(340px, 31vw);
  position: relative;
}

.fieldplx-search-icon {
  position: absolute;
  top: 50%;
  left: 12px;
  z-index: 2;
  transform: translateY(-50%);
  color: #9ca3af;
  font-size: 14px;
  pointer-events: none;
}

.fieldplx-search-input {
  height: 38px;
  padding: 8px 13px 8px 35px;
  border: 1px solid var(--fieldplx-border);
  border-radius: 10px;
  background: #f9fafb;
  box-shadow: none;
  font-size: 12px;
}

.fieldplx-search-input:focus {
  border-color: #c4b5fd;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.09);
}

.fieldplx-topbar-action {
  width: 38px;
  height: 38px;
  padding: 0;
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--fieldplx-border);
  border-radius: 10px;
  background: #ffffff;
  color: #4b5563;
  font-size: 17px;
}

.fieldplx-topbar-action:hover {
  color: var(--fieldplx-primary);
  border-color: #d8ccfb;
  background: #faf8ff;
}

.fieldplx-notification-count {
  position: absolute;
  top: -5px;
  right: -5px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #ffffff;
  border-radius: 999px;
  background: #dc2626;
  color: #ffffff;
  font-size: 9px;
  font-weight: 700;
}

.fieldplx-profile-button {
  min-width: 0;
  padding: 4px 8px 4px 5px;
  display: flex;
  align-items: center;
  gap: 9px;
  border: 1px solid var(--fieldplx-border);
  border-radius: 11px;
  background: #ffffff;
  text-align: left;
}

.fieldplx-profile-button:hover {
  border-color: #d8ccfb;
  background: #faf8ff;
}

.fieldplx-avatar {
  width: 32px;
  height: 32px;
  flex: 0 0 32px;
  overflow: hidden;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
}

.fieldplx-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.fieldplx-profile-details {
  max-width: 145px;
  min-width: 0;
}

.fieldplx-profile-name,
.fieldplx-profile-role {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.fieldplx-profile-name {
  color: #111827;
  font-size: 11px;
  font-weight: 700;
}

.fieldplx-profile-role {
  margin-top: 1px;
  color: var(--fieldplx-muted);
  font-size: 9px;
}

.fieldplx-dropdown {
  width: 340px;
  max-width: calc(100vw - 24px);
  padding: 0;
  margin-top: 10px !important;
  overflow: hidden;
  border: 1px solid var(--fieldplx-border);
  border-radius: 14px;
  background: #ffffff;
  box-shadow: 0 14px 34px rgba(31, 41, 55, 0.12);
}

.fieldplx-dropdown-header {
  min-height: 48px;
  padding: 11px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--fieldplx-border);
  background: #ffffff;
}

.fieldplx-dropdown-title {
  margin: 0;
  color: #111827;
  font-size: 14px;
  line-height: 1.2;
  font-weight: 700;
}

.fieldplx-notification-item {
  padding: 11px 14px;
  display: flex;
  gap: 10px;
  border-bottom: 1px solid #f1f2f4;
  color: inherit;
  text-decoration: none;
}

.fieldplx-notification-item:hover {
  background: #faf8ff;
}

.fieldplx-notification-item.is-unread {
  background: #fbf9ff;
}

.fieldplx-notification-icon {
  width: 32px;
  height: 32px;
  flex: 0 0 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  background: #f3e8ff;
  color: #7c3aed;
  font-size: 14px;
}

.fieldplx-notification-content {
  min-width: 0;
}

.fieldplx-notification-title {
  margin: 0;
  color: #111827;
  font-size: 11px;
  font-weight: 700;
}

.fieldplx-notification-message {
  margin-top: 3px;
  overflow: hidden;
  display: -webkit-box;
  color: var(--fieldplx-muted);
  font-size: 10px;
  line-height: 1.45;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.fieldplx-notification-time {
  margin-top: 4px;
  color: #9ca3af;
  font-size: 9px;
}

.fieldplx-empty-notifications {
  min-height: 155px;
  padding: 28px 18px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  color: #718096;
  background: #ffffff;
  font-size: 13px;
  line-height: 1.45;
}

.fieldplx-empty-notifications i {
  display: block;
  margin-bottom: 10px;
  color: #b9a8ff;
  font-size: 30px;
  line-height: 1;
}

.fieldplx-dropdown-footer {
  min-height: 44px;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-top: 1px solid var(--fieldplx-border);
  text-align: center;
  background: #ffffff;
}

.fieldplx-dropdown-footer a {
  color: var(--fieldplx-primary);
  font-size: 11px;
  font-weight: 700;
  text-decoration: none;
}

.fieldplx-dropdown-footer a:hover {
  text-decoration: underline;
}

.fieldplx-profile-menu {
  width: 230px;
  padding: 7px;
  border: 1px solid var(--fieldplx-border);
  border-radius: 12px;
  box-shadow: 0 18px 50px rgba(31, 41, 55, 0.13);
}

.fieldplx-profile-menu-header {
  padding: 9px 10px 11px;
  border-bottom: 1px solid #f0f1f3;
}

.fieldplx-profile-menu-name {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: #111827;
  font-size: 12px;
  font-weight: 700;
}

.fieldplx-profile-menu-email {
  margin-top: 2px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: var(--fieldplx-muted);
  font-size: 10px;
}

.fieldplx-profile-menu .dropdown-item {
  padding: 9px 10px;
  display: flex;
  align-items: center;
  gap: 9px;
  border-radius: 8px;
  color: #374151;
  font-size: 11px;
}

.fieldplx-profile-menu .dropdown-item:hover {
  color: var(--fieldplx-primary);
  background: #faf8ff;
}

.fieldplx-profile-menu .dropdown-item.text-danger:hover {
  color: #b91c1c !important;
  background: #fff5f5;
}

.fieldplx-main-layout {
  display: flex;
  min-height: calc(100vh - var(--fieldplx-topbar-height));
}

.fieldplx-main-content {
  min-width: 0;
  flex: 1;
}

.fieldplx-content-wrapper {
  padding: 18px;
}

@media (max-width: 991.98px) {
  .fieldplx-brand-mobile {
    display: flex;
  }

  .fieldplx-page-heading {
    display: none;
  }

  .fieldplx-search-wrap {
    margin-left: auto;
    width: min(280px, 40vw);
  }

  .fieldplx-profile-details {
    display: none;
  }

  .fieldplx-profile-button {
    padding-right: 5px;
  }
}

@media (max-width: 767.98px) {
  .fieldplx-topbar-inner {
    gap: 8px;
    padding: 8px 11px;
  }

  .fieldplx-brand-name {
    display: none;
  }

  .fieldplx-search-wrap {
    display: none;
  }

  .fieldplx-topbar-spacer {
    margin-left: auto;
  }

  .fieldplx-dropdown {
    width: min(330px, calc(100vw - 22px));
  }

  .fieldplx-content-wrapper {
    padding: 12px;
  }
}

:root {
  --fieldplx-sidebar-width: 246px;
  --fieldplx-sidebar-collapsed-width: 72px;
}

.fieldplx-sidebar {
  width: var(--fieldplx-sidebar-width);
  min-width: var(--fieldplx-sidebar-width);
  height: calc(100vh - var(--fieldplx-topbar-height));
  position: sticky;
  top: var(--fieldplx-topbar-height);
  z-index: 1020;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-right: 1px solid var(--fieldplx-border);
  transition:
    width 0.22s ease,
    min-width 0.22s ease,
    transform 0.22s ease;
}

.fieldplx-sidebar-header {
  min-height: 64px;
  padding: 10px 13px;
  display: flex;
  align-items: center;
  border-bottom: 1px solid #f0f1f3;
}

.fieldplx-sidebar-brand {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  color: #111827;
  text-decoration: none;
}

.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  border-radius: 10px;
}

.fieldplx-sidebar-logo {
  object-fit: contain;
  background: #f7f4ff;
}

.fieldplx-sidebar-logo-placeholder {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  color: #ffffff;
  font-size: 16px;
  font-weight: 700;
}

.fieldplx-sidebar-brand-text {
  min-width: 0;
  display: block;
}

.fieldplx-sidebar-company-name {
  max-width: 160px;
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 12px;
  font-weight: 700;
}

.fieldplx-sidebar-product-name {
  margin-top: 1px;
  display: block;
  color: #8b5cf6;
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.4px;
  text-transform: uppercase;
}

.fieldplx-sidebar-close {
  width: 32px;
  height: 32px;
  margin-left: auto;
  padding: 0;
  display: none;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #6b7280;
  font-size: 16px;
}

.fieldplx-sidebar-body {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 12px 9px;
  scrollbar-width: thin;
  scrollbar-color: #d8d4e5 transparent;
}

.fieldplx-sidebar-section-label {
  margin: 4px 10px 7px;
  color: #9ca3af;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.65px;
  text-transform: uppercase;
}

.fieldplx-sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.fieldplx-sidebar-link {
  width: 100%;
  min-height: 39px;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: #4b5563;
  text-align: left;
  text-decoration: none;
  font-family: inherit;
  font-size: 11px;
  font-weight: 500;
  transition:
    color 0.16s ease,
    background 0.16s ease;
}

.fieldplx-sidebar-link:hover {
  background: #f8f6ff;
  color: #6d28d9;
}

.fieldplx-sidebar-link.active {
  background: #f0ebff;
  color: #6d28d9;
  font-weight: 700;
}

.fieldplx-sidebar-link-icon {
  width: 20px;
  height: 20px;
  flex: 0 0 20px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
}

.fieldplx-sidebar-link-text {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.fieldplx-sidebar-arrow {
  margin-left: auto;
  color: #9ca3af;
  font-size: 10px;
  transition: transform 0.2s ease;
}

.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow {
  transform: rotate(180deg);
}

.fieldplx-sidebar-submenu {
  max-height: 0;
  overflow: hidden;
  padding-left: 39px;
  transition: max-height 0.25s ease;
}

.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-submenu {
  max-height: 520px;
  padding-top: 3px;
  padding-bottom: 3px;
}

.fieldplx-sidebar-sublink {
  min-height: 31px;
  padding: 7px 9px;
  position: relative;
  display: flex;
  align-items: center;
  border-radius: 7px;
  color: #6b7280;
  text-decoration: none;
  font-size: 10px;
  font-weight: 500;
}

.fieldplx-sidebar-sublink::before {
  width: 5px;
  height: 5px;
  margin-right: 9px;
  flex: 0 0 5px;
  content: "";
  border-radius: 50%;
  background: #d1d5db;
}

.fieldplx-sidebar-sublink:hover {
  background: #faf8ff;
  color: #6d28d9;
}

.fieldplx-sidebar-sublink.active {
  background: #f7f3ff;
  color: #6d28d9;
  font-weight: 700;
}

.fieldplx-sidebar-sublink.active::before {
  background: #7c3aed;
}

.fieldplx-sidebar-empty {
  margin: 8px 10px 14px;
  padding: 14px 12px;
  display: grid;
  justify-items: center;
  gap: 7px;
  border: 1px dashed #ddd6fe;
  border-radius: 10px;
  background: #faf8ff;
  color: #7c3aed;
  font-size: 9px;
  line-height: 1.5;
  text-align: center;
}

.fieldplx-sidebar-empty i {
  font-size: 17px;
}

.fieldplx-sidebar-footer {
  padding: 10px;
  border-top: 1px solid #f0f1f3;
}

.fieldplx-sidebar-user {
  padding: 8px;
  display: flex;
  align-items: center;
  gap: 9px;
  border-radius: 10px;
  background: #fafafa;
}

.fieldplx-sidebar-user-avatar {
  width: 31px;
  height: 31px;
  flex: 0 0 31px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
}

.fieldplx-sidebar-user-details {
  min-width: 0;
  flex: 1;
}

.fieldplx-sidebar-user-name,
.fieldplx-sidebar-user-role {
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.fieldplx-sidebar-user-name {
  color: #111827;
  font-size: 10px;
  font-weight: 700;
}

.fieldplx-sidebar-user-role {
  margin-top: 1px;
  color: #9ca3af;
  font-size: 8px;
}

.fieldplx-sidebar-logout {
  width: 29px;
  height: 29px;
  flex: 0 0 29px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  color: #9ca3af;
  text-decoration: none;
  font-size: 14px;
}

.fieldplx-sidebar-logout:hover {
  background: #fee2e2;
  color: #dc2626;
}

.fieldplx-sidebar-overlay {
  display: none;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
  width: var(--fieldplx-sidebar-collapsed-width);
  min-width: var(--fieldplx-sidebar-collapsed-width);
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
  display: none;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
  justify-content: center;
  padding-left: 8px;
  padding-right: 8px;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link {
  justify-content: center;
  padding-left: 8px;
  padding-right: 8px;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
  justify-content: center;
  padding-left: 5px;
  padding-right: 5px;
}

@media (max-width: 991.98px) {
  .fieldplx-sidebar {
    width: 260px;
    min-width: 260px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    z-index: 1050;
    transform: translateX(-100%);
    box-shadow: none;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
    transform: translateX(0);
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: 260px;
    min-width: 260px;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
    display: block;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
    display: block;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
    justify-content: initial;
  }

  .fieldplx-sidebar-close {
    display: inline-flex;
  }

  .fieldplx-sidebar-overlay {
    position: fixed;
    inset: 0;
    z-index: 1040;
    display: block;
    visibility: hidden;
    background: rgba(17, 24, 39, 0.42);
    opacity: 0;
    transition:
      opacity 0.2s ease,
      visibility 0.2s ease;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
    visibility: visible;
    opacity: 1;
  }
}

.fieldplx-fallback-sidebar {
  width: 236px;
  min-width: 236px;
  height: calc(100vh - var(--fieldplx-topbar-height));
  position: sticky;
  top: var(--fieldplx-topbar-height);
  z-index: 1020;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--fieldplx-border);
  background: #ffffff;
}

.fieldplx-fallback-brand {
  min-height: 62px;
  padding: 11px 13px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-bottom: 1px solid #f1f5f9;
}

.fieldplx-fallback-logo {
  width: 37px;
  height: 37px;
  flex: 0 0 37px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  color: #ffffff;
  font-size: 14px;
  font-weight: 700;
}

.fieldplx-fallback-brand-text {
  min-width: 0;
}

.fieldplx-fallback-brand-text strong,
.fieldplx-fallback-brand-text small {
  display: block;
}

.fieldplx-fallback-brand-text strong {
  max-width: 155px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: #111827;
  font-size: 11px;
}

.fieldplx-fallback-brand-text small {
  margin-top: 2px;
  color: #8b5cf6;
  font-size: 8px;
  font-weight: 700;
  letter-spacing: 0.4px;
  text-transform: uppercase;
}

.fieldplx-fallback-nav {
  flex: 1;
  overflow-y: auto;
  padding: 10px 8px;
}

.fieldplx-fallback-nav a,
.fieldplx-fallback-footer a {
  min-height: 38px;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-radius: 9px;
  color: #4b5563;
  font-size: 10px;
  font-weight: 600;
  text-decoration: none;
}

.fieldplx-fallback-nav a:hover,
.fieldplx-fallback-nav a.active {
  background: #f0ebff;
  color: #6d28d9;
}

.fieldplx-fallback-nav i,
.fieldplx-fallback-footer i {
  width: 19px;
  flex: 0 0 19px;
  font-size: 14px;
  text-align: center;
}

.fieldplx-fallback-footer {
  padding: 10px;
  border-top: 1px solid #f1f5f9;
}

.fieldplx-fallback-footer a:hover {
  background: #fef2f2;
  color: #dc2626;
}

@media (max-width: 991.98px) {
  .fieldplx-fallback-sidebar {
    display: none;
  }
}

:root {
  --fieldplx-primary: #74b824;
  --fieldplx-primary-dark: #5d971b;
  --fieldplx-text: #0b1933;
  --fieldplx-muted: #6f7b90;
  --fieldplx-border: #e5eaf1;
  --fieldplx-surface: #ffffff;
  --fieldplx-background: #f6f8fb;
  --fieldplx-topbar-height: 70px;
  --fieldplx-sidebar-width: 250px;
  --fieldplx-sidebar-collapsed-width: 78px;
  --fd-navy: #001131;
  --fd-navy-light: #071f49;
  --fd-blue: #123d70;
  --fd-green: #74b824;
  --fd-green-dark: #5d971b;
  --fd-green-soft: #f0f8e5;
  --fd-orange: #96c945;
  --fd-red: #e45b66;
  --fd-bg: #f6f8fb;
  --fd-text: #0b1933;
  --fd-muted: #6f7b90;
  --fd-border: #e5eaf1;
}

body {
  background: var(--fd-bg) !important;
  color: var(--fd-text);
  font-family: Arial, Helvetica, sans-serif !important;
  font-size: 14px;
}

.fieldplx-topbar {
  min-height: 70px !important;
  margin-left: var(--fieldplx-sidebar-width);
  width: calc(100% - var(--fieldplx-sidebar-width));
  background: #fff !important;
  border-bottom: 1px solid var(--fd-border) !important;
  box-shadow: 0 3px 14px rgba(0, 17, 49, 0.035);
  backdrop-filter: none !important;
  transition:
    margin-left 0.25s ease,
    width 0.25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-topbar {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
  width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}

.fieldplx-topbar-inner {
  min-height: 70px !important;
  padding: 0 27px !important;
  gap: 13px !important;
}

.fieldplx-page-heading {
  display: none !important;
}

.fieldplx-menu-toggle,
.fieldplx-topbar-action {
  width: 41px !important;
  height: 41px !important;
  border: 0 !important;
  border-radius: 9px !important;
  color: var(--fd-navy) !important;
  background: transparent !important;
}

.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
  color: var(--fd-navy) !important;
  background: var(--fd-green-soft) !important;
}

.fieldplx-search-wrap {
  width: 280px !important;
  margin-left: auto;
}

.fieldplx-search-input {
  height: 41px !important;
  padding-left: 38px !important;
  border: 0 !important;
  border-radius: 8px !important;
  background: #f5f8fb !important;
  color: var(--fd-text) !important;
  font-size: 12px !important;
}

.fieldplx-search-input:focus {
  background: #f5f8fb !important;
  box-shadow: 0 0 0 3px rgba(116, 184, 36, 0.14) !important;
}

.fieldplx-profile-button {
  padding: 2px !important;
  border: 0 !important;
  border-radius: 9px !important;
  background: transparent !important;
}

.fieldplx-profile-button:hover {
  background: var(--fd-green-soft) !important;
}

.fieldplx-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  border-radius: 50% !important;
  border: 0 !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg, #fff, #e8f3d9) !important;
  font-size: 12px !important;
  font-weight: 800 !important;
}

.fieldplx-profile-name {
  font-size: 12px !important;
}

.fieldplx-profile-role {
  color: var(--fd-muted) !important;
  font-size: 10px !important;
}

.fieldplx-notification-count {
  background: var(--fd-red) !important;
}

.fieldplx-dropdown,
.fieldplx-profile-menu {
  border-color: var(--fd-border) !important;
  box-shadow: 0 18px 45px rgba(29, 38, 74, 0.14) !important;
}

.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover {
  color: var(--fd-green-dark) !important;
}

.fieldplx-sidebar {
  width: var(--fieldplx-sidebar-width) !important;
  min-width: var(--fieldplx-sidebar-width) !important;
  height: 100vh !important;
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  z-index: 1045 !important;
  color: #fff !important;
  background: linear-gradient(
    180deg,
    var(--fd-navy-light),
    var(--fd-navy)
  ) !important;

  border-right: 0 !important;
  transition:
    width 0.25s ease,
    min-width 0.25s ease,
    transform 0.25s ease !important;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
  width: var(--fieldplx-sidebar-collapsed-width) !important;
  min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}

.fieldplx-sidebar-header {
  min-height: 68px !important;
  padding: 9px 14px 10px !important;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-brand {
  color: #fff !important;
}

.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
  width: 40px !important;
  height: 40px !important;
  flex: 0 0 40px !important;
  border-radius: 10px !important;
}

.fieldplx-sidebar-logo-placeholder {
  color: #fff !important;
  background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
  font-size: 18px !important;
}

.fieldplx-sidebar-company-name {
  max-width: 155px !important;
  color: #fff !important;
  font-size: 16px !important;
  font-weight: 700 !important;
}

.fieldplx-sidebar-product-name {
  color: #9fda55 !important;
  font-size: 9px !important;
}

.fieldplx-sidebar-body {
  padding: 12px 14px !important;
  scrollbar-width: none !important;
}

.fieldplx-sidebar-body::-webkit-scrollbar {
  display: none;
}

.fieldplx-sidebar-section-label {
  margin: 7px 12px 7px !important;
  color: rgba(255, 255, 255, 0.5) !important;
  font-size: 9px !important;
}

.fieldplx-sidebar-nav {
  gap: 3px !important;
}

.fieldplx-sidebar-link {
  min-height: 46px !important;
  margin-bottom: 3px !important;
  padding: 0 14px !important;
  gap: 15px !important;
  border-radius: 9px !important;
  color: rgba(255, 255, 255, 0.94) !important;
  font-size: 14px !important;
  font-weight: 600 !important;
}

.fieldplx-sidebar-link:hover {
  color: #fff !important;
  background: rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
  color: #fff !important;
  background: linear-gradient(90deg, #7fc92d, #68aa1d) !important;
  box-shadow: 0 6px 18px rgba(0, 17, 49, 0.28) !important;
}

.fieldplx-sidebar-link-icon {
  width: 21px !important;
  height: 21px !important;
  flex: 0 0 21px !important;
  font-size: 19px !important;
}

.fieldplx-sidebar-arrow {
  color: rgba(255, 255, 255, 0.65) !important;
}

.fieldplx-sidebar-submenu {
  padding-left: 36px !important;
}

.fieldplx-sidebar-sublink {
  min-height: 34px !important;
  color: rgba(255, 255, 255, 0.72) !important;
  font-size: 11px !important;
}

.fieldplx-sidebar-sublink::before {
  background: rgba(255, 255, 255, 0.35) !important;
}

.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active {
  color: #fff !important;
  background: rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-sublink.active::before {
  background: #9fda55 !important;
}

.fieldplx-sidebar-footer {
  padding: 10px 14px 14px !important;
  border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-user {
  min-height: 62px;
  background: rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-user-name {
  color: #fff !important;
  font-size: 12px !important;
}

.fieldplx-sidebar-user-role {
  color: rgba(255, 255, 255, 0.6) !important;
  font-size: 9px !important;
}

.fieldplx-sidebar-user-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg, #fff, #e8f3d9) !important;
}

.fieldplx-sidebar-logout {
  color: rgba(255, 255, 255, 0.7) !important;
}

.fieldplx-sidebar-logout:hover {
  color: #fff !important;
  background: rgba(228, 91, 102, 0.3) !important;
}

.fieldplx-main-layout {
  display: block !important;
  min-height: calc(100vh - 70px) !important;
}

.fieldplx-main-content {
  margin-left: var(--fieldplx-sidebar-width);
  min-width: 0;
  transition: margin-left 0.25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-main-content {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
}

.fieldplx-content-wrapper {
  padding: 0 !important;
}

.fieldplx-footer {
  display: block !important;
}

.fd-dashboard {
  width: 100%;
  max-width: 1600px;
  margin: auto;
  padding: 25px 27px 35px;
}

.fd-dashboard .row > * {
  min-width: 0;
}

.fd-welcome {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 23px;
}

.fd-welcome h1 {
  margin: 0 0 8px;
  color: var(--fd-text);
  font-size: 21px;
  font-weight: 700;
}

.fd-welcome p {
  margin: 0;
  color: var(--fd-muted);
  font-size: 12px;
}

.fd-date-actions {
  display: flex;
  gap: 9px;
}

.fd-date-button,
.fd-filter-button {
  height: 46px;
  border: 1px solid var(--fd-border);
  border-radius: 9px;
  color: var(--fd-navy);
  background: #fff;
  box-shadow: 0 5px 15px rgba(31, 43, 88, 0.05);
  text-decoration: none;
}

.fd-date-button {
  min-width: 213px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 11px;
  padding: 0 14px;
  font-size: 11px;
  font-weight: 700;
}

.fd-filter-button {
  width: 46px;
  display: grid;
  place-items: center;
}

.fd-date-button:hover,
.fd-filter-button:hover {
  border-color: #cfe3ae;
  color: var(--fd-green-dark);
  background: #f9fcf4;
}

.fd-card {
  height: 100%;
  border: 1px solid var(--fd-border);
  border-radius: 9px;
  background: #fff;
  box-shadow: 0 4px 14px rgba(31, 43, 88, 0.05);
}

/* Summary cards - clean reference style */
.fd-stat-card {
  position: relative;
  min-height: 112px;
  padding: 18px 20px;
  overflow: hidden;
  border: 1px solid #dfe6ef;
  border-radius: 12px;
  background: #ffffff;
  box-shadow: 0 3px 12px rgba(24, 45, 76, 0.035);
}

.fd-stat-more {
  position: absolute;
  top: 14px;
  right: 15px;
  color: #8b9bb0;
  font-size: 18px;
  line-height: 1;
}

.fd-stat-row {
  display: flex;
  align-items: center;
  gap: 18px;
  min-height: 72px;
}

.fd-stat-row > div {
  min-width: 0;
}

.fd-stat-icon {
  width: 58px;
  height: 58px;
  flex: 0 0 58px;
  display: grid;
  place-items: center;
  border-radius: 16px;
  color: #ffffff;
  background: #123f73 !important;
  font-size: 26px;
}

.fd-stat-icon i {
  line-height: 1;
}

.fd-stat-icon.blue,
.fd-stat-icon.green,
.fd-stat-icon.lime,
.fd-stat-icon.orange {
  background: #123f73 !important;
}

.fd-stat-label {
  display: block;
  margin-bottom: 8px;
  color: #506784;
  font-size: 13px;
  line-height: 1.2;
  font-weight: 400;
}

.fd-stat-value {
  display: block;
  color: #020b16;
  font-size: 31px;
  line-height: 1;
  font-weight: 700;
  letter-spacing: -0.5px;
}

.fd-stat-card .fd-growth,
.fd-stat-card .fd-sparkline {
  display: none !important;
}

.fd-growth {
  display: block;
  margin-top: 14px;
  color: #8a95a8;
  font-size: 9px;
}

.fd-growth strong {
  font-size: 10px;
}

.fd-growth.up strong {
  color: var(--fd-green-dark);
}

.fd-growth.down strong {
  color: var(--fd-red);
}

.fd-growth.flat strong {
  color: #7d899d;
}

.fd-sparkline {
  position: absolute;
  right: 18px;
  bottom: 7px;
  left: 18px;
  height: 45px;
}

.fd-panel {
  padding: 18px;
}

.fd-panel-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 13px;
}

.fd-panel-title h2 {
  margin: 0;
  color: var(--fd-text);
  font-size: 14px;
  font-weight: 700;
}

.fd-chart-card {
  min-height: 313px;
}

.fd-chart-area {
  position: relative;
  height: 245px;
}

.fd-chart-area canvas {
  width: 100% !important;
  height: 100% !important;
}

.fd-chart-legend {
  color: var(--fd-muted);
  font-size: 10px;
  white-space: nowrap;
}

.fd-status-wrapper {
  min-height: 245px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 22px;
}

.fd-donut {
  position: relative;
  width: 165px;
  height: 165px;
  flex: 0 0 165px;
  display: grid;
  place-items: center;
  border-radius: 50%;
}

.fd-donut::before {
  position: absolute;
  width: 104px;
  height: 104px;
  border-radius: 50%;
  background: #fff;
  content: "";
}

.fd-donut-center {
  position: relative;
  z-index: 1;
  text-align: center;
}

.fd-donut-center strong {
  display: block;
  color: var(--fd-text);
  font-size: 21px;
}

.fd-donut-center small {
  color: var(--fd-muted);
  font-size: 10px;
}

.fd-status-legend {
  display: flex;
  flex-direction: column;
  gap: 11px;
}

.fd-legend-row {
  display: flex;
  gap: 8px;
  color: var(--fd-muted);
  font-size: 10px;
  line-height: 1.45;
}

.fd-legend-dot {
  width: 8px;
  height: 8px;
  flex: 0 0 8px;
  margin-top: 3px;
  border-radius: 50%;
}

.fd-legend-row strong {
  color: var(--fd-text);
}

.fd-tasks-count {
  padding: 4px 8px;
  border-radius: 999px;
  color: var(--fd-green-dark);
  background: var(--fd-green-soft);
  font-size: 9px;
  font-weight: 700;
}

.fd-task-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.fd-task-item {
  min-height: 41px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--fd-border);
  border-radius: 8px;
  color: inherit;
  background: #fbfcfa;
  text-decoration: none;
  transition:
    border-color 0.2s ease,
    background 0.2s ease;
}

.fd-task-item:hover {
  border-color: #cfe3ae;
  color: inherit;
  background: #f7fbed;
}

.fd-task-check {
  width: 17px;
  height: 17px;
  flex: 0 0 17px;
  display: grid;
  place-items: center;
  border: 1px solid #cdd3df;
  border-radius: 4px;
  color: #fff;
  font-size: 10px;
}

.fd-task-item.complete {
  background: #f5faee;
}

.fd-task-item.complete .fd-task-check {
  border-color: var(--fd-green);
  background: var(--fd-green);
}

.fd-task-content {
  min-width: 0;
  flex: 1;
}

.fd-task-content strong,
.fd-task-content small {
  display: block;
}

.fd-task-content strong {
  overflow: hidden;
  color: var(--fd-navy);
  font-size: 10px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fd-task-content small {
  margin-top: 2px;
  color: var(--fd-muted);
  font-size: 9px;
}

.fd-task-item.complete .fd-task-content strong {
  color: #8792a4;
  text-decoration: line-through;
}

.fd-task-time {
  flex: 0 0 auto;
  padding: 4px 7px;
  border-radius: 999px;
  color: #5c6b81;
  background: #eef2f6;
  font-size: 8.5px;
  font-weight: 700;
  white-space: nowrap;
}

.fd-task-footer {
  display: flex;
  justify-content: flex-end;
  padding-top: 7px;
}

.fd-link {
  color: var(--fd-green-dark);
  font-size: 10px;
  font-weight: 600;
  text-decoration: none;
}

.fd-link:hover {
  color: var(--fd-green);
}

.fd-recent-jobs-card {
  min-height: 360px;
  overflow: hidden;
}

.fd-view-button {
  padding: 6px 11px;
  border: 1px solid var(--fd-border);
  border-radius: 5px;
  color: #53627a;
  background: #fff;
  font-size: 10px;
  text-decoration: none;
}

.fd-view-button:hover {
  border-color: #cfe3ae;
  color: var(--fd-green-dark);
  background: #f9fcf4;
}

.fd-jobs-table {
  min-width: 820px;
  margin: 4px 0 0;
  white-space: nowrap;
}

.fd-jobs-table th {
  padding: 11px 6px;
  border-bottom-color: var(--fd-border);
  color: #65738a;
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
}

.fd-jobs-table td {
  padding: 12px 6px;
  border-bottom-color: #f1f3f7;
  color: #33445f;
  font-size: 9.5px;
  vertical-align: middle;
}

.fd-job-name {
  color: var(--fd-text);
  font-weight: 700;
}

.fd-status {
  display: inline-flex;
  padding: 5px 7px;
  border-radius: 5px;
  font-size: 9px;
  font-weight: 600;
}

.fd-status.progress {
  color: #123d70;
  background: #edf2f7;
}

.fd-status.completed {
  color: #5d971b;
  background: #f0f8e5;
}

.fd-status.pending {
  color: #678a23;
  background: #f5f9ea;
}

.fd-status.cancelled {
  color: #b9444d;
  background: #fff0f1;
}

.fd-action-link {
  width: 28px;
  height: 28px;
  display: grid;
  place-items: center;
  border-radius: 6px;
  color: #66748b;
  text-decoration: none;
}

.fd-action-link:hover {
  color: var(--fd-green-dark);
  background: var(--fd-green-soft);
}

.fd-schedule-event {
  min-height: 45px;
  display: grid;
  grid-template-columns: 10px 58px 1fr;
  align-items: start;
  color: inherit;
  text-decoration: none;
}

.fd-schedule-event:hover .fd-schedule-info strong {
  color: var(--fd-green-dark);
}

.fd-schedule-dot {
  width: 8px;
  height: 8px;
  margin-top: 3px;
  border-radius: 50%;
  background: var(--fd-green);
}

.fd-schedule-time {
  padding-top: 1px;
  color: var(--fd-muted);
  font-size: 9px;
}

.fd-schedule-info strong,
.fd-schedule-info small {
  display: block;
}

.fd-schedule-info strong {
  color: var(--fd-text);
  font-size: 10px;
}

.fd-schedule-info small {
  margin-top: 2px;
  color: var(--fd-muted);
  font-size: 9px;
}

.fd-activity-item {
  display: flex;
  gap: 10px;
  padding: 8px 0;
}

.fd-activity-icon {
  width: 30px;
  height: 30px;
  flex: 0 0 30px;
  display: grid;
  place-items: center;
  border-radius: 9px;
}

.fd-activity-icon.green,
.fd-activity-icon.lime {
  color: var(--fd-green-dark);
  background: #f0f8e5;
}

.fd-activity-icon.orange {
  color: #789d2c;
  background: #f4f9ea;
}

.fd-activity-icon.blue {
  color: #123d70;
  background: #edf2f7;
}

.fd-activity-content strong,
.fd-activity-content small {
  display: block;
}

.fd-activity-content strong {
  color: var(--fd-text);
  font-size: 10px;
}

.fd-activity-content small {
  margin-top: 2px;
  color: var(--fd-muted);
  font-size: 9px;
  line-height: 1.4;
}

.fd-bottom-card {
  position: relative;
  min-height: 132px;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 22px;
  overflow: hidden;
}

.fd-bottom-icon {
  width: 52px;
  height: 52px;
  flex: 0 0 52px;
  display: grid;
  place-items: center;
  border-radius: 14px;
  font-size: 22px;
}

.fd-bottom-content small,
.fd-bottom-content strong,
.fd-bottom-content span {
  display: block;
}

.fd-bottom-content small {
  color: var(--fd-muted);
  font-size: 10px;
  font-weight: 600;
}

.fd-bottom-content strong {
  margin-top: 5px;
  color: var(--fd-text);
  font-size: 23px;
  line-height: 1.1;
}

.fd-bottom-content span {
  margin-top: 7px;
  color: #33445f;
  font-size: 9px;
  font-weight: 700;
}

.fd-bottom-content .growth {
  color: var(--fd-green-dark);
}

.fd-empty {
  min-height: 120px;
  display: grid;
  place-items: center;
  padding: 20px;
  color: #9aa4b3;
  font-size: 10px;
  text-align: center;
}

@media (max-width: 1199.98px) {
  .fd-status-wrapper {
    gap: 14px;
  }
}

@media (max-width: 991.98px) {
  .fieldplx-topbar,
  body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: 250px !important;
    min-width: 250px !important;
    transform: translateX(-100%);
    box-shadow: none !important;
    filter: none !important;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
    transform: translateX(0) !important;
  }

  .fieldplx-main-content,
  body.fieldplx-sidebar-collapsed .fieldplx-main-content {
    margin-left: 0 !important;
  }

  .fieldplx-sidebar-brand-text,
  .fieldplx-sidebar-section-label,
  .fieldplx-sidebar-link-text,
  .fieldplx-sidebar-arrow,
  .fieldplx-sidebar-user-details,
  .fieldplx-sidebar-logout,
  .fieldplx-sidebar-submenu {
    display: initial;
  }
}

@media (max-width: 767.98px) {
  :root {
    --fieldplx-topbar-height: 64px;
  }

  .fieldplx-topbar,
  .fieldplx-topbar-inner {
    min-height: 64px !important;
  }

  .fieldplx-topbar-inner {
    padding: 0 13px !important;
  }

  .fieldplx-search-wrap {
    display: none !important;
  }

  .fd-dashboard {
    padding: 17px 13px 28px;
  }

  .fd-welcome {
    align-items: flex-start;
  }

  .fd-welcome h1 {
    font-size: 19px;
  }

  .fd-welcome p {
    max-width: 260px;
    font-size: 11px;
    line-height: 1.5;
  }

  .fd-date-button {
    min-width: 46px;
    width: 46px;
    padding: 0;
    justify-content: center;
  }

  .fd-date-button span,
  .fd-date-button .bi-chevron-down {
    display: none;
  }

  .fd-stat-card {
    min-height: 108px;
    padding: 17px 18px;
  }

  .fd-donut {
    width: 145px;
    height: 145px;
    flex-basis: 145px;
  }

  .fd-donut::before {
    width: 91px;
    height: 91px;
  }
}

@media (max-width: 420px) {
  .fd-welcome {
    min-height: 65px;
    gap: 10px;
  }

  .fd-date-actions {
    gap: 5px;
  }

  .fd-filter-button {
    width: 42px;
  }

  .fd-status-wrapper {
    transform: scale(0.92);
    margin-inline: -14px;
  }
}

@media (max-width: 575.98px) {
  .fd-stat-card {
    min-height: 102px;
    padding: 15px 17px;
  }

  .fd-stat-row {
    gap: 15px;
    min-height: 66px;
  }

  .fd-stat-icon {
    width: 54px;
    height: 54px;
    flex-basis: 54px;
    border-radius: 15px;
    font-size: 24px;
  }

  .fd-stat-label {
    margin-bottom: 7px;
  }

  .fd-stat-value {
    font-size: 28px;
  }

  .fd-stat-row {
    gap: 18px;
    min-height: 72px;
  }

  .fd-stat-value {
    font-size: 29px;
  }
}

.fieldplx-footer {
  min-height: 52px;
  margin-left: var(--fieldplx-sidebar-width);
  border-top: 1px solid var(--fieldplx-border);
  background: #ffffff;
  transition:
    margin-left 0.22s ease,
    background-color 0.22s ease;
}

.fieldplx-footer-inner {
  min-height: 52px;
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 18px;
  color: #6b7280;
  font-size: 10px;
}

.fieldplx-footer-copyright {
  min-width: 0;
}

.fieldplx-footer-links {
  display: flex;
  align-items: center;
  gap: 8px;
}

.fieldplx-footer-links a {
  color: #6b7280;
  text-decoration: none;
  transition: color 0.18s ease;
}

.fieldplx-footer-links a:hover {
  color: var(--fieldplx-primary);
}

.fieldplx-footer-separator {
  color: #d1d5db;
  font-size: 8px;
}

.fieldplx-footer-product {
  margin-left: auto;
  white-space: nowrap;
  color: #9ca3af;
}

.fieldplx-footer-product strong {
  color: var(--fieldplx-primary);
  font-weight: 700;
}

body.fieldplx-sidebar-collapsed .fieldplx-footer {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
}

@media (max-width: 991.98px) {
  .fieldplx-footer {
    margin-left: 0;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-footer {
    margin-left: 0;
  }
}

@media (max-width: 767.98px) {
  .fieldplx-footer-inner {
    padding: 12px;
    flex-wrap: wrap;
    justify-content: center;
    gap: 7px 14px;
    text-align: center;
  }

  .fieldplx-footer-product {
    width: 100%;
    margin-left: 0;
  }
}

/* Notification dropdown correction */
.dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
  right: 0 !important;
  left: auto !important;
  width: 340px !important;
  max-width: calc(100vw - 24px) !important;
  margin-top: 10px !important;
  border: 1px solid var(--fd-border) !important;
  border-radius: 14px !important;
  background: #ffffff !important;
  box-shadow: 0 14px 34px rgba(29, 38, 74, 0.12) !important;
}

#topbarNotificationList {
  max-height: 300px;
  overflow-y: auto;
  background: #ffffff;
}

.fieldplx-empty-notifications {
  min-height: 155px !important;
  padding: 28px 18px 24px !important;
}

.fieldplx-dropdown-footer {
  border-top: 1px solid var(--fd-border) !important;
}

@media (max-width: 575.98px) {
  .dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
    width: min(320px, calc(100vw - 20px)) !important;
  }

  .fieldplx-empty-notifications {
    min-height: 135px !important;
    padding: 22px 15px !important;
  }
}

/* ==========================================================
   FieldPlx mobile sidebar final correction
   Desktop sidebar appearance is intentionally unchanged.
   ========================================================== */
@media (max-width: 991.98px) {
  html,
  body {
    overflow-x: hidden !important;
  }

  body.fieldplx-sidebar-mobile-open {
    overflow: hidden !important;
  }

  .fieldplx-topbar,
  body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-main-content,
  body.fieldplx-sidebar-collapsed .fieldplx-main-content {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: min(300px, calc(100vw - 52px)) !important;
    min-width: 0 !important;
    max-width: 300px !important;
    height: 100vh !important;
    height: 100dvh !important;
    position: fixed !important;
    top: 0 !important;
    bottom: 0 !important;
    left: 0 !important;
    z-index: 1060 !important;

    display: flex !important;
    flex-direction: column !important;

    overflow: hidden !important;
    visibility: hidden !important;
    transform: translate3d(-100%, 0, 0) !important;

    border-right: 0 !important;
    box-shadow: none !important;
    filter: none !important;

    transition:
      transform 0.25s ease,
      visibility 0.25s ease !important;

    will-change: transform;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
  body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed
    .fieldplx-sidebar {
    visibility: visible !important;
    transform: translate3d(0, 0, 0) !important;
  }

  .fieldplx-sidebar-header,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
    flex: 0 0 auto !important;
    justify-content: flex-start !important;
    padding-left: 14px !important;
    padding-right: 10px !important;
  }

  .fieldplx-sidebar-close {
    width: 34px !important;
    height: 34px !important;
    margin-left: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;

    color: rgba(255, 255, 255, 0.88) !important;
    background: rgba(255, 255, 255, 0.08) !important;
  }

  .fieldplx-sidebar-close:hover {
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.14) !important;
  }

  .fieldplx-sidebar-body {
    min-height: 0 !important;
    flex: 1 1 auto !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
  }

  .fieldplx-sidebar-footer {
    flex: 0 0 auto !important;
  }

  /* Never allow the desktop collapsed state to hide mobile labels. */
  .fieldplx-sidebar-brand-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text {
    display: block !important;
  }

  .fieldplx-sidebar-section-label,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label {
    display: block !important;
  }

  .fieldplx-sidebar-link-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text {
    display: block !important;
  }

  .fieldplx-sidebar-arrow,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow {
    display: inline-flex !important;
  }

  .fieldplx-sidebar-user-details,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details {
    display: block !important;
  }

  .fieldplx-sidebar-logout,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
    display: inline-flex !important;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
    justify-content: flex-start !important;
  }

  /* Restore proper accordion behavior on mobile.
       Do not use display:initial here: it turns the submenu into inline
       content and breaks max-height animation/spacing. */
  .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
    display: block !important;
    max-height: 0 !important;
    overflow: hidden !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;

    transition:
      max-height 0.25s ease,
      padding-top 0.25s ease,
      padding-bottom 0.25s ease !important;
  }

  .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-menu.menu-open
    > .fieldplx-sidebar-submenu {
    display: block !important;
    max-height: 680px !important;
    padding-top: 4px !important;
    padding-bottom: 5px !important;
  }

  .fieldplx-sidebar-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 1055 !important;

    display: block !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;

    background: rgba(0, 17, 49, 0.48) !important;
    transition:
      opacity 0.25s ease,
      visibility 0.25s ease !important;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
    visibility: visible !important;
    opacity: 1 !important;
    pointer-events: auto !important;
  }
}

@media (max-width: 575.98px) {
  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: min(288px, calc(100vw - 44px)) !important;
  }

  .fieldplx-sidebar-body {
    padding-left: 10px !important;
    padding-right: 10px !important;
  }

  .fieldplx-sidebar-link {
    min-height: 43px !important;
    padding-left: 12px !important;
    padding-right: 12px !important;
    gap: 12px !important;
    font-size: 13px !important;
  }

  .fieldplx-sidebar-submenu {
    padding-left: 31px !important;
  }

  .fieldplx-sidebar-sublink {
    min-height: 33px !important;
    font-size: 11px !important;
  }
}

    
/* Employees page */
.fd-employees-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.fd-employees-title{margin:0 0 7px;color:var(--fd-text);font-size:21px;font-weight:700}.fd-employees-subtitle{margin:0;color:var(--fd-muted);font-size:11px}.fd-employees-actions{display:flex;gap:8px}.fd-employee-btn{min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;background:#fff;color:#43546c;font-size:10px;font-weight:700;cursor:pointer}.fd-employee-btn.primary{border-color:var(--fd-green);background:linear-gradient(90deg,#7fc92d,#68aa1d);color:#fff}.fd-employee-btn:hover{border-color:#cfe3ae;background:#f9fcf4;color:var(--fd-green-dark)}.fd-employee-btn.primary:hover{background:linear-gradient(90deg,#74b824,#5d971b);color:#fff}.fd-employee-btn.danger{border-color:#ffd5d9;color:#b9444d}.fd-employee-loader{width:13px;height:13px;display:none;border:2px dotted currentColor;border-radius:50%;animation:eSpin .75s linear infinite}.fd-employee-btn.loading .fd-employee-loader{display:inline-block}@keyframes eSpin{to{transform:rotate(360deg)}}
.fd-employee-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}.fd-employee-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}.fd-employee-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;background:#123f73;color:#fff;font-size:25px}.fd-employee-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:13px}.fd-employee-stat-value{display:block;color:#020b16;font-size:31px;line-height:1;font-weight:700}.fd-employees-card{overflow:hidden}.fd-employees-toolbar{padding:13px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-search{width:270px;position:relative}.fd-employee-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a96a7}.fd-employee-search input,.fd-employee-filter{height:39px;border:1px solid #dde4ec;border-radius:8px;background:#fff;color:#33445f;font-size:10px;outline:0}.fd-employee-search input{width:100%;padding:8px 11px 8px 34px}.fd-employee-filter{min-width:140px;padding:8px 10px}.fd-employee-toolbar-spacer{margin-left:auto}.fd-employee-table-wrap{overflow-x:auto}.fd-employee-table{width:100%;min-width:1180px;border-collapse:collapse;white-space:nowrap}.fd-employee-table th{padding:11px 12px;border-bottom:1px solid var(--fd-border);background:#f8fafc;color:#65738a;font-size:9px;font-weight:600;text-transform:uppercase}.fd-employee-table td{padding:12px;border-bottom:1px solid #f1f3f7;color:#33445f;font-size:9.5px}.fd-employee-person{display:flex;align-items:center;gap:10px}.fd-employee-avatar{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:50%;background:linear-gradient(135deg,#fff,#e8f3d9);border:1px solid #dce8cf;color:var(--fd-navy);font-size:10px;font-weight:700;overflow:hidden}.fd-employee-avatar img{width:100%;height:100%;object-fit:cover}.fd-employee-person strong,.fd-employee-person small{display:block}.fd-employee-person small{margin-top:2px;color:#8d98a8;font-size:8.5px}.fd-employee-badge{display:inline-flex;padding:5px 7px;border-radius:5px;font-size:8.5px;font-weight:600}.fd-employee-badge.active,.fd-employee-badge.field{color:#5d971b;background:#f0f8e5}.fd-employee-badge.inactive{color:#6f7b90;background:#eef2f6}.fd-employee-badge.invited,.fd-employee-badge.admin{color:#123d70;background:#edf2f7}.fd-employee-badge.suspended{color:#b9444d;background:#fff0f1}.fd-employee-actions-cell{display:flex;gap:5px}.fd-employee-icon{width:29px;height:29px;display:grid;place-items:center;border:0;border-radius:6px;background:transparent;color:#66748b;cursor:pointer}.fd-employee-icon:hover{background:var(--fd-green-soft);color:var(--fd-green-dark)}.fd-employee-icon.danger:hover{background:#fff0f1;color:#b9444d}.fd-employee-empty{padding:28px 18px!important;text-align:center;color:#9aa4b3!important;font-size:10px!important}.fd-employee-pagination{padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--fd-border);font-size:9px;color:#768397}
.fd-employee-modal-backdrop{position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.46);backdrop-filter:blur(3px)}.fd-employee-modal-backdrop.show{display:flex}.fd-employee-modal{width:min(860px,100%);max-height:calc(100vh - 36px);overflow:auto;border:1px solid #dfe5ec;border-radius:12px;background:#fff;box-shadow:0 24px 65px rgba(0,17,49,.24)}.fd-employee-modal-header{padding:11px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-modal-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;background:var(--fd-green-soft);color:var(--fd-green-dark)}.fd-employee-modal-heading{flex:1}.fd-employee-modal-heading h3{margin:0;font-size:12px}.fd-employee-modal-heading p{margin:3px 0 0;color:var(--fd-muted);font-size:8.5px}.fd-employee-modal-close{width:30px;height:30px;border:0;border-radius:7px;background:transparent;color:#8490a0}.fd-employee-modal-body{padding:15px}.fd-employee-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.fd-employee-field.full{grid-column:1/-1}.fd-employee-field label{display:block;margin-bottom:6px;color:#42536c;font-size:9px;font-weight:700}.fd-employee-field input,.fd-employee-field select{width:100%;min-height:40px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;background:#fff;color:#263750;font-size:10px;outline:0}.fd-employee-section{grid-column:1/-1;padding:7px 0 2px;border-bottom:1px solid #eef2f5;color:#31425b;font-size:9px;font-weight:700;text-transform:uppercase}.fd-employee-switches{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.fd-employee-switch-row{padding:10px;border:1px solid var(--fd-border);border-radius:9px;background:#fbfcfd;display:flex;align-items:center;justify-content:space-between}.fd-employee-switch-row strong,.fd-employee-switch-row small{display:block}.fd-employee-switch-row strong{font-size:9.5px}.fd-employee-switch-row small{margin-top:2px;color:#8a96a7;font-size:8px}.fd-employee-switch input{width:15px;height:15px;accent-color:var(--fd-green)}.fd-employee-modal-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-confirm{width:min(440px,100%)}
.fd-employee-toast{width:min(290px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:8px 9px;display:flex;align-items:center;gap:7px;border-radius:7px;color:#fff;opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s ease;box-shadow:0 10px 26px rgba(0,17,49,.18)}.fd-employee-toast.show{opacity:1;transform:translateY(0)}.fd-employee-toast.success{background:#5d971b}.fd-employee-toast.error{background:#e45b66}.fd-employee-toast.warning{background:#96a52f}.fd-employee-toast.info{background:#123d70}.fd-employee-toast span{font-size:8.5px}.fd-employee-toast button{margin-left:auto;border:0;background:transparent;color:#fff}@media(max-width:767.98px){.fd-employees-header{flex-direction:column}.fd-employee-grid{grid-template-columns:1fr}.fd-employee-field.full,.fd-employee-section,.fd-employee-switches{grid-column:auto}.fd-employee-switches{grid-template-columns:1fr}.fd-employee-search{width:100%}.fd-employee-toolbar-spacer{display:none}}@media(max-width:575.98px){.fd-employee-toast{top:72px;left:12px;right:12px;width:auto}.fd-employee-modal-footer{flex-direction:column-reverse}.fd-employee-modal-footer .fd-employee-btn{width:100%}}

/* ==========================================================
   Teams page - canonical tenant template
   ========================================================== */
.fd-teams-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}
.fd-teams-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  line-height:1.2;
  font-weight:700;
}
.fd-teams-subtitle{
  margin:0;
  max-width:780px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}
.fd-teams-actions{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.fd-team-button{
  min-height:39px;
  padding:0 13px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  border:1px solid var(--fd-border);
  border-radius:8px;
  color:#43546c;
  background:#fff;
  box-shadow:0 4px 12px rgba(31,43,88,.04);
  font-size:10px;
  font-weight:700;
  text-decoration:none;
  cursor:pointer;
}
.fd-team-button:hover{
  border-color:#cfe3ae;
  color:var(--fd-green-dark);
  background:#f9fcf4;
}
.fd-team-button.primary{
  border-color:var(--fd-green);
  color:#fff;
  background:linear-gradient(90deg,#7fc92d,#68aa1d);
  box-shadow:0 7px 16px rgba(104,170,29,.18);
}
.fd-team-button.primary:hover{
  color:#fff;
  background:linear-gradient(90deg,#74b824,#5d971b);
}
.fd-team-button.danger{
  border-color:#ffd5d9;
  color:#b9444d;
  background:#fff;
}
.fd-team-button:disabled{
  opacity:.58;
  cursor:not-allowed;
}
.fd-team-loader{
  width:13px;
  height:13px;
  display:none;
  border:2px dotted currentColor;
  border-radius:50%;
  animation:fdTeamSpin .75s linear infinite;
}
.fd-team-button.loading .fd-team-loader{display:inline-block}
@keyframes fdTeamSpin{to{transform:rotate(360deg)}}

.fd-teams-summary{margin-bottom:16px}
.fd-team-stat-card{
  min-height:112px;
  padding:18px 20px;
  border:1px solid #dfe6ef;
  border-radius:12px;
  background:#fff;
  box-shadow:0 3px 12px rgba(24,45,76,.035);
}
.fd-team-stat-row{
  min-height:72px;
  display:flex;
  align-items:center;
  gap:18px;
}
.fd-team-stat-icon{
  width:58px;
  height:58px;
  flex:0 0 58px;
  display:grid;
  place-items:center;
  border-radius:16px;
  color:#fff;
  background:#123f73;
  font-size:25px;
}
.fd-team-stat-label{
  display:block;
  margin-bottom:8px;
  color:#506784;
  font-size:13px;
}
.fd-team-stat-value{
  display:block;
  color:#020b16;
  font-size:31px;
  line-height:1;
  font-weight:700;
}

.fd-teams-card{overflow:hidden}
.fd-teams-toolbar{
  padding:13px 14px;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}
.fd-team-search{width:270px;position:relative}
.fd-team-search i{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  color:#8a96a7;
  font-size:13px;
}
.fd-team-search input,
.fd-team-filter{
  height:39px;
  border:1px solid #dde4ec;
  border-radius:8px;
  outline:0;
  color:#33445f;
  background:#fff;
  font-size:10px;
}
.fd-team-search input{
  width:100%;
  padding:8px 11px 8px 34px;
}
.fd-team-filter{
  min-width:140px;
  padding:8px 10px;
}
.fd-team-search input:focus,
.fd-team-filter:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}
.fd-team-toolbar-spacer{margin-left:auto}

.fd-team-table-wrap{
  width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}
.fd-team-table-wrap::-webkit-scrollbar{height:3px!important}
.fd-team-table-wrap::-webkit-scrollbar-track{background:transparent!important}
.fd-team-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  border-radius:999px!important;
  background:#9aa0a6!important;
}
.fd-team-table-wrap::-webkit-scrollbar-button{
  width:0!important;
  height:0!important;
  display:none!important;
}
.fd-team-table{
  width:100%;
  min-width:1080px;
  margin:0;
  border-collapse:collapse;
  white-space:nowrap;
}
.fd-team-table th{
  padding:11px 12px;
  border-bottom:1px solid var(--fd-border);
  color:#65738a;
  background:#f8fafc;
  font-size:9px;
  font-weight:600;
  text-transform:uppercase;
}
.fd-team-table td{
  padding:12px;
  border-bottom:1px solid #f1f3f7;
  color:#33445f;
  font-size:9.5px;
  vertical-align:middle;
}
.fd-team-table tbody tr:hover{background:#fbfcfa}

.fd-team-name{
  display:flex;
  align-items:center;
  gap:10px;
}
.fd-team-name-icon{
  width:36px;
  height:36px;
  flex:0 0 36px;
  display:grid;
  place-items:center;
  border-radius:10px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:15px;
}
.fd-team-name strong,
.fd-team-name small{display:block}
.fd-team-name strong{
  color:var(--fd-text);
  font-size:10.5px;
}
.fd-team-name small{
  margin-top:2px;
  color:#8d98a8;
  font-size:8.5px;
}
.fd-team-badge{
  display:inline-flex;
  align-items:center;
  padding:5px 7px;
  border-radius:5px;
  font-size:8.5px;
  font-weight:600;
}
.fd-team-badge.active{
  color:#5d971b;
  background:#f0f8e5;
}
.fd-team-badge.inactive{
  color:#6f7b90;
  background:#eef2f6;
}
.fd-team-badge.primary{
  color:#123d70;
  background:#edf2f7;
}
.fd-team-actions-cell{
  display:flex;
  align-items:center;
  gap:5px;
}
.fd-team-icon-button{
  width:29px;
  height:29px;
  padding:0;
  display:grid;
  place-items:center;
  border:0;
  border-radius:6px;
  color:#66748b;
  background:transparent;
  cursor:pointer;
  font-size:12px;
}
.fd-team-icon-button:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}
.fd-team-icon-button.danger:hover{
  color:#b9444d;
  background:#fff0f1;
}
.fd-team-empty{
  padding:28px 18px!important;
  text-align:center;
  color:#9aa4b3!important;
  font-size:10px!important;
}
.fd-team-pagination{
  min-height:49px;
  padding:10px 14px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  border-top:1px solid var(--fd-border);
  color:#768397;
  background:#fff;
  font-size:9px;
}
.fd-team-pagination-actions{display:flex;gap:5px}

.fd-team-modal-backdrop{
  position:fixed;
  inset:0;
  z-index:15000;
  display:none;
  align-items:center;
  justify-content:center;
  padding:18px;
  background:rgba(0,17,49,.46);
  backdrop-filter:blur(3px);
}
.fd-team-modal-backdrop.show{display:flex}
.fd-team-modal{
  width:min(860px,100%);
  max-height:calc(100vh - 36px);
  overflow:auto;
  border:1px solid #dfe5ec;
  border-radius:12px;
  background:#fff;
  box-shadow:0 24px 65px rgba(0,17,49,.24);
}
.fd-team-modal-header{
  min-height:58px;
  padding:11px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}
.fd-team-modal-icon{
  width:34px;
  height:34px;
  display:grid;
  place-items:center;
  border-radius:9px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:15px;
}
.fd-team-modal-heading{min-width:0;flex:1}
.fd-team-modal-heading h3{
  margin:0;
  color:var(--fd-text);
  font-size:12px;
  font-weight:700;
}
.fd-team-modal-heading p{
  margin:3px 0 0;
  color:var(--fd-muted);
  font-size:8.5px;
}
.fd-team-modal-close{
  width:30px;
  height:30px;
  display:grid;
  place-items:center;
  border:0;
  border-radius:7px;
  color:#8490a0;
  background:transparent;
  cursor:pointer;
}
.fd-team-modal-body{padding:15px}
.fd-team-form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:13px;
}
.fd-team-field.full{grid-column:1/-1}
.fd-team-field label{
  margin-bottom:6px;
  display:block;
  color:#42536c;
  font-size:9px;
  font-weight:700;
}
.fd-team-field input,
.fd-team-field select,
.fd-team-field textarea{
  width:100%;
  min-height:40px;
  padding:8px 10px;
  border:1px solid #dfe5ec;
  border-radius:8px;
  outline:0;
  color:#263750;
  background:#fff;
  font-size:10px;
}
.fd-team-field textarea{
  min-height:76px;
  resize:vertical;
}
.fd-team-field input:focus,
.fd-team-field select:focus,
.fd-team-field textarea:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}
.fd-team-section-title{
  grid-column:1/-1;
  margin-top:3px;
  padding:8px 0 2px;
  border-bottom:1px solid #eef2f5;
  color:#31425b;
  font-size:9px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.04em;
}
.fd-team-members-box{
  grid-column:1/-1;
  border:1px solid var(--fd-border);
  border-radius:9px;
  overflow:hidden;
}
.fd-team-members-head{
  padding:10px 11px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  background:#f9fbfc;
  border-bottom:1px solid var(--fd-border);
}
.fd-team-members-head strong{
  color:#31425b;
  font-size:10px;
}
.fd-team-members-list{
  max-height:260px;
  overflow:auto;
}
.fd-team-member-row{
  padding:9px 11px;
  display:grid;
  grid-template-columns:24px minmax(0,1fr) 170px 110px;
  align-items:center;
  gap:8px;
  border-bottom:1px solid #f0f3f6;
}
.fd-team-member-row:last-child{border-bottom:0}
.fd-team-member-row input[type="checkbox"]{
  width:14px;
  height:14px;
  accent-color:var(--fd-green);
}
.fd-team-member-copy strong,
.fd-team-member-copy small{display:block}
.fd-team-member-copy strong{
  color:#34465f;
  font-size:9.5px;
}
.fd-team-member-copy small{
  margin-top:2px;
  color:#8a96a7;
  font-size:8px;
}
.fd-team-member-row input[type="text"]{
  width:100%;
  height:34px;
  padding:6px 8px;
  border:1px solid #dfe5ec;
  border-radius:7px;
  font-size:9px;
}
.fd-team-primary-label{
  display:flex;
  align-items:center;
  gap:6px;
  color:#607086;
  font-size:8.5px;
}
.fd-team-modal-footer{
  padding:12px 15px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
  border-top:1px solid var(--fd-border);
  background:#fbfcfd;
}
.fd-team-confirm{width:min(440px,100%)}
.fd-team-confirm .fd-team-modal-body{
  padding:18px 16px;
  color:#56667c;
  font-size:10px;
  line-height:1.6;
}

.fd-team-toast{
  width:min(290px,calc(100vw - 24px));
  position:fixed;
  top:82px;
  right:16px;
  z-index:25000;
  padding:8px 9px;
  display:flex;
  align-items:center;
  gap:7px;
  border-radius:7px;
  color:#fff;
  box-shadow:0 10px 26px rgba(0,17,49,.18);
  opacity:0;
  transform:translateY(-8px);
  pointer-events:none;
  transition:.18s ease;
}
.fd-team-toast.show{
  opacity:1;
  transform:translateY(0);
}
.fd-team-toast.success{background:#5d971b}
.fd-team-toast.error{background:#e45b66}
.fd-team-toast.warning{background:#96a52f}
.fd-team-toast.info{background:#123d70}
.fd-team-toast-message{
  min-width:0;
  flex:1;
  font-size:8.5px;
  font-weight:600;
}
.fd-team-toast-close{
  width:19px;
  height:19px;
  padding:0;
  border:0;
  color:#fff;
  background:transparent;
  cursor:pointer;
}

@media(max-width:767.98px){
  .fd-teams-header{flex-direction:column}
  .fd-teams-actions{justify-content:flex-end}
  .fd-team-form-grid{grid-template-columns:1fr}
  .fd-team-field.full,
  .fd-team-section-title,
  .fd-team-members-box{grid-column:auto}
  .fd-team-search{width:100%}
  .fd-team-toolbar-spacer{display:none}
  .fd-team-member-row{
    grid-template-columns:24px minmax(0,1fr);
  }
  .fd-team-member-row input[type="text"],
  .fd-team-primary-label{
    grid-column:2;
  }
}
@media(max-width:575.98px){
  .fd-team-stat-card{
    min-height:102px;
    padding:15px 17px;
  }
  .fd-team-stat-icon{
    width:54px;
    height:54px;
    flex-basis:54px;
  }
  .fd-team-stat-value{font-size:29px}
  .fd-team-filter{flex:1}
  .fd-team-modal-footer{flex-direction:column-reverse}
  .fd-team-modal-footer .fd-team-button{width:100%}
  .fd-team-toast{
    top:72px;
    left:12px;
    right:12px;
    width:auto;
  }
}
\n/* Clients page additions */\na,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}\n.fd-client-type{display:inline-flex;align-items:center;padding:5px 7px;border-radius:5px;font-size:8.5px;font-weight:600}\n.fd-client-type.client,.fd-client-type.active{color:#5d971b;background:#f0f8e5}.fd-client-type.lead,.fd-client-type.new{color:#123d70;background:#edf2f7}.fd-client-type.inactive{color:#6f7b90;background:#eef2f6}.fd-client-type.archived{color:#8a5e10;background:#fff7df}\n.fd-client-checks{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px}.fd-client-check{min-height:38px;padding:7px 9px;display:inline-flex;align-items:center;gap:7px;border:1px solid #e3e8ed;border-radius:7px;color:#5c6d82;background:#fff;font-size:8.5px}.fd-client-check input{width:14px;height:14px;accent-color:var(--fd-green)}\n
/* Clients table font + alignment correction */
.fd-client-table{
  table-layout:auto;
}

.fd-client-table th{
  padding:12px 14px !important;
  color:#5f6f86 !important;
  font-size:9px !important;
  line-height:1.2 !important;
  font-weight:700 !important;
  letter-spacing:.01em !important;
  text-align:left !important;
  vertical-align:middle !important;
  white-space:nowrap !important;
}

.fd-client-table td{
  padding:12px 14px !important;
  color:#33445f !important;
  font-size:9.5px !important;
  line-height:1.45 !important;
  font-weight:400 !important;
  text-align:left !important;
  vertical-align:middle !important;
}

.fd-client-table th:first-child,
.fd-client-table td:first-child{
  width:58px;
  text-align:center !important;
}

.fd-client-person{
  min-width:185px;
  align-items:center !important;
}

.fd-client-person strong{
  color:#17233b !important;
  font-size:10.5px !important;
  line-height:1.35 !important;
  font-weight:700 !important;
}

.fd-client-person small{
  margin-top:3px !important;
  color:#8793a5 !important;
  font-size:8.5px !important;
  line-height:1.3 !important;
  font-weight:400 !important;
}

.fd-client-table td small{
  color:#66758a !important;
  font-size:8.5px !important;
  line-height:1.35 !important;
}

.fd-client-badge{
  min-height:22px;
  padding:4px 7px !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  border-radius:5px !important;
  font-size:8.5px !important;
  line-height:1 !important;
  font-weight:700 !important;
  text-transform:capitalize !important;
  white-space:nowrap !important;
}

.fd-client-actions-cell{
  min-width:100px;
  justify-content:flex-start !important;
  align-items:center !important;
  gap:4px !important;
}

.fd-client-icon-btn{
  width:29px !important;
  height:29px !important;
  min-width:29px !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  line-height:1 !important;
}

.fd-client-icon-btn i{
  line-height:1 !important;
  font-size:12px !important;
}

.fd-client-table td:nth-child(3),
.fd-client-table td:nth-child(9){
  vertical-align:middle !important;
}

.fd-client-table td:nth-child(10){
  color:#52627a !important;
  font-size:9px !important;
}

.fd-client-table th:last-child,
.fd-client-table td:last-child{
  text-align:left !important;
}

.fd-client-table a,
.fd-client-table a:visited,
.fd-client-table a:hover,
.fd-client-table a:focus,
.fd-client-table a:active{
  color:inherit;
  text-decoration:none !important;
}

.fd-client-table a.fd-client-badge,
.fd-client-table a.fd-client-badge:visited{
  color:#123d70 !important;
}

@media(max-width:767.98px){
  .fd-client-table th,
  .fd-client-table td{
    padding:10px 11px !important;
  }
}

/* Client Locations page */
.fd-loc-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}
.fd-loc-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  font-weight:700;
}
.fd-loc-sub{
  margin:0;
  max-width:800px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}
.fd-loc-actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.fd-loc-client{
  margin-bottom:14px;
  padding:12px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border:1px solid var(--fd-border);
  border-radius:10px;
  background:#fff;
}
.fd-loc-client-icon{
  width:38px;
  height:38px;
  display:grid;
  place-items:center;
  flex:0 0 38px;
  border-radius:10px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}
.fd-loc-client strong,
.fd-loc-client small{
  display:block;
}
.fd-loc-client strong{
  color:var(--fd-text);
  font-size:11px;
}
.fd-loc-client small{
  margin-top:3px;
  color:var(--fd-muted);
  font-size:8.5px;
}
.fd-loc-address{
  min-width:260px;
  white-space:normal !important;
  line-height:1.4;
}
.fd-loc-modal{
  width:min(900px,100%);
}
.fd-loc-map-link{
  color:#123d70 !important;
  font-weight:700;
  text-decoration:none !important;
}
.fd-loc-map-link:hover{
  color:var(--fd-green-dark) !important;
}
.fd-loc-primary{
  color:#5d971b;
  background:#f0f8e5;
}
.fd-loc-type{
  color:#123d70;
  background:#edf2f7;
}
.fd-loc-table .fd-team-actions-cell{
  justify-content:flex-start;
}
.fd-loc-table-wrap{
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}
.fd-loc-table-wrap::-webkit-scrollbar{height:3px!important}
.fd-loc-table-wrap::-webkit-scrollbar-track{background:transparent!important}
.fd-loc-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  border-radius:999px!important;
  background:#9aa0a6!important;
}
.fd-loc-table-wrap::-webkit-scrollbar-button{
  width:0!important;
  height:0!important;
  display:none!important;
}
@media(max-width:767.98px){
  .fd-loc-head{
    flex-direction:column;
  }
  .fd-loc-actions{
    width:100%;
  }
}

/* ==========================================================
   Service Requests - tenant CRM intake
   ========================================================== */
a,
a:link,
a:visited,
a:hover,
a:focus,
a:active{
  text-decoration:none!important;
}

.fd-rq-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}

.fd-rq-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  line-height:1.2;
  font-weight:700;
}

.fd-rq-sub{
  margin:0;
  max-width:860px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}

.fd-rq-actions{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.fd-rq-btn{
  min-height:39px;
  padding:0 13px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  border:1px solid var(--fd-border);
  border-radius:8px;
  color:#43546c;
  background:#fff;
  box-shadow:0 4px 12px rgba(31,43,88,.04);
  font-size:10px;
  font-weight:700;
  cursor:pointer;
}

.fd-rq-btn:hover{
  border-color:#cfe3ae;
  color:var(--fd-green-dark);
  background:#f9fcf4;
}

.fd-rq-btn.primary{
  border-color:var(--fd-green);
  color:#fff;
  background:linear-gradient(90deg,#7fc92d,#68aa1d);
  box-shadow:0 7px 16px rgba(104,170,29,.18);
}

.fd-rq-btn.primary:hover{
  color:#fff;
  background:linear-gradient(90deg,#74b824,#5d971b);
}

.fd-rq-btn.danger{
  border-color:#ffd5d9;
  color:#b9444d;
  background:#fff;
}

.fd-rq-btn:disabled{
  opacity:.58;
  cursor:not-allowed;
}

.fd-rq-loader{
  width:13px;
  height:13px;
  display:none;
  border:2px dotted currentColor;
  border-radius:50%;
  animation:fdRqSpin .75s linear infinite;
}

.fd-rq-btn.loading .fd-rq-loader{display:inline-block}

@keyframes fdRqSpin{
  to{transform:rotate(360deg)}
}

.fd-rq-summary{margin-bottom:16px}

.fd-rq-stat{
  min-height:112px;
  padding:18px 20px;
  border:1px solid #dfe6ef;
  border-radius:12px;
  background:#fff;
  box-shadow:0 3px 12px rgba(24,45,76,.035);
}

.fd-rq-stat-row{
  min-height:72px;
  display:flex;
  align-items:center;
  gap:18px;
}

.fd-rq-stat-icon{
  width:58px;
  height:58px;
  flex:0 0 58px;
  display:grid;
  place-items:center;
  border-radius:16px;
  color:#fff;
  background:#123f73;
  font-size:25px;
}

.fd-rq-stat-label{
  display:block;
  margin-bottom:8px;
  color:#506784;
  font-size:13px;
}

.fd-rq-stat-value{
  display:block;
  color:#020b16;
  font-size:31px;
  line-height:1;
  font-weight:700;
}

.fd-rq-card{overflow:hidden}

.fd-rq-toolbar{
  padding:13px 14px;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.fd-rq-search{
  width:280px;
  position:relative;
}

.fd-rq-search i{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  color:#8a96a7;
  font-size:13px;
}

.fd-rq-search input,
.fd-rq-filter{
  height:39px;
  border:1px solid #dde4ec;
  border-radius:8px;
  outline:0;
  color:#33445f;
  background:#fff;
  font-size:10px;
}

.fd-rq-search input{
  width:100%;
  padding:8px 11px 8px 34px;
}

.fd-rq-filter{
  min-width:140px;
  padding:8px 10px;
}

.fd-rq-search input:focus,
.fd-rq-filter:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}

.fd-rq-spacer{margin-left:auto}

.fd-rq-table-wrap{
  width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}

.fd-rq-table-wrap::-webkit-scrollbar{height:3px!important}
.fd-rq-table-wrap::-webkit-scrollbar-track{background:transparent!important}
.fd-rq-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  border-radius:999px!important;
  background:#9aa0a6!important;
}
.fd-rq-table-wrap::-webkit-scrollbar-button{
  width:0!important;
  height:0!important;
  display:none!important;
}

.fd-rq-table{
  width:100%;
  min-width:1320px;
  margin:0;
  border-collapse:collapse;
  white-space:nowrap;
}

.fd-rq-table th{
  padding:11px 12px;
  border-bottom:1px solid var(--fd-border);
  color:#65738a;
  background:#f8fafc;
  font-size:9px;
  line-height:1.2;
  font-weight:700;
  text-align:left;
  text-transform:uppercase;
}

.fd-rq-table td{
  padding:12px;
  border-bottom:1px solid #f1f3f7;
  color:#33445f;
  font-size:9.5px;
  line-height:1.45;
  vertical-align:middle;
}

.fd-rq-table tbody tr:hover{background:#fbfcfa}

.fd-rq-table th:first-child,
.fd-rq-table td:first-child{
  width:55px;
  text-align:center;
}

.fd-rq-request strong,
.fd-rq-request small,
.fd-rq-client strong,
.fd-rq-client small{
  display:block;
}

.fd-rq-request strong{
  color:#123d70;
  font-size:10.5px;
  font-weight:700;
}

.fd-rq-request small,
.fd-rq-client small{
  margin-top:3px;
  color:#8995a6;
  font-size:8.3px;
}

.fd-rq-client strong{
  color:#17233b;
  font-size:10px;
  font-weight:700;
}

.fd-rq-badge{
  min-height:22px;
  padding:4px 7px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:5px;
  font-size:8.5px;
  line-height:1;
  font-weight:700;
  text-transform:capitalize;
}

.fd-rq-badge.new,
.fd-rq-badge.normal{
  color:#123d70;
  background:#edf2f7;
}

.fd-rq-badge.contacting,
.fd-rq-badge.information_required{
  color:#8a5e10;
  background:#fff7df;
}

.fd-rq-badge.assessment_required,
.fd-rq-badge.quote_required,
.fd-rq-badge.job_required,
.fd-rq-badge.high{
  color:#b55b00;
  background:#fff1e4;
}

.fd-rq-badge.converted,
.fd-rq-badge.closed,
.fd-rq-badge.low{
  color:#5d971b;
  background:#f0f8e5;
}

.fd-rq-badge.cancelled{
  color:#8b4450;
  background:#fff0f1;
}

.fd-rq-badge.urgent{
  color:#bd2f3a;
  background:#fff0f1;
}

.fd-rq-actions-cell{
  min-width:120px;
  display:flex;
  align-items:center;
  gap:4px;
}

.fd-rq-icon{
  width:29px;
  height:29px;
  min-width:29px;
  padding:0;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:0;
  border-radius:6px;
  color:#66748b;
  background:transparent;
  cursor:pointer;
  font-size:12px;
  line-height:1;
}

.fd-rq-icon:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}

.fd-rq-icon.danger:hover{
  color:#b9444d;
  background:#fff0f1;
}

.fd-rq-empty{
  padding:28px 18px!important;
  text-align:center;
  color:#9aa4b3!important;
  font-size:10px!important;
}

.fd-rq-pagination{
  min-height:49px;
  padding:10px 14px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  border-top:1px solid var(--fd-border);
  color:#768397;
  background:#fff;
  font-size:9px;
}

.fd-rq-pagination-actions{
  display:flex;
  gap:5px;
}

/* Modal */
.fd-rq-modal-bg{
  position:fixed;
  inset:0;
  z-index:15000;
  display:none;
  align-items:center;
  justify-content:center;
  padding:18px;
  background:rgba(0,17,49,.46);
  backdrop-filter:blur(3px);
}

.fd-rq-modal-bg.show{display:flex}

.fd-rq-modal{
  width:min(940px,100%);
  max-height:calc(100vh - 34px);
  overflow:auto;
  border:1px solid #dfe5ec;
  border-radius:12px;
  background:#fff;
  box-shadow:0 24px 65px rgba(0,17,49,.24);
}

.fd-rq-modal.small{
  width:min(610px,100%);
}

.fd-rq-modal-header{
  min-height:58px;
  padding:11px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.fd-rq-modal-icon{
  width:34px;
  height:34px;
  display:grid;
  place-items:center;
  border-radius:9px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:15px;
}

.fd-rq-modal-heading{
  min-width:0;
  flex:1;
}

.fd-rq-modal-heading h3{
  margin:0;
  color:var(--fd-text);
  font-size:12px;
  font-weight:700;
}

.fd-rq-modal-heading p{
  margin:3px 0 0;
  color:var(--fd-muted);
  font-size:8.5px;
}

.fd-rq-modal-close{
  width:30px;
  height:30px;
  display:grid;
  place-items:center;
  border:0;
  border-radius:7px;
  color:#8490a0;
  background:transparent;
  cursor:pointer;
}

.fd-rq-modal-body{padding:15px}

.fd-rq-form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:13px;
}

.fd-rq-field.full{grid-column:1/-1}

.fd-rq-field label{
  margin-bottom:6px;
  display:block;
  color:#42536c;
  font-size:9px;
  font-weight:700;
}

.fd-rq-field input,
.fd-rq-field select,
.fd-rq-field textarea{
  width:100%;
  min-height:40px;
  padding:8px 10px;
  border:1px solid #dfe5ec;
  border-radius:8px;
  outline:0;
  color:#263750;
  background:#fff;
  font-size:10px;
}

.fd-rq-field textarea{
  min-height:88px;
  resize:vertical;
}

.fd-rq-field input:focus,
.fd-rq-field select:focus,
.fd-rq-field textarea:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}

.fd-rq-section{
  grid-column:1/-1;
  margin-top:4px;
  padding:8px 0 3px;
  border-bottom:1px solid #eef2f5;
  color:#31425b;
  font-size:9px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.04em;
}

.fd-rq-modal-footer{
  padding:12px 15px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
  border-top:1px solid var(--fd-border);
  background:#fbfcfd;
}

/* History */
.fd-rq-history{
  display:grid;
  gap:8px;
}

.fd-rq-history-item{
  padding:10px 11px;
  border:1px solid #e4e9ef;
  border-radius:8px;
  background:#fbfcfd;
}

.fd-rq-history-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}

.fd-rq-history-top strong{
  color:#263750;
  font-size:9.5px;
}

.fd-rq-history-item small{
  display:block;
  margin-top:4px;
  color:#8793a5;
  font-size:8px;
}

.fd-rq-history-item p{
  margin:7px 0 0;
  color:#56667c;
  font-size:8.5px;
  line-height:1.5;
}

/* Toast */
.fd-rq-toast{
  width:min(290px,calc(100vw - 24px));
  position:fixed;
  top:82px;
  right:16px;
  z-index:25000;
  padding:8px 9px;
  display:flex;
  align-items:center;
  gap:7px;
  border-radius:7px;
  color:#fff;
  box-shadow:0 10px 26px rgba(0,17,49,.18);
  opacity:0;
  transform:translateY(-8px);
  pointer-events:none;
  transition:.18s ease;
}

.fd-rq-toast.show{
  opacity:1;
  transform:translateY(0);
}

.fd-rq-toast.success{background:#5d971b}
.fd-rq-toast.error{background:#e45b66}
.fd-rq-toast.warning{background:#96a52f}
.fd-rq-toast.info{background:#123d70}

.fd-rq-toast-msg{
  min-width:0;
  flex:1;
  font-size:8.5px;
  font-weight:600;
}

.fd-rq-toast-close{
  width:19px;
  height:19px;
  padding:0;
  border:0;
  color:#fff;
  background:transparent;
  cursor:pointer;
}

@media(max-width:767.98px){
  .fd-rq-head{flex-direction:column}
  .fd-rq-actions{width:100%}
  .fd-rq-form-grid{grid-template-columns:1fr}
  .fd-rq-field.full,
  .fd-rq-section{grid-column:auto}
  .fd-rq-search{width:100%}
  .fd-rq-spacer{display:none}
}

@media(max-width:575.98px){
  .fd-rq-stat{
    min-height:102px;
    padding:15px 17px;
  }
  .fd-rq-stat-icon{
    width:54px;
    height:54px;
    flex-basis:54px;
  }
  .fd-rq-stat-value{font-size:29px}
  .fd-rq-filter{flex:1}
  .fd-rq-modal-footer{flex-direction:column-reverse}
  .fd-rq-modal-footer .fd-rq-btn{width:100%}
  .fd-rq-toast{
    top:72px;
    left:12px;
    right:12px;
    width:auto;
  }
}

/* Separate Edit Request action */
.fd-rq-icon[href]{text-decoration:none!important}


/* ==========================================================
   Service Requests v2.1 - same compact stats cards as Quotations
   ========================================================== */
.fd-rq-summary-v2{margin-bottom:17px}
.fd-rq-summary-v2 > div{display:flex}
.fd-rq-metric-card{
  width:100%;height:100%;min-height:134px;padding:15px 18px;position:relative;
  overflow:visible;border:1px solid #dfe6ef;border-radius:12px;background:#fff;
  box-shadow:0 3px 12px rgba(24,45,76,.035)
}
.fd-rq-metric-title{
  margin:0;color:#15233a;font-size:15px;font-weight:700;line-height:1.2
}
.fd-rq-metric-title-row{display:flex;align-items:center;gap:7px}
.fd-rq-metric-info{color:#8b9bb0;font-size:12px;line-height:1}
.fd-rq-card-arrow{
  position:absolute;top:14px;right:15px;color:#7f90a8;font-size:15px;line-height:1
}
.fd-rq-metric-sub{
  margin:4px 24px 0 0;max-width:315px;color:#738197;font-size:9.5px;line-height:1.35
}
.fd-rq-overview-list{margin-top:8px;display:flex;flex-direction:column;gap:4px}
.fd-rq-overview-item{
  min-height:14px;display:grid;grid-template-columns:8px minmax(0,1fr) auto;
  align-items:center;gap:6px;color:#4f6078;font-size:9px;line-height:1.15;
  text-decoration:none!important
}
.fd-rq-overview-item:hover{color:var(--fd-green-dark)}
.fd-rq-overview-dot{width:6px;height:6px;border-radius:50%;background:#aab5c5}
.fd-rq-overview-dot.approval{background:#d7aa25}
.fd-rq-overview-dot.new{background:#94a8c1}
.fd-rq-overview-dot.assessment{background:#5d9f2f}
.fd-rq-overview-dot.overdue{background:#e45b66}
.fd-rq-overview-dot.unscheduled{background:#7e91a8}
.fd-rq-overview-count{color:#253750;font-size:9px;font-weight:700;text-align:right}
.fd-rq-metric-period{margin-top:3px;color:#7c8a9e;font-size:9px;line-height:1.2}
.fd-rq-metric-value-row{margin-top:18px;display:flex;align-items:center;gap:9px}
.fd-rq-metric-value{
  color:#071426;font-size:30px;line-height:1;font-weight:700;letter-spacing:-.5px
}
.fd-rq-metric-change{
  min-height:22px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;
  border-radius:999px;font-size:9px;font-weight:700;white-space:nowrap
}
.fd-rq-metric-change.up{color:#5d971b;background:#edf7e4}
.fd-rq-metric-change.down{color:#b9444d;background:#fff0f1}
.fd-rq-metric-change.flat{color:#718096;background:#eef2f6}
.fd-rq-trend-wrap{position:relative;display:inline-flex}
.fd-rq-trend-popup{
  min-width:190px;padding:11px 12px;position:absolute;right:-8px;bottom:calc(100% + 12px);z-index:30;
  visibility:hidden;opacity:0;transform:translateY(5px);pointer-events:none;
  border:1px solid #dfe6ef;border-radius:10px;background:#fff;
  box-shadow:0 12px 28px rgba(20,42,75,.14);transition:.15s ease
}
.fd-rq-trend-popup:after{
  width:10px;height:10px;position:absolute;right:22px;bottom:-6px;content:"";
  border-right:1px solid #dfe6ef;border-bottom:1px solid #dfe6ef;background:#fff;
  transform:rotate(45deg)
}
.fd-rq-trend-wrap:hover .fd-rq-trend-popup,
.fd-rq-trend-wrap:focus-within .fd-rq-trend-popup{visibility:visible;opacity:1;transform:translateY(0)}
.fd-rq-trend-popup-title{margin-bottom:7px;color:#62738a;font-size:9px;font-weight:700}
.fd-rq-trend-popup-row{
  display:flex;align-items:center;justify-content:space-between;gap:15px;padding:3px 0;
  color:#607086;font-size:9px
}
.fd-rq-trend-popup-row strong{color:#243650;font-size:9px}
@media(max-width:1199.98px){.fd-rq-metric-card{min-height:136px}}
@media(max-width:767.98px){.fd-rq-metric-card{min-height:132px}.fd-rq-summary-v2{row-gap:12px}}
@media(max-width:575.98px){.fd-rq-metric-card{min-height:128px;padding:14px 16px}.fd-rq-metric-value{font-size:28px}}

/* Version 2.2.0 - entire request row opens Request View */
.fd-rq-table tbody tr.fd-rq-clickable-row{cursor:pointer;transition:background .15s ease}
.fd-rq-table tbody tr.fd-rq-clickable-row:hover{background:#fbfcfa}
.fd-rq-table tbody tr.fd-rq-clickable-row:focus{outline:2px solid rgba(116,184,36,.30);outline-offset:-2px;background:#fbfcfa}


/* ==========================================================
   Service Requests v3.0.0
   Jobber request-list information architecture + FieldPlx
   Add Invoice typography / controls / tenant shell.
   ========================================================== */
.fd-rq-head.fd-rq-head-v3{
  align-items:center;
  margin-bottom:26px;
}
.fd-rq-head-v3 .fd-rq-title{
  margin:0;
  font-size:24px;
  line-height:1.2;
  font-weight:700;
  letter-spacing:-.2px;
}
.fd-rq-head-v3 .fd-rq-actions{gap:9px}
.fd-rq-head-v3 .fd-rq-btn{
  min-height:44px;
  padding:0 16px;
  border-radius:8px;
  box-shadow:none;
  font-size:14px;
  font-weight:600;
  white-space:nowrap;
}
.fd-rq-head-v3 .fd-rq-btn.primary{
  border-color:var(--fd-green);
  background:var(--fd-green-dark);
  box-shadow:none;
}
.fd-rq-head-v3 .fd-rq-btn.primary:hover{background:#518618}

.fd-rq-more-wrap{position:relative}
.fd-rq-more-menu{
  width:215px;
  padding:7px;
  position:absolute;
  top:calc(100% + 7px);
  right:0;
  z-index:110;
  display:none;
  border:1px solid var(--fd-border);
  border-radius:9px;
  background:#fff;
  box-shadow:0 12px 28px rgba(0,17,49,.14);
}
.fd-rq-more-menu.show{display:block}
.fd-rq-more-item{
  width:100%;
  min-height:42px;
  padding:8px 10px;
  display:flex;
  align-items:center;
  gap:10px;
  border:0;
  border-radius:7px;
  color:#33465b!important;
  background:transparent;
  font-family:Arial,Helvetica,sans-serif;
  font-size:14px;
  font-weight:600;
  text-align:left;
  text-decoration:none!important;
  cursor:pointer;
}
.fd-rq-more-item i{width:20px;font-size:17px;text-align:center}
.fd-rq-more-item:hover{color:var(--fd-green-dark)!important;background:var(--fd-green-soft)}

.fd-rq-summary-v3{
  display:grid;
  grid-template-columns:minmax(0,1.05fr) minmax(0,1.05fr) minmax(0,1.05fr) minmax(310px,1.7fr);
  gap:12px;
  margin-bottom:31px;
}
.fd-rq-summary-v3 .fd-rq-metric-card{
  min-height:166px;
  padding:18px 19px;
  border:1px solid #dfe5ec;
  border-radius:9px;
  box-shadow:none;
}
.fd-rq-summary-v3 .fd-rq-metric-title{
  color:var(--fd-text);
  font-size:18px;
  line-height:1.25;
  font-weight:700;
}
.fd-rq-summary-v3 .fd-rq-metric-info{font-size:14px}
.fd-rq-summary-v3 .fd-rq-card-arrow{top:18px;right:18px;font-size:14px}
.fd-rq-summary-v3 .fd-rq-overview-list{margin-top:10px;gap:5px}
.fd-rq-summary-v3 .fd-rq-overview-item{
  min-height:17px;
  grid-template-columns:8px minmax(0,1fr) auto;
  gap:7px;
  color:#3f5369;
  font-size:13px;
  line-height:1.2;
}
.fd-rq-summary-v3 .fd-rq-overview-dot{width:7px;height:7px}
.fd-rq-summary-v3 .fd-rq-overview-count{font-size:13px;font-weight:500}
.fd-rq-summary-v3 .fd-rq-metric-period{margin-top:3px;color:#65778a;font-size:13px}
.fd-rq-summary-v3 .fd-rq-metric-value-row{margin-top:42px;gap:10px}
.fd-rq-summary-v3 .fd-rq-metric-value{font-size:38px;letter-spacing:-.7px}
.fd-rq-summary-v3 .fd-rq-metric-change{
  min-height:27px;
  padding:0 10px;
  font-size:12px;
  font-weight:600;
}
.fd-rq-summary-v3 .fd-rq-trend-popup-title,
.fd-rq-summary-v3 .fd-rq-trend-popup-row,
.fd-rq-summary-v3 .fd-rq-trend-popup-row strong{font-size:12px}
.fd-rq-efficiency-copy{
  max-width:470px;
  margin:4px 0 0;
  color:#53667c;
  font-size:13px;
  line-height:1.35;
}
.fd-rq-ai-link{
  margin-top:43px;
  padding:0;
  display:inline-flex;
  align-items:center;
  gap:6px;
  border:0;
  color:#1a8090;
  background:transparent;
  font-family:Arial,Helvetica,sans-serif;
  font-size:14px;
  font-weight:700;
  text-decoration:underline;
  cursor:pointer;
}

.fd-rq-list-section{margin-top:0}
.fd-rq-list-heading{
  display:flex;
  align-items:baseline;
  gap:9px;
  margin:0 0 22px;
}
.fd-rq-list-heading h2{margin:0;color:var(--fd-text);font-size:20px;line-height:1.25;font-weight:700}
.fd-rq-list-heading span{color:#5f7185;font-size:13px}
.fd-rq-list-tools{
  margin-bottom:17px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:15px;
  flex-wrap:wrap;
}
.fd-rq-left-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fd-rq-filter-pill{
  min-height:44px;
  padding:0 12px;
  display:flex;
  align-items:center;
  gap:8px;
  border:0;
  border-radius:999px;
  color:#263b50;
  background:#ecebe7;
  font-size:14px;
  line-height:1;
}
.fd-rq-filter-pill > i{font-size:17px}
.fd-rq-filter-pill .fd-rq-pill-label{font-weight:600}
.fd-rq-filter-pill .fd-rq-pill-sep{color:#8492a0}
.fd-rq-filter-pill select{
  max-width:150px;
  padding:0 21px 0 0;
  border:0;
  outline:0;
  color:#263b50;
  background:transparent;
  font-family:Arial,Helvetica,sans-serif;
  font-size:14px;
  font-weight:400;
  cursor:pointer;
}
.fd-rq-filter-pill select:focus{box-shadow:none}
.fd-rq-search.fd-rq-search-v3{width:250px}
.fd-rq-search-v3 i{left:15px;color:#577082;font-size:18px}
.fd-rq-search-v3 input{
  width:100%;
  height:48px;
  padding:10px 13px 10px 46px;
  border:1px solid #dfe5ec;
  border-radius:8px;
  color:#263b50;
  background:#fff;
  font-family:Arial,Helvetica,sans-serif;
  font-size:14px;
}
.fd-rq-search-v3 input:focus{border-color:#a9cf75;box-shadow:0 0 0 3px rgba(116,184,36,.11)}

.fd-rq-table-wrap.fd-rq-table-wrap-v3{border:0;overflow-x:auto}
.fd-rq-table.fd-rq-table-v3{
  min-width:900px;
  table-layout:fixed;
  white-space:normal;
}
.fd-rq-table-v3 th{
  padding:11px 10px 12px;
  border-bottom:1px solid #d8e0e7;
  color:#334b5f;
  background:#fff;
  font-size:13px;
  line-height:1.2;
  font-weight:500;
  text-transform:none;
}
.fd-rq-table-v3 td{
  padding:12px 10px;
  border-bottom:1px solid #dfe5ec;
  color:#263b50;
  font-size:14px;
  line-height:1.4;
  vertical-align:middle;
}
.fd-rq-table-v3 th:nth-child(1),.fd-rq-table-v3 td:nth-child(1){width:17%;text-align:left}
.fd-rq-table-v3 th:nth-child(2),.fd-rq-table-v3 td:nth-child(2){width:17%}
.fd-rq-table-v3 th:nth-child(3),.fd-rq-table-v3 td:nth-child(3){width:19%}
.fd-rq-table-v3 th:nth-child(4),.fd-rq-table-v3 td:nth-child(4){width:20%}
.fd-rq-table-v3 th:nth-child(5),.fd-rq-table-v3 td:nth-child(5){width:15%}
.fd-rq-table-v3 th:nth-child(6),.fd-rq-table-v3 td:nth-child(6){width:12%;text-align:right}
.fd-rq-table-v3 tbody tr.fd-rq-clickable-row{cursor:pointer}
.fd-rq-table-v3 tbody tr.fd-rq-clickable-row:hover{background:#fbfcfa}
.fd-rq-table-v3 .fd-rq-client-name{color:#203548;font-size:14px;font-weight:700}
.fd-rq-table-v3 .fd-rq-cell-title{color:#263b50;font-size:14px}
.fd-rq-table-v3 .fd-rq-property{color:#334b5f;line-height:1.35}
.fd-rq-table-v3 .fd-rq-contact strong,
.fd-rq-table-v3 .fd-rq-contact small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fd-rq-table-v3 .fd-rq-contact strong{color:#334b5f;font-size:14px;font-weight:400}
.fd-rq-table-v3 .fd-rq-contact small{margin-top:2px;color:#334b5f;font-size:13px}
.fd-rq-table-v3 .fd-rq-requested{color:#334b5f;white-space:nowrap}
.fd-rq-table-v3 .fd-rq-badge{
  min-height:27px;
  padding:5px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:500;
  text-transform:none;
}
.fd-rq-table-v3 .fd-rq-badge.new{color:#17648f;background:#e9f4ff}
.fd-rq-table-v3 .fd-rq-badge.new:before{
  width:7px;height:7px;margin-right:6px;display:inline-block;border-radius:50%;background:#38aaf0;content:"";
}
.fd-rq-table-v3 .fd-rq-empty{padding:35px 15px!important;font-size:13px!important}
.fd-rq-pagination.fd-rq-pagination-v3{
  min-height:51px;
  padding:12px 0 0;
  border-top:0;
  color:#64768a;
  font-size:12px;
}
.fd-rq-pagination-v3 .fd-rq-btn{min-width:39px;min-height:39px;font-size:13px;box-shadow:none}

@media(max-width:1199.98px){
  .fd-rq-summary-v3{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:767.98px){
  .fd-rq-head.fd-rq-head-v3{align-items:stretch}
  .fd-rq-head-v3 .fd-rq-actions{justify-content:flex-end}
  .fd-rq-summary-v3{grid-template-columns:1fr}
  .fd-rq-list-tools{align-items:stretch}
  .fd-rq-left-filters{width:100%}
  .fd-rq-search.fd-rq-search-v3{width:100%}
}
@media(max-width:575.98px){
  .fd-rq-head-v3 .fd-rq-actions{display:grid;grid-template-columns:1fr 1fr}
  .fd-rq-head-v3 .fd-rq-btn{width:100%}
  .fd-rq-more-wrap{width:100%}
  .fd-rq-more-menu{width:100%}
  .fd-rq-summary-v3 .fd-rq-metric-card{min-height:145px}
  .fd-rq-summary-v3 .fd-rq-metric-value-row{margin-top:30px}
  .fd-rq-filter-pill{flex:1;min-width:145px}
  .fd-rq-filter-pill select{min-width:0;max-width:100%;flex:1}
}


/* ==========================================================
   Service Requests v3.1.0 - Invoice reference visual alignment
   UI/template/font only. Existing hover interactions are preserved.
   ========================================================== */
body{
  background:#fff!important;
  color:#0b2b37;
  font-family:Arial,Helvetica,sans-serif!important;
  font-size:14px;
}

.fd-dashboard{
  width:100%;
  max-width:none;
  margin:0;
  padding:24px 22px 42px;
  background:#fff;
  min-height:calc(100vh - 70px);
}

.fd-rq-head.fd-rq-head-v3{
  align-items:center;
  gap:18px;
  margin-bottom:25px;
}
.fd-rq-head-v3 .fd-rq-title{
  margin:0;
  color:#0b2b37;
  font-size:31px;
  line-height:1.1;
  font-weight:700;
  letter-spacing:-.7px;
}
.fd-rq-head-v3 .fd-rq-actions{gap:9px}
.fd-rq-head-v3 .fd-rq-btn,
.fd-rq-pagination-v3 .fd-rq-btn{
  min-height:38px;
  height:38px;
  padding:0 14px;
  gap:8px;
  border:1px solid #dce4e8;
  border-radius:7px;
  color:#31505d;
  background:#fff;
  box-shadow:none;
  font:700 14px Arial,Helvetica,sans-serif;
  white-space:nowrap;
}
.fd-rq-head-v3 .fd-rq-btn.primary{
  border-color:#2f8d25;
  background:#2f8d25;
  color:#fff;
  box-shadow:none;
}

.fd-rq-more-menu{
  width:182px;
  padding:7px 0;
  top:45px;
  border:1px solid #dce4e8;
  border-radius:8px;
  background:#fff;
  box-shadow:0 10px 25px rgba(0,17,49,.14);
}
.fd-rq-more-item{
  min-height:44px;
  padding:9px 14px;
  gap:10px;
  border-radius:0;
  color:#294755!important;
  background:#fff;
  font:700 13px Arial,Helvetica,sans-serif;
}
.fd-rq-more-item i{font-size:18px;color:#315967}

.fd-rq-summary-v3{
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:10px;
  margin-bottom:26px;
}
.fd-rq-summary-v3 .fd-rq-metric-card{
  min-width:0;
  min-height:141px;
  padding:15px 15px 14px;
  border:1px solid #dce4e8;
  border-radius:7px;
  background:#fff;
  box-shadow:none;
}
.fd-rq-summary-v3 .fd-rq-metric-title{
  margin:0;
  color:#123845;
  font-size:15px;
  line-height:1.25;
  font-weight:700;
}
.fd-rq-summary-v3 .fd-rq-metric-info,
.fd-rq-summary-v3 .fd-rq-card-arrow{
  color:#355866;
  font-size:12px;
}
.fd-rq-summary-v3 .fd-rq-card-arrow{top:15px;right:15px}
.fd-rq-summary-v3 .fd-rq-overview-list{
  margin-top:9px;
  gap:4px;
}
.fd-rq-summary-v3 .fd-rq-overview-item{
  min-height:auto;
  grid-template-columns:8px minmax(0,1fr) auto;
  gap:6px;
  color:#405d6a;
  font-size:12px;
  line-height:1.25;
}
.fd-rq-summary-v3 .fd-rq-overview-dot{width:7px;height:7px}
.fd-rq-summary-v3 .fd-rq-overview-count{
  color:#405d6a;
  font-size:12px;
  font-weight:400;
  text-align:right;
  white-space:nowrap;
}
.fd-rq-summary-v3 .fd-rq-metric-period{
  margin-top:2px;
  color:#607782;
  font-size:12px;
  line-height:1.3;
}
.fd-rq-summary-v3 .fd-rq-metric-value-row{
  margin-top:24px;
  gap:8px;
  flex-wrap:wrap;
}
.fd-rq-summary-v3 .fd-rq-metric-value{
  color:#0b2b37;
  font-size:34px;
  line-height:.95;
  font-weight:700;
  letter-spacing:-1px;
}
.fd-rq-summary-v3 .fd-rq-metric-change{
  min-height:25px;
  padding:3px 8px;
  border-radius:999px;
  font-size:12px;
  line-height:1;
  font-weight:700;
}
.fd-rq-summary-v3 .fd-rq-trend-popup{
  min-width:185px;
  max-width:260px;
  padding:12px 14px;
  border:1px solid #d7e0e4;
  border-radius:8px;
  background:#fff;
  box-shadow:0 7px 22px rgba(0,17,49,.16);
}
.fd-rq-summary-v3 .fd-rq-trend-popup-title{
  margin-bottom:6px;
  color:#71848e;
  font-size:12px;
  font-weight:400;
  line-height:1.25;
}
.fd-rq-summary-v3 .fd-rq-trend-popup-row{
  gap:10px;
  padding:0;
  margin-top:3px;
  color:#294b59;
  font-size:12px;
  font-weight:700;
  line-height:1.3;
}
.fd-rq-summary-v3 .fd-rq-trend-popup-row strong{
  color:#294b59;
  font-size:12px;
  font-weight:700;
}

.fd-rq-list-heading{
  align-items:baseline;
  gap:8px;
  margin:0 0 18px;
}
.fd-rq-list-heading h2{
  margin:0;
  color:#123845;
  font-size:19px;
  line-height:1.2;
  font-weight:700;
}
.fd-rq-list-heading span{
  color:#607782;
  font-size:13px;
  font-weight:400;
}
.fd-rq-list-tools{
  position:relative;
  min-height:47px;
  margin-bottom:10px;
  gap:8px;
}
.fd-rq-left-filters{gap:8px}
.fd-rq-filter-pill{
  min-height:38px;
  height:38px;
  padding:0 13px;
  gap:7px;
  border:0;
  border-radius:999px;
  color:#173846;
  background:#e9e8e4;
  font:700 13px Arial,Helvetica,sans-serif;
}
.fd-rq-filter-pill > i{font-size:17px}
.fd-rq-filter-pill .fd-rq-pill-label{font-weight:700}
.fd-rq-filter-pill .fd-rq-pill-sep{color:#6e7e85;font-weight:400}
.fd-rq-filter-pill select{
  max-width:150px;
  padding:0 20px 0 0;
  color:#173846;
  background:transparent;
  font:400 13px Arial,Helvetica,sans-serif;
}
.fd-rq-search.fd-rq-search-v3{
  width:202px;
  margin-left:auto;
}
.fd-rq-search-v3 i{
  left:14px;
  color:#587381;
  font-size:17px;
}
.fd-rq-search-v3 input{
  width:100%;
  height:45px;
  padding:0 13px 0 45px;
  border:1px solid #dce4e8;
  border-radius:7px;
  color:#173846;
  background:#fff;
  outline:0;
  font:13px Arial,Helvetica,sans-serif;
}

.fd-rq-table-wrap.fd-rq-table-wrap-v3{
  width:100%;
  overflow-x:auto;
  overflow-y:visible;
}
.fd-rq-table.fd-rq-table-v3{
  width:100%;
  min-width:900px;
  border-collapse:collapse;
  table-layout:fixed;
  white-space:normal;
}
.fd-rq-table-v3 th{
  height:40px;
  padding:0 6px;
  border-bottom:1px solid #cfd9de;
  color:#46616e;
  background:#fff;
  font-size:11.5px;
  line-height:1.2;
  font-weight:400;
  text-align:left;
  text-transform:none;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.fd-rq-table-v3 td{
  min-height:46px;
  padding:8px 6px;
  border-bottom:1px solid #dde5e9;
  color:#314f5d;
  background:#fff;
  font-size:12px;
  line-height:1.3;
  vertical-align:middle;
  overflow:hidden;
  text-overflow:ellipsis;
}
.fd-rq-table-v3 th:nth-child(1),.fd-rq-table-v3 td:nth-child(1){width:16%;text-align:left}
.fd-rq-table-v3 th:nth-child(2),.fd-rq-table-v3 td:nth-child(2){width:16%}
.fd-rq-table-v3 th:nth-child(3),.fd-rq-table-v3 td:nth-child(3){width:22%}
.fd-rq-table-v3 th:nth-child(4),.fd-rq-table-v3 td:nth-child(4){width:18%}
.fd-rq-table-v3 th:nth-child(5),.fd-rq-table-v3 td:nth-child(5){width:13%}
.fd-rq-table-v3 th:nth-child(6),.fd-rq-table-v3 td:nth-child(6){width:15%;text-align:left;white-space:nowrap;overflow:visible}
.fd-rq-table-v3 .fd-rq-client-name{
  color:#123845;
  font-size:12px;
  font-weight:700;
}
.fd-rq-table-v3 .fd-rq-cell-title,
.fd-rq-table-v3 .fd-rq-property,
.fd-rq-table-v3 .fd-rq-contact strong,
.fd-rq-table-v3 .fd-rq-requested{
  color:#314f5d;
  font-size:12px;
  font-weight:400;
}
.fd-rq-table-v3 .fd-rq-contact small{
  margin-top:2px;
  color:#6f7f89;
  font-size:11px;
}
.fd-rq-table-v3 .fd-rq-badge{
  min-height:23px;
  max-width:100%;
  padding:3px 9px;
  display:inline-flex;
  align-items:center;
  justify-content:flex-start;
  gap:6px;
  border-radius:999px;
  font-size:12px;
  line-height:1;
  font-weight:400;
  white-space:nowrap;
  vertical-align:middle;
}
.fd-rq-table-v3 .fd-rq-badge:before{
  width:7px;
  height:7px;
  margin-right:0;
  display:inline-block;
  border-radius:50%;
  background:currentColor;
  content:"";
}
.fd-rq-table-v3 .fd-rq-badge.new:before{background:currentColor}
.fd-rq-table-v3 .fd-rq-badge.new,
.fd-rq-table-v3 .fd-rq-badge.contacting,
.fd-rq-table-v3 .fd-rq-badge.information_required{
  color:#8b7410;
  background:#f8f0c8;
}
.fd-rq-table-v3 .fd-rq-badge.assessment_required,
.fd-rq-table-v3 .fd-rq-badge.quote_required,
.fd-rq-table-v3 .fd-rq-badge.job_required{
  color:#52717f;
  background:#edf1f2;
}
.fd-rq-table-v3 .fd-rq-badge.converted,
.fd-rq-table-v3 .fd-rq-badge.closed{
  color:#3b8a33;
  background:#e7f2e4;
}
.fd-rq-table-v3 .fd-rq-badge.cancelled{
  color:#bc4941;
  background:#fae8e6;
}
.fd-rq-table-v3 .fd-rq-empty{
  height:150px!important;
  padding:0 15px!important;
  color:#71858f!important;
  font-size:13px!important;
  text-align:center!important;
}
.fd-rq-pagination.fd-rq-pagination-v3{
  min-height:48px;
  padding:11px 0 0;
  border-top:0;
  color:#6d818c;
  background:#fff;
  font-size:12px;
}
.fd-rq-pagination-v3 .fd-rq-btn{
  width:32px;
  min-width:32px;
  height:32px;
  min-height:32px;
  padding:0 8px;
  border-radius:6px;
  font-size:13px;
}

.fd-rq-modal-bg{
  padding:18px;
  background:rgba(0,17,49,.33);
  backdrop-filter:none;
}
.fd-rq-modal{
  width:min(860px,calc(100vw - 30px));
  max-height:calc(100vh - 36px);
  border:1px solid #d8e0e4;
  border-radius:10px;
  background:#fff;
  box-shadow:0 22px 60px rgba(0,17,49,.23);
}
.fd-rq-modal.small{width:min(540px,calc(100vw - 30px))}
.fd-rq-modal-header{
  min-height:auto;
  padding:20px 22px 12px;
  gap:12px;
  border-bottom:0;
  background:#fff;
}
.fd-rq-modal-icon{
  width:32px;
  height:32px;
  border-radius:8px;
  color:#24751d;
  background:#f2f8ee;
  font-size:14px;
}
.fd-rq-modal-heading h3{
  margin:0;
  color:#123845;
  font-size:22px;
  font-weight:700;
}
.fd-rq-modal-heading p{
  margin:4px 0 0;
  color:#5f7380;
  font-size:11px;
  line-height:1.4;
}
.fd-rq-modal-close{
  width:34px;
  height:34px;
  border-radius:7px;
  color:#274c5b;
  font-size:20px;
}
.fd-rq-modal-body{padding:10px 22px 12px}
.fd-rq-form-grid{gap:13px}
.fd-rq-field label{
  margin-bottom:5px;
  color:#526b78;
  font-size:12px;
  font-weight:700;
}
.fd-rq-field input,
.fd-rq-field select,
.fd-rq-field textarea{
  width:100%;
  min-height:39px;
  padding:7px 10px;
  border:1px solid #dce4e8;
  border-radius:7px;
  color:#173846;
  background:#fff;
  font:14px Arial,Helvetica,sans-serif;
}
.fd-rq-field textarea{min-height:100px;line-height:1.5}
.fd-rq-section{
  padding:8px 0 3px;
  border-bottom:1px solid #eef2f5;
  color:#31425b;
  font-size:11px;
  font-weight:700;
  letter-spacing:.04em;
}
.fd-rq-modal-footer{
  padding:12px 22px 20px;
  gap:8px;
  border-top:0;
  background:#fff;
}
.fd-rq-modal-footer .fd-rq-btn{
  min-height:38px;
  height:38px;
  padding:0 14px;
  border:1px solid #dce4e8;
  border-radius:7px;
  color:#31505d;
  background:#fff;
  box-shadow:none;
  font:700 14px Arial,Helvetica,sans-serif;
}
.fd-rq-modal-footer .fd-rq-btn.primary{
  border-color:#2f8d25;
  background:#2f8d25;
  color:#fff;
}
.fd-rq-history-item{
  padding:10px 11px;
  border:1px solid #dce4e8;
  border-radius:7px;
  background:#fbfcfc;
}
.fd-rq-history-top strong{color:#294b59;font-size:12px}
.fd-rq-history-item small{color:#7c8e96;font-size:11px}
.fd-rq-history-item p{color:#405d6a;font-size:12px;line-height:1.5}
.fd-rq-toast{
  width:min(390px,calc(100vw - 36px));
  top:82px;
  right:18px;
  padding:12px 14px;
  border-radius:8px;
  font-size:14px;
  font-weight:700;
}
.fd-rq-toast-msg{font-size:14px;font-weight:700}

@media(max-width:1199.98px){
  .fd-rq-summary-v3{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:991.98px){
  .fd-dashboard{padding:20px 16px 36px}
}
@media(max-width:767.98px){
  .fd-dashboard{padding:17px 13px 32px}
  .fd-rq-head-v3 .fd-rq-title{font-size:27px}
  .fd-rq-head.fd-rq-head-v3{align-items:flex-start;flex-direction:column}
  .fd-rq-head-v3 .fd-rq-actions{width:100%}
  .fd-rq-summary-v3{grid-template-columns:1fr}
  .fd-rq-list-tools{align-items:flex-start;flex-wrap:wrap}
  .fd-rq-left-filters{width:100%}
  .fd-rq-search.fd-rq-search-v3{width:100%;order:-1;margin-left:0}
  .fd-rq-modal-header{padding:16px 16px 10px}
  .fd-rq-modal-body{padding:10px 16px 12px}
  .fd-rq-modal-footer{padding:12px 16px 16px}
}
@media(max-width:575.98px){
  .fd-rq-head-v3 .fd-rq-actions{display:grid;grid-template-columns:1fr 1fr}
  .fd-rq-head-v3 .fd-rq-btn{width:100%}
  .fd-rq-more-wrap{width:100%}
  .fd-rq-more-menu{width:100%}
  .fd-rq-filter-pill{flex:1;min-width:145px}
  .fd-rq-filter-pill select{min-width:0;max-width:100%;flex:1}
  .fd-rq-modal-footer{flex-direction:column-reverse}
  .fd-rq-modal-footer .fd-rq-btn{width:100%}
}

</style>
</head>

<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>
    <div class="fieldplx-main-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="fieldplx-main-content">
            <div class="fieldplx-content-wrapper">
                <div class="fd-dashboard">

                    <section class="fd-rq-head fd-rq-head-v3">
                        <div>
                            <h1 class="fd-rq-title">Requests</h1>
                        </div>

                        <div class="fd-rq-actions">
                            <a href="add-request.php" class="fd-rq-btn primary">
                                New Request
                            </a>

                            <div class="fd-rq-more-wrap">
                                <button type="button" class="fd-rq-btn" id="moreActionsButton" aria-expanded="false">
                                    <i class="bi bi-three-dots"></i>
                                    More Actions
                                </button>
                                <div class="fd-rq-more-menu" id="moreActionsMenu">
                                    <a href="add-request.php" class="fd-rq-more-item">
                                        <i class="bi bi-tools"></i>
                                        <span>Customize Form</span>
                                    </a>
                                    <button type="button" class="fd-rq-more-item" id="shareRequestFormButton">
                                        <i class="bi bi-code-square"></i>
                                        <span>Share or Embed</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="fd-rq-summary-v3">
                        <article class="fd-rq-metric-card">
                            <h2 class="fd-rq-metric-title">Overview</h2>
                            <div class="fd-rq-overview-list">
                                <a class="fd-rq-overview-item" href="#requestsTableCard" data-overview-status="quote_required">
                                    <span class="fd-rq-overview-dot approval"></span>
                                    <span>Needs approval</span>
                                    <span class="fd-rq-overview-count"><?= (int)$requestStats['needs_approval'] ?></span>
                                </a>
                                <a class="fd-rq-overview-item" href="#requestsTableCard" data-overview-status="new">
                                    <span class="fd-rq-overview-dot new"></span>
                                    <span>New</span>
                                    <span class="fd-rq-overview-count"><?= (int)$requestStats['new'] ?></span>
                                </a>
                                <div class="fd-rq-overview-item">
                                    <span class="fd-rq-overview-dot assessment"></span>
                                    <span>Assessment complete</span>
                                    <span class="fd-rq-overview-count"><?= (int)$requestStats['assessment_completed'] ?></span>
                                </div>
                                <div class="fd-rq-overview-item">
                                    <span class="fd-rq-overview-dot overdue"></span>
                                    <span>Overdue</span>
                                    <span class="fd-rq-overview-count"><?= (int)$requestStats['overdue'] ?></span>
                                </div>
                                <div class="fd-rq-overview-item">
                                    <span class="fd-rq-overview-dot unscheduled"></span>
                                    <span>Unscheduled</span>
                                    <span class="fd-rq-overview-count"><?= (int)$requestStats['unscheduled'] ?></span>
                                </div>
                            </div>
                        </article>

                        <article class="fd-rq-metric-card">
                            <span class="fd-rq-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                            <h2 class="fd-rq-metric-title">New requests</h2>
                            <div class="fd-rq-metric-period">Past 30 days</div>
                            <div class="fd-rq-metric-value-row">
                                <strong class="fd-rq-metric-value"><?= (int)$requestStats['new_requests_30'] ?></strong>
                                <span class="fd-rq-trend-wrap" tabindex="0">
                                    <span class="fd-rq-metric-change <?= fieldplxRequestTrendClass($requestStats['new_requests_change']) ?>">
                                        <?= fieldplxRequestTrendArrow($requestStats['new_requests_change']) ?> <?= number_format(abs((float)$requestStats['new_requests_change']), 0) ?>%
                                    </span>
                                    <span class="fd-rq-trend-popup" role="tooltip">
                                        <span class="fd-rq-trend-popup-title">New requests</span>
                                        <span class="fd-rq-trend-popup-row"><span><?= htmlspecialchars($requestStats['previous_period_label']) ?></span><strong><?= (int)$requestStats['previous_new_requests_30'] ?></strong></span>
                                        <span class="fd-rq-trend-popup-row"><span><?= htmlspecialchars($requestStats['current_period_label']) ?></span><strong><?= (int)$requestStats['new_requests_30'] ?></strong></span>
                                    </span>
                                </span>
                            </div>
                        </article>

                        <article class="fd-rq-metric-card">
                            <div class="fd-rq-metric-title-row">
                                <h2 class="fd-rq-metric-title">Conversion rate</h2>
                                <span class="fd-rq-metric-info" title="Quotes or jobs created from requests in the past 30 days"><i class="bi bi-info-circle"></i></span>
                            </div>
                            <div class="fd-rq-metric-period">Past 30 days</div>
                            <div class="fd-rq-metric-value-row">
                                <strong class="fd-rq-metric-value"><?= number_format((float)$requestStats['conversion_rate'], 0) ?>%</strong>
                                <span class="fd-rq-trend-wrap" tabindex="0">
                                    <span class="fd-rq-metric-change <?= fieldplxRequestTrendClass($requestStats['conversion_change']) ?>">
                                        <?= fieldplxRequestTrendArrow($requestStats['conversion_change']) ?> <?= number_format(abs((float)$requestStats['conversion_change']), 0) ?>%
                                    </span>
                                    <span class="fd-rq-trend-popup" role="tooltip">
                                        <span class="fd-rq-trend-popup-title">Conversion rate</span>
                                        <span class="fd-rq-trend-popup-row"><span><?= htmlspecialchars($requestStats['previous_period_label']) ?></span><strong><?= number_format((float)$requestStats['previous_conversion_rate'], 0) ?>%</strong></span>
                                        <span class="fd-rq-trend-popup-row"><span><?= htmlspecialchars($requestStats['current_period_label']) ?></span><strong><?= number_format((float)$requestStats['conversion_rate'], 0) ?>%</strong></span>
                                    </span>
                                </span>
                            </div>
                        </article>

                        
                    </section>

                    <section class="fd-rq-list-section" id="requestsTableCard">
                        <div class="fd-rq-list-heading">
                            <h2>All requests</h2>
                            <span id="resultCount">(0 results)</span>
                        </div>

                        <div class="fd-rq-list-tools">
                            <div class="fd-rq-left-filters">
                                <label class="fd-rq-filter-pill" for="statusFilter">
                                    <span class="fd-rq-pill-label">Status</span>
                                    <span class="fd-rq-pill-sep">|</span>
                                    <select id="statusFilter" aria-label="Filter requests by status">
                                        <option value="">All</option>
                                        <option value="new">New</option>
                                        <option value="contacting">Contacting</option>
                                        <option value="information_required">Information Required</option>
                                        <option value="assessment_required">Assessment Required</option>
                                        <option value="quote_required">Quote Required</option>
                                        <option value="job_required">Job Required</option>
                                        <option value="converted">Converted</option>
                                        <option value="closed">Closed</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </label>

                                <label class="fd-rq-filter-pill" for="dateFilter">
                                    <i class="bi bi-calendar3"></i>
                                    <span class="fd-rq-pill-label">Date</span>
                                    <span class="fd-rq-pill-sep">|</span>
                                    <select id="dateFilter" aria-label="Filter requests by requested date">
                                        <option value="">All</option>
                                        <option value="today">Today</option>
                                        <option value="last_7">Last 7 days</option>
                                        <option value="last_30">Last 30 days</option>
                                        <option value="this_month">This month</option>
                                    </select>
                                </label>
                            </div>

                            <div class="fd-rq-search fd-rq-search-v3">
                                <i class="bi bi-search"></i>
                                <input type="search" id="requestSearch" placeholder="Search requests..." autocomplete="off">
                            </div>
                        </div>

                        <div class="fd-rq-table-wrap fd-rq-table-wrap-v3">
                            <table class="fd-rq-table fd-rq-table-v3">
                                <thead>
                                    <tr>
                                        <th>Client <i class="bi bi-chevron-expand"></i></th>
                                        <th>Title <i class="bi bi-chevron-expand"></i></th>
                                        <th>Property</th>
                                        <th>Contact</th>
                                        <th>Requested <i class="bi bi-chevron-expand"></i></th>
                                        <th>Status <i class="bi bi-chevron-expand"></i></th>
                                    </tr>
                                </thead>
                                <tbody id="requestsBody">
                                    <tr><td colspan="6" class="fd-rq-empty">Loading requests...</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="fd-rq-pagination fd-rq-pagination-v3">
                            <span id="countText">Showing 0 requests</span>
                            <div class="fd-rq-pagination-actions">
                                <button type="button" class="fd-rq-btn" id="prevPage" aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>
                                <button type="button" class="fd-rq-btn" id="nextPage" aria-label="Next page"><i class="bi bi-chevron-right"></i></button>
                            </div>
                        </div>
                    </section>

                <!-- Add / Edit Request -->
                <div class="fd-rq-modal-bg" id="requestModal" aria-hidden="true">
                    <section class="fd-rq-modal" role="dialog" aria-modal="true">
                        <div class="fd-rq-modal-header">
                            <span class="fd-rq-modal-icon"><i class="bi bi-inbox"></i></span>

                            <div class="fd-rq-modal-heading">
                                <h3 id="requestModalTitle">Add Service Request</h3>
                                <p>Capture the client requirement and route it to the next operational stage.</p>
                            </div>

                            <button type="button" class="fd-rq-modal-close" id="requestModalClose">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <form id="requestForm">
                            <div class="fd-rq-modal-body">
                                <input type="hidden" name="request_id" id="requestId" value="0">

                                <div class="fd-rq-form-grid">
                                    <div class="fd-rq-section">Customer & Service</div>

                                    <div class="fd-rq-field">
                                        <label for="clientId">Client *</label>
                                        <select name="client_id" id="clientId" required>
                                            <option value="">Select Client</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="locationId">Service Location</label>
                                        <select name="location_id" id="locationId">
                                            <option value="">Select Client First</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="serviceId">Service</label>
                                        <select name="product_service_id" id="serviceId">
                                            <option value="">Select Service</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="branchId">Branch</label>
                                        <select name="branch_id" id="branchId">
                                            <option value="">No Branch</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-section">Request Requirement</div>

                                    <div class="fd-rq-field full">
                                        <label for="requestTitle">Request Title *</label>
                                        <input
                                            type="text"
                                            name="title"
                                            id="requestTitle"
                                            maxlength="190"
                                            required
                                            placeholder="Example: AC not cooling - inspection required"
                                        >
                                    </div>

                                    <div class="fd-rq-field full">
                                        <label for="description">Requirement / Initial Notes</label>
                                        <textarea
                                            name="description"
                                            id="description"
                                            maxlength="5000"
                                            placeholder="Describe customer requirement, issue, scope, access notes or any information needed before assessment/quote/job."
                                        ></textarea>
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="source">Request Source</label>
                                        <select name="source" id="source">
                                            <option value="office">Office</option>
                                            <option value="website">Website</option>
                                            <option value="portal">Portal</option>
                                            <option value="phone">Phone</option>
                                            <option value="sms">SMS</option>
                                            <option value="email">Email</option>
                                            <option value="ai">AI</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="priority">Priority</label>
                                        <select name="priority" id="priority">
                                            <option value="low">Low</option>
                                            <option value="normal" selected>Normal</option>
                                            <option value="high">High</option>
                                            <option value="urgent">Urgent</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-section">Preferred Schedule & Ownership</div>

                                    <div class="fd-rq-field">
                                        <label for="preferredDate">Preferred Date</label>
                                        <input type="date" name="preferred_date" id="preferredDate">
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="assignedUserId">Assigned To</label>
                                        <select name="assigned_user_id" id="assignedUserId">
                                            <option value="">Unassigned</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="preferredTimeFrom">Preferred Time From</label>
                                        <input type="time" name="preferred_time_from" id="preferredTimeFrom">
                                    </div>

                                    <div class="fd-rq-field">
                                        <label for="preferredTimeTo">Preferred Time To</label>
                                        <input type="time" name="preferred_time_to" id="preferredTimeTo">
                                    </div>

                                    <div class="fd-rq-field full">
                                        <label for="requestStatus">Current Stage / Status</label>
                                        <select name="status" id="requestStatus">
                                            <option value="new">New</option>
                                            <option value="contacting">Contacting</option>
                                            <option value="information_required">Information Required</option>
                                            <option value="assessment_required">Assessment Required</option>
                                            <option value="quote_required">Quote Required</option>
                                            <option value="job_required">Job Required</option>
                                            <option value="converted">Converted</option>
                                            <option value="closed">Closed</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                    </div>

                                    <div class="fd-rq-field full" id="statusNoteWrap" style="display:none">
                                        <label for="statusNote">Status Change Note</label>
                                        <textarea
                                            name="status_note"
                                            id="statusNote"
                                            maxlength="3000"
                                            placeholder="Reason or notes for this status change"
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="fd-rq-modal-footer">
                                <button type="button" class="fd-rq-btn" id="cancelRequest">
                                    Cancel
                                </button>

                                <button type="submit" class="fd-rq-btn primary" id="saveRequest">
                                    <span class="fd-rq-loader"></span>
                                    <i class="bi bi-check-lg"></i>
                                    <span id="saveRequestText">Save Request</span>
                                </button>
                            </div>
                        </form>
                    </section>
                </div>

                <!-- Status history -->
                <div class="fd-rq-modal-bg" id="historyModal" aria-hidden="true">
                    <section class="fd-rq-modal small" role="dialog" aria-modal="true">
                        <div class="fd-rq-modal-header">
                            <span class="fd-rq-modal-icon"><i class="bi bi-clock-history"></i></span>

                            <div class="fd-rq-modal-heading">
                                <h3 id="historyTitle">Request Status History</h3>
                                <p>Complete request status movement and notes.</p>
                            </div>

                            <button type="button" class="fd-rq-modal-close" id="historyClose">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <div class="fd-rq-modal-body">
                            <div class="fd-rq-history" id="historyBody">
                                <div class="fd-rq-empty">Loading history...</div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Close / cancel confirmation -->
                <div class="fd-rq-modal-bg" id="deleteModal" aria-hidden="true">
                    <section class="fd-rq-modal small">
                        <div class="fd-rq-modal-header">
                            <span class="fd-rq-modal-icon"><i class="bi bi-x-circle"></i></span>

                            <div class="fd-rq-modal-heading">
                                <h3>Cancel Request</h3>
                                <p>The request is retained for audit and history.</p>
                            </div>

                            <button type="button" class="fd-rq-modal-close" id="deleteClose">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <div class="fd-rq-modal-body">
                            <div class="fd-rq-field">
                                <label for="cancelReason">Cancellation Reason *</label>
                                <textarea id="cancelReason" maxlength="3000" required></textarea>
                            </div>
                        </div>

                        <div class="fd-rq-modal-footer">
                            <button type="button" class="fd-rq-btn" id="cancelDelete">Back</button>
                            <button type="button" class="fd-rq-btn danger" id="confirmDelete">
                                <span class="fd-rq-loader"></span>
                                <i class="bi bi-x-circle"></i>
                                Cancel Request
                            </button>
                        </div>
                    </section>
                </div>

                <div class="fd-rq-toast info" id="requestToast">
                    <span class="fd-rq-toast-msg" id="requestToastMsg">Notification</span>

                    <button type="button" class="fd-rq-toast-close" id="requestToastClose">
                        <i class="bi bi-x"></i>
                    </button>
                </div>

                <script>
                (function(){
                'use strict';

                var csrfToken = <?= json_encode($requestsCsrfToken) ?>;

                var state = {
                    page:1,
                    perPage:10,
                    search:'',
                    status:'',
                    dateFilter:'',
                    priority:'',
                    branchId:'',
                    serviceId:'',
                    cancelId:0,
                    originalStatus:'',
                    meta:{
                        branches:[],
                        clients:[],
                        services:[],
                        users:[]
                    }
                };

                var tableBody = document.getElementById('requestsBody');
                var requestModal = document.getElementById('requestModal');
                var historyModal = document.getElementById('historyModal');
                var deleteModal = document.getElementById('deleteModal');
                var form = document.getElementById('requestForm');
                var saveButton = document.getElementById('saveRequest');
                var toast = document.getElementById('requestToast');
                var toastMsg = document.getElementById('requestToastMsg');

                var toastTimer = null;
                var searchTimer = null;

                function esc(v){
                    return String(v == null ? '' : v)
                        .replace(/&/g,'&amp;')
                        .replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;')
                        .replace(/"/g,'&quot;')
                        .replace(/'/g,'&#039;');
                }

                function readable(v){
                    return String(v || '-')
                        .replace(/_/g,' ')
                        .replace(/\b\w/g,function(x){return x.toUpperCase()});
                }

                function notify(type,message){
                    if(toastTimer) clearTimeout(toastTimer);

                    toast.className =
                        'fd-rq-toast '+
                        (type || 'info')+
                        ' show';

                    toastMsg.textContent =
                        message || 'Notification';

                    toastTimer = setTimeout(function(){
                        toast.classList.remove('show');
                    },3000);
                }

                function setLoading(button,on){
                    if(!button) return;
                    button.disabled = !!on;
                    button.classList.toggle('loading',!!on);
                }

                function parseResponse(response){
                    return response.text().then(function(rawText){
                        var text = (rawText || '').trim();
                        var data = null;

                        try{
                            data = text !== '' ? JSON.parse(text) : {};
                        }catch(e){
                            var clean = text
                                .replace(/<br\s*\/?>/gi,' ')
                                .replace(/<[^>]*>/g,' ')
                                .replace(/\s+/g,' ')
                                .trim();

                            throw new Error(
                                clean !== ''
                                    ? 'Server error: '+clean
                                    : 'Server returned an invalid response.'
                            );
                        }

                        if(
                            !response.ok ||
                            !data ||
                            data.success !== true
                        ){
                            throw new Error(
                                data && data.message
                                    ? data.message
                                    : 'Request failed.'
                            );
                        }

                        return data;
                    });
                }

                function request(fd){
                    fd.append('csrf_token',csrfToken);

                    return fetch(
                        'api/requests.php',
                        {
                            method:'POST',
                            body:fd,
                            credentials:'same-origin',
                            headers:{
                                'X-Requested-With':'XMLHttpRequest',
                                'Accept':'application/json'
                            }
                        }
                    ).then(parseResponse);
                }

                function requestList(fd){
                    fd.append('csrf_token',csrfToken);
                    return fetch(
                        'api/requests-list.php',
                        {
                            method:'POST',
                            body:fd,
                            credentials:'same-origin',
                            headers:{
                                'X-Requested-With':'XMLHttpRequest',
                                'Accept':'application/json'
                            }
                        }
                    ).then(parseResponse);
                }

                function formatDate(v){
                    if(!v) return '-';

                    var d = new Date(String(v).replace(' ','T'));

                    if(isNaN(d.getTime())) return esc(v);

                    return d.toLocaleDateString(
                        undefined,
                        {
                            day:'2-digit',
                            month:'short',
                            year:'numeric'
                        }
                    );
                }

                function time12(v){
                    if(!v) return '';

                    var parts = String(v).split(':');
                    var h = Number(parts[0] || 0);
                    var m = parts[1] || '00';
                    var ap = h >= 12 ? 'PM' : 'AM';
                    h = h % 12;
                    if(h === 0) h = 12;

                    return h+':'+m+' '+ap;
                }

                function timeAgo(v){
                    if(!v) return '-';
                    var normalized = String(v).replace(' ','T');
                    var d = new Date(normalized);
                    if(isNaN(d.getTime())) return formatDate(v);
                    var seconds = Math.max(0,Math.floor((Date.now()-d.getTime())/1000));
                    if(seconds < 60) return 'Just now';
                    var minutes = Math.floor(seconds/60);
                    if(minutes < 60) return minutes+' minute'+(minutes===1?'':'s')+' ago';
                    var hours = Math.floor(minutes/60);
                    if(hours < 24) return hours+' hour'+(hours===1?'':'s')+' ago';
                    var days = Math.floor(hours/24);
                    if(days < 30) return days+' day'+(days===1?'':'s')+' ago';
                    return formatDate(v);
                }

                function requestProperty(row){
                    var line1 = row.location_address_line1 || row.location_name || '';
                    var line2 = row.location_address_line2 || '';
                    var cityLine = [row.location_city || '',row.location_state || ''].filter(Boolean).join(', ');
                    if(row.location_postal_code){
                        cityLine += (cityLine ? ' ' : '') + row.location_postal_code;
                    }
                    var parts = [line1,line2,cityLine].filter(function(x){return String(x || '').trim() !== '';});
                    return parts.length ? parts : ['Property not confirmed'];
                }

                function fillSelect(id,rows,firstText){
                    var el = document.getElementById(id);
                    if(!el) return;
                    var html = '<option value="">'+esc(firstText)+'</option>';

                    (rows || []).forEach(function(row){
                        html +=
                            '<option value="'+Number(row.id)+'">'+
                            esc(row.name)+
                            '</option>';
                    });

                    el.innerHTML = html;
                }

                function applyMeta(meta){
                    state.meta = meta || state.meta;

                    fillSelect('branchId',state.meta.branches,'No Branch');
                    fillSelect('branchFilter',state.meta.branches,'All Branches');
                    fillSelect('serviceId',state.meta.services,'Select Service');
                    fillSelect('serviceFilter',state.meta.services,'All Services');
                    fillSelect('clientId',state.meta.clients,'Select Client');
                    fillSelect('assignedUserId',state.meta.users,'Unassigned');
                }

                function loadLocations(clientId,selectedId){
                    var locationEl = document.getElementById('locationId');

                    if(!clientId){
                        locationEl.innerHTML = '<option value="">Select Client First</option>';
                        return Promise.resolve();
                    }

                    var fd = new FormData();
                    fd.append('action','locations');
                    fd.append('client_id',clientId);

                    locationEl.innerHTML = '<option value="">Loading locations...</option>';

                    return request(fd)
                        .then(function(data){
                            var html = '<option value="">No Location / Not Confirmed</option>';

                            (data.locations || []).forEach(function(row){
                                html +=
                                    '<option value="'+Number(row.id)+'">'+
                                    esc(row.name)+
                                    (row.city ? ' - '+esc(row.city) : '')+
                                    '</option>';
                            });

                            locationEl.innerHTML = html;

                            if(selectedId){
                                locationEl.value = String(selectedId);
                            }
                        })
                        .catch(function(error){
                            locationEl.innerHTML = '<option value="">Unable to load locations</option>';
                            notify('error',error.message);
                        });
                }

                function render(rows){
                    if(!rows || !rows.length){
                        tableBody.innerHTML =
                            '<tr><td colspan="6" class="fd-rq-empty">No requests found.</td></tr>';
                        return;
                    }

                    var html = '';
                    rows.forEach(function(row){
                        var property = requestProperty(row);
                        var propertyHtml = property.map(function(part){return esc(part);}).join('<br>');
                        var phone = row.client_phone || '-';
                        var email = row.client_email || '';
                        html +=
                            '<tr class="fd-rq-clickable-row" data-request-view="'+Number(row.id)+'" tabindex="0" role="link" aria-label="View '+esc(row.title || row.request_no || 'request')+'">'+
                                '<td><span class="fd-rq-client-name">'+esc(row.client_name || '-')+'</span></td>'+ 
                                '<td><span class="fd-rq-cell-title">'+esc(row.title || '-')+'</span></td>'+ 
                                '<td><div class="fd-rq-property">'+propertyHtml+'</div></td>'+ 
                                '<td><div class="fd-rq-contact"><strong>'+esc(phone)+'</strong>'+(email ? '<small>'+esc(email)+'</small>' : '')+'</div></td>'+ 
                                '<td><span class="fd-rq-requested" title="'+esc(formatDate(row.created_at))+'">'+esc(timeAgo(row.created_at))+'</span></td>'+ 
                                '<td><span class="fd-rq-badge '+esc(row.status)+'">'+esc(readable(row.status))+'</span></td>'+ 
                            '</tr>';
                    });
                    tableBody.innerHTML = html;
                }

                function load(){
                    var fd = new FormData();
                    fd.append('action','list');
                    fd.append('page',state.page);
                    fd.append('per_page',state.perPage);
                    fd.append('search',state.search);
                    fd.append('status',state.status);
                    fd.append('date_filter',state.dateFilter);

                    tableBody.innerHTML =
                        '<tr><td colspan="6" class="fd-rq-empty">Loading requests...</td></tr>';

                    requestList(fd)
                        .then(function(data){
                            render(data.requests || []);
                            var p = data.pagination || {};
                            var total = Number(p.total || 0);
                            document.getElementById('resultCount').textContent =
                                '('+total+' result'+(total===1?'':'s')+')';
                            document.getElementById('countText').textContent =
                                total > 0
                                    ? 'Showing '+Number(p.from || 0)+'-'+Number(p.to || 0)+' of '+total+' requests'
                                    : 'Showing 0 requests';
                            document.getElementById('prevPage').disabled = state.page <= 1;
                            document.getElementById('nextPage').disabled = state.page >= Number(p.pages || 1);
                        })
                        .catch(function(error){
                            tableBody.innerHTML =
                                '<tr><td colspan="6" class="fd-rq-empty">'+esc(error.message)+'</td></tr>';
                            document.getElementById('resultCount').textContent = '(0 results)';
                            notify('error',error.message);
                        });
                }

                function resetForm(){
                    form.reset();

                    document.getElementById('requestId').value = 0;
                    document.getElementById('source').value = 'office';
                    document.getElementById('priority').value = 'normal';
                    document.getElementById('requestStatus').value = 'new';
                    document.getElementById('locationId').innerHTML =
                        '<option value="">Select Client First</option>';

                    state.originalStatus = 'new';

                    document.getElementById('statusNoteWrap').style.display =
                        'none';
                }

                function openRequest(id){
                    resetForm();

                    requestModal.classList.add('show');
                    requestModal.setAttribute('aria-hidden','false');

                    if(id <= 0){
                        document.getElementById('requestModalTitle').textContent =
                            'Add Service Request';

                        document.getElementById('saveRequestText').textContent =
                            'Save Request';

                        return;
                    }

                    var fd = new FormData();
                    fd.append('action','get');
                    fd.append('request_id',id);

                    request(fd)
                        .then(function(data){
                            applyMeta(data.meta || {});

                            var row = data.request || {};

                            document.getElementById('requestModalTitle').textContent =
                                'Edit '+(row.request_no || 'Request');

                            document.getElementById('saveRequestText').textContent =
                                'Update Request';

                            document.getElementById('requestId').value = row.id || 0;
                            document.getElementById('clientId').value = row.client_id || '';
                            document.getElementById('serviceId').value = row.product_service_id || '';
                            document.getElementById('branchId').value = row.branch_id || '';
                            document.getElementById('requestTitle').value = row.title || '';
                            document.getElementById('description').value = row.description || '';
                            document.getElementById('source').value = row.source || 'office';
                            document.getElementById('priority').value = row.priority || 'normal';
                            document.getElementById('preferredDate').value = row.preferred_date || '';
                            document.getElementById('preferredTimeFrom').value = row.preferred_time_from || '';
                            document.getElementById('preferredTimeTo').value = row.preferred_time_to || '';
                            document.getElementById('assignedUserId').value = row.assigned_user_id || '';
                            document.getElementById('requestStatus').value = row.status || 'new';

                            state.originalStatus = row.status || 'new';

                            return loadLocations(
                                row.client_id,
                                row.location_id
                            );
                        })
                        .catch(function(error){
                            closeRequest();
                            notify('error',error.message);
                        });
                }

                function closeRequest(){
                    requestModal.classList.remove('show');
                    requestModal.setAttribute('aria-hidden','true');
                }

                document.getElementById('clientId').addEventListener(
                    'change',
                    function(){
                        loadLocations(this.value,'');
                    }
                );

                document.getElementById('requestStatus').addEventListener(
                    'change',
                    function(){
                        document.getElementById('statusNoteWrap').style.display =
                            this.value !== state.originalStatus
                                ? 'block'
                                : 'none';
                    }
                );

                form.addEventListener('submit',function(event){
                    event.preventDefault();

                    if(!form.reportValidity()){
                        notify(
                            'warning',
                            'Complete the required service request fields.'
                        );
                        return;
                    }

                    var fd = new FormData(form);
                    fd.append('action','save');

                    setLoading(saveButton,true);

                    request(fd)
                        .then(function(data){
                            closeRequest();
                            notify('success',data.message);
                            load();
                        })
                        .catch(function(error){
                            notify('error',error.message);
                        })
                        .finally(function(){
                            setLoading(saveButton,false);
                        });
                });

                function openHistory(id,requestNo){
                    historyModal.classList.add('show');

                    document.getElementById('historyTitle').textContent =
                        (requestNo || 'Request')+
                        ' - Status History';

                    document.getElementById('historyBody').innerHTML =
                        '<div class="fd-rq-empty">Loading history...</div>';

                    var fd = new FormData();
                    fd.append('action','history');
                    fd.append('request_id',id);

                    request(fd)
                        .then(function(data){
                            var rows = data.history || [];
                            var box = document.getElementById('historyBody');

                            if(!rows.length){
                                box.innerHTML =
                                    '<div class="fd-rq-empty">No status history available.</div>';
                                return;
                            }

                            var html = '';

                            rows.forEach(function(row){
                                html +=
                                    '<div class="fd-rq-history-item">'+
                                        '<div class="fd-rq-history-top">'+
                                            '<strong>'+
                                                esc(readable(row.old_status || 'Created'))+
                                                ' → '+
                                                esc(readable(row.new_status))+
                                            '</strong>'+
                                            '<span class="fd-rq-badge '+esc(row.new_status)+'">'+esc(readable(row.new_status))+'</span>'+
                                        '</div>'+
                                        '<small>'+
                                            esc(row.changed_by_name || 'System')+
                                            ' · '+
                                            esc(formatDate(row.changed_at))+
                                        '</small>'+
                                        (row.notes
                                            ? '<p>'+esc(row.notes)+'</p>'
                                            : '')+
                                    '</div>';
                            });

                            box.innerHTML = html;
                        })
                        .catch(function(error){
                            document.getElementById('historyBody').innerHTML =
                                '<div class="fd-rq-empty">'+esc(error.message)+'</div>';

                            notify('error',error.message);
                        });
                }

                function openCancel(id,status){
                    if(status === 'cancelled'){
                        notify('warning','This request is already cancelled.');
                        return;
                    }

                    if(status === 'converted'){
                        notify('warning','Converted requests cannot be cancelled from this page.');
                        return;
                    }

                    state.cancelId = id;
                    document.getElementById('cancelReason').value = '';

                    deleteModal.classList.add('show');
                }

                document.getElementById('confirmDelete').onclick = function(){
                    if(state.cancelId <= 0) return;

                    var reason =
                        document.getElementById('cancelReason').value.trim();

                    if(!reason){
                        notify(
                            'warning',
                            'Enter the cancellation reason.'
                        );
                        document.getElementById('cancelReason').focus();
                        return;
                    }

                    var button = this;
                    var fd = new FormData();

                    fd.append('action','cancel');
                    fd.append('request_id',state.cancelId);
                    fd.append('notes',reason);

                    setLoading(button,true);

                    request(fd)
                        .then(function(data){
                            deleteModal.classList.remove('show');
                            state.cancelId = 0;

                            notify('success',data.message);
                            load();
                        })
                        .catch(function(error){
                            notify('error',error.message);
                        })
                        .finally(function(){
                            setLoading(button,false);
                        });
                };

                tableBody.addEventListener('click',function(event){
                    var button = event.target.closest('[data-action]');
                    if(!button) return;

                    var action = button.dataset.action;
                    var id = Number(button.dataset.id);

                    if(action === 'edit'){
                        openRequest(id);
                        return;
                    }

                    if(action === 'history'){
                        openHistory(
                            id,
                            button.dataset.no || ''
                        );
                        return;
                    }

                    if(action === 'cancel'){
                        openCancel(
                            id,
                            button.dataset.status
                        );
                    }
                });

                
                /* Version 2.2.0: open Request View from anywhere on the normal row. */
                function openRequestViewFromRow(row){
                    if(!row) return;
                    var requestId = Number(row.getAttribute('data-request-view') || 0);
                    if(requestId > 0){
                        window.location.href = 'request-view.php?request_id=' + requestId;
                    }
                }

                tableBody.addEventListener('click',function(event){
                    if(event.target.closest('a,button,input,select,textarea,label,[data-action]')) return;
                    openRequestViewFromRow(event.target.closest('tr[data-request-view]'));
                });

                tableBody.addEventListener('keydown',function(event){
                    if(event.key !== 'Enter' && event.key !== ' ') return;
                    if(event.target.closest('a,button,input,select,textarea,label,[data-action]')) return;
                    var row = event.target.closest('tr[data-request-view]');
                    if(!row) return;
                    event.preventDefault();
                    openRequestViewFromRow(row);
                });

                var moreActionsButton = document.getElementById('moreActionsButton');
                var moreActionsMenu = document.getElementById('moreActionsMenu');
                var shareRequestFormButton = document.getElementById('shareRequestFormButton');
                var fieldplxAiLearnButton = document.getElementById('fieldplxAiLearnButton');

                if(moreActionsButton && moreActionsMenu){
                    moreActionsButton.addEventListener('click',function(event){
                        event.stopPropagation();
                        var open = moreActionsMenu.classList.toggle('show');
                        moreActionsButton.setAttribute('aria-expanded',open ? 'true' : 'false');
                    });
                    document.addEventListener('click',function(event){
                        if(!event.target.closest('.fd-rq-more-wrap')){
                            moreActionsMenu.classList.remove('show');
                            moreActionsButton.setAttribute('aria-expanded','false');
                        }
                    });
                }

                if(shareRequestFormButton){
                    shareRequestFormButton.addEventListener('click',function(){
                        var url = window.location.origin + window.location.pathname.replace(/requests(?:\.php)?$/,'add-request.php');
                        if(navigator.clipboard && navigator.clipboard.writeText){
                            navigator.clipboard.writeText(url).then(function(){
                                notify('success','Request form link copied.');
                            }).catch(function(){
                                notify('info','Request form: '+url);
                            });
                        }else{
                            notify('info','Request form: '+url);
                        }
                        if(moreActionsMenu) moreActionsMenu.classList.remove('show');
                    });
                }

                if(fieldplxAiLearnButton){
                    fieldplxAiLearnButton.addEventListener('click',function(){
                        notify('info','FieldPlx AI request insights can be connected to this dashboard.');
                    });
                }

                document.getElementById('requestModalClose').onclick = closeRequest;
                document.getElementById('cancelRequest').onclick = closeRequest;

                document.getElementById('historyClose').onclick =
                    function(){historyModal.classList.remove('show')};

                document.getElementById('deleteClose').onclick =
                    function(){deleteModal.classList.remove('show')};

                document.getElementById('cancelDelete').onclick =
                    function(){deleteModal.classList.remove('show')};

                document.getElementById('requestToastClose').onclick =
                    function(){toast.classList.remove('show')};

                document.getElementById('requestSearch').addEventListener(
                    'input',
                    function(event){
                        if(searchTimer) clearTimeout(searchTimer);
                        searchTimer = setTimeout(function(){
                            state.search = event.target.value.trim();
                            state.page = 1;
                            load();
                        },250);
                    }
                );

                document.getElementById('statusFilter').onchange = function(event){
                    state.status = event.target.value;
                    state.page = 1;
                    load();
                };

                document.getElementById('dateFilter').onchange = function(event){
                    state.dateFilter = event.target.value;
                    state.page = 1;
                    load();
                };

                document.getElementById('prevPage').onclick = function(){
                    if(state.page > 1){
                        state.page--;
                        load();
                    }
                };

                document.getElementById('nextPage').onclick = function(){
                    state.page++;
                    load();
                };

                [requestModal,historyModal,deleteModal].forEach(function(modal){
                    modal.addEventListener('click',function(event){
                        if(event.target === modal){
                            modal.classList.remove('show');
                        }
                    });
                });


                document.querySelectorAll('[data-overview-status]').forEach(function(link){
                    link.addEventListener('click',function(){
                        var status = this.getAttribute('data-overview-status') || '';
                        document.getElementById('statusFilter').value = status;
                        state.status = status;
                        state.page = 1;
                        load();
                    });
                });

                load();

                })();
                </script>

            </div>
        </main>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


</body>

</html>