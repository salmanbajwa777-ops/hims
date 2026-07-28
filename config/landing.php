<?php
/**
 * Where a user lands after signing in.
 *
 * Extracted from index.php so it can be reused without side effects: index.php
 * redirects an already-logged-in visitor at include time, so anything else
 * needing this rule (impersonate.php) could not simply require it. index.php
 * now defers to this file, keeping ONE definition of the landing rule.
 *
 * Only DOCTOR and ADMIN are role-driven; STAFF is one role covering every
 * desk/ward worker, so their home is chosen by what they can actually DO
 * (permissions), not by a sub-role that no longer exists. Requires session
 * permissions to be loaded.
 */

if (!function_exists('landing_page_for_role')) {
    function landing_page_for_role(string $baseRole): string
    {
        if ($baseRole === 'DOCTOR') { return '/doctor.php'; }
        if ($baseRole === 'ADMIN')  { return '/dashboard.php'; }
        // STAFF (and any legacy value) — permission-driven.
        if (function_exists('has_permission')) {
            if (has_permission('RECEPTION_REGISTER_PATIENTS')) { return '/receptionist.php'; }
            if (has_permission('NURSING_RECORD_ADMISSIONS'))   { return '/admissions.php'; }
        }
        return '/dashboard.php';
    }
}
