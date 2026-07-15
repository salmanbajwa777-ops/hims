const crypto = require('crypto');
const fs = require('fs');

function id() {
  return 'c' + crypto.randomBytes(12).toString('hex');
}

const adminId = id();
const adminHash = '$2b$10$QJzqA4WL.yMlxsQM8qNC9OHDTzEZQiWLp34/ZtHFFnIyEJeLymO9a'; // admin1234

const permissions = [
  // clinical
  ['patients.view', 'View all patients', 'clinical'],
  ['patients.register', 'Register/edit patients', 'clinical'],
  ['consultation.create', 'Create consultation', 'clinical'],
  ['consultation.edit_own', 'Edit own consultation notes', 'clinical'],
  ['consultation.edit_all', 'Edit any consultation notes', 'clinical'],
  ['vitals.record', 'Record vitals', 'clinical'],
  ['vitals.view', 'View vitals', 'clinical'],
  ['procedures.record', 'Record procedures', 'clinical'],
  ['shortstay.record_events', 'Record short-stay events', 'clinical'],
  ['shortstay.manage_beds', 'Manage bed status', 'clinical'],
  ['consent.print', 'Print consent forms', 'clinical'],
  ['qa.view', 'View QA assessments', 'clinical'],
  ['qa.perform', 'Perform QA assessments', 'clinical'],
  ['shift.activate_reception', 'Activate reception shift', 'clinical'],
  // financial
  ['commission.view_own', 'View own commission', 'financial'],
  ['financials.view_clinic', 'View clinic financials', 'financial'],
  ['doctor_terms.edit', 'Edit doctor financial terms', 'financial'],
  ['doctor_terms.view', 'View doctor financial terms', 'financial'],
  ['rate_master.view', 'View rate master', 'financial'],
  ['rate_master.edit', 'Edit rate master', 'financial'],
  ['tax_config.view', 'View tax configuration', 'financial'],
  ['tax_config.edit', 'Edit tax configuration', 'financial'],
  ['billing.generate', 'Generate bills', 'financial'],
  ['billing.view', 'View patient invoices', 'financial'],
  ['expenses.view', 'View expenses', 'financial'],
  ['expenses.add', 'Add expenses', 'financial'],
  ['expense_categories.manage', 'Manage expense categories', 'financial'],
  // admin
  ['staff.manage', 'Manage staff', 'admin'],
  ['doctors.onboard', 'Onboard doctors', 'admin'],
  ['permissions.manage', 'Manage roles & permissions', 'admin'],
  ['tasks.assign', 'Assign special tasks', 'admin'],
  ['audit.view', 'View audit logs', 'admin'],
  ['reports.view', 'View reports', 'admin'],
];

const permRows = permissions.map(([key, label, cat]) => ({ id: id(), key, label, cat }));

const lines = [];

lines.push('-- Phase 1 seed: admin user, permission catalog, ADMIN role grants');
lines.push('-- Login: admin / admin1234  (mustChangePassword = 1, change on first login)');
lines.push('');
lines.push('-- Seed: admin user');
lines.push('INSERT INTO `User` (`id`, `name`, `email`, `password`, `baseRole`, `status`, `mustChangePassword`, `createdAt`, `updatedAt`) VALUES');
lines.push(`('${adminId}', 'Admin', 'admin', '${adminHash}', 'ADMIN', 'active', 1, NOW(3), NOW(3));`);
lines.push('');

lines.push('-- Seed: permission catalog');
lines.push('INSERT INTO `Permission` (`id`, `key`, `label`, `category`, `createdAt`) VALUES');
lines.push(
  permRows
    .map((p) => `('${p.id}', '${p.key}', '${p.label.replace(/'/g, "''")}', '${p.cat}', NOW(3))`)
    .join(',\n') + ';'
);
lines.push('');

lines.push('-- Seed: RolePermission — ADMIN gets every permission');
lines.push('INSERT INTO `RolePermission` (`id`, `baseRole`, `permissionId`) VALUES');
lines.push(
  permRows.map((p) => `('${id()}', 'ADMIN', '${p.id}')`).join(',\n') + ';'
);
lines.push('');

fs.writeFileSync('prisma/seed_phase1.sql', lines.join('\n') + '\n');
console.log('Written prisma/seed_phase1.sql');
console.log('Admin User id:', adminId);
console.log('Permission count:', permRows.length);
