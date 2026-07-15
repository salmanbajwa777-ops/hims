-- Phase 1 seed: admin user, permission catalog, ADMIN role grants
-- Login: admin / admin1234  (mustChangePassword = 1, change on first login)

-- Seed: admin user
INSERT INTO `User` (`id`, `name`, `email`, `password`, `baseRole`, `status`, `mustChangePassword`, `createdAt`, `updatedAt`) VALUES
('cb2714e3a721bfe1a30afdb55', 'Admin', 'admin', '$2b$10$QJzqA4WL.yMlxsQM8qNC9OHDTzEZQiWLp34/ZtHFFnIyEJeLymO9a', 'ADMIN', 'active', 1, NOW(3), NOW(3));

-- Seed: permission catalog
INSERT INTO `Permission` (`id`, `key`, `label`, `category`, `createdAt`) VALUES
('c5266fb006b44ef009f2e6e6c', 'patients.view', 'View all patients', 'clinical', NOW(3)),
('c13ea4626645873cad0622f5f', 'patients.register', 'Register/edit patients', 'clinical', NOW(3)),
('c62dcdaf0235b4fc3a172cb51', 'consultation.create', 'Create consultation', 'clinical', NOW(3)),
('cdb3c65d483ee728fd1a11527', 'consultation.edit_own', 'Edit own consultation notes', 'clinical', NOW(3)),
('cd9314f5475da6c448073c59f', 'consultation.edit_all', 'Edit any consultation notes', 'clinical', NOW(3)),
('cd4c0ed775241eb194174869a', 'vitals.record', 'Record vitals', 'clinical', NOW(3)),
('ccbd0067840abd1cce1e30c27', 'vitals.view', 'View vitals', 'clinical', NOW(3)),
('c8bf59dffbdc25b60a97ad671', 'procedures.record', 'Record procedures', 'clinical', NOW(3)),
('cb78623f1ea7b9de81dafe32d', 'shortstay.record_events', 'Record short-stay events', 'clinical', NOW(3)),
('c254eaf27a3d0ba4fa1d3e072', 'shortstay.manage_beds', 'Manage bed status', 'clinical', NOW(3)),
('cd1d8e1c953dfbcdc601332a2', 'consent.print', 'Print consent forms', 'clinical', NOW(3)),
('c97ae251b401e3082c9370cd9', 'qa.view', 'View QA assessments', 'clinical', NOW(3)),
('c0ff710fc86a7eea202de244c', 'qa.perform', 'Perform QA assessments', 'clinical', NOW(3)),
('cbdfa98068cf0c0f8585d1ffe', 'shift.activate_reception', 'Activate reception shift', 'clinical', NOW(3)),
('c0bce69fd09198c47f6960a86', 'commission.view_own', 'View own commission', 'financial', NOW(3)),
('c03899ec14882295d83d4a0c7', 'financials.view_clinic', 'View clinic financials', 'financial', NOW(3)),
('c078a11ade9629a16a7cb2cbc', 'doctor_terms.edit', 'Edit doctor financial terms', 'financial', NOW(3)),
('c8fd0f96bae3e98cadc05430b', 'doctor_terms.view', 'View doctor financial terms', 'financial', NOW(3)),
('cfe2ca7720876d9b0d9e58360', 'rate_master.view', 'View rate master', 'financial', NOW(3)),
('c740e45c3a585cf15ba4167d8', 'rate_master.edit', 'Edit rate master', 'financial', NOW(3)),
('c52be2161062afe22c7790aee', 'tax_config.view', 'View tax configuration', 'financial', NOW(3)),
('c88b1a2330a256e0d646357cb', 'tax_config.edit', 'Edit tax configuration', 'financial', NOW(3)),
('c6d7c38e5dab2511cd57148c8', 'billing.generate', 'Generate bills', 'financial', NOW(3)),
('c2cfdb3182ce05b625e501006', 'billing.view', 'View patient invoices', 'financial', NOW(3)),
('c3cf4c9676df46b3819c7e04c', 'expenses.view', 'View expenses', 'financial', NOW(3)),
('c081a7480ba92afcc2b9c3c2b', 'expenses.add', 'Add expenses', 'financial', NOW(3)),
('cade18930288cc51f99b7536d', 'expense_categories.manage', 'Manage expense categories', 'financial', NOW(3)),
('c2f221f296d51d7829fb61a4d', 'staff.manage', 'Manage staff', 'admin', NOW(3)),
('c56b7180f57931280dc6e6773', 'doctors.onboard', 'Onboard doctors', 'admin', NOW(3)),
('c0ad267096cea2e52a6984fdc', 'permissions.manage', 'Manage roles & permissions', 'admin', NOW(3)),
('cf347e60e4fed6bf5515808f7', 'tasks.assign', 'Assign special tasks', 'admin', NOW(3)),
('c88cddf6c0c84c47df12933f9', 'audit.view', 'View audit logs', 'admin', NOW(3)),
('ca87a6d8e7e3a64582e00d19e', 'reports.view', 'View reports', 'admin', NOW(3));

-- Seed: RolePermission — ADMIN gets every permission
INSERT INTO `RolePermission` (`id`, `baseRole`, `permissionId`) VALUES
('c9759205d7685f2518319409f', 'ADMIN', 'c5266fb006b44ef009f2e6e6c'),
('c6338e3bb53db4fc3171ccb73', 'ADMIN', 'c13ea4626645873cad0622f5f'),
('cb8a31a92ac42e69e2141a786', 'ADMIN', 'c62dcdaf0235b4fc3a172cb51'),
('c549afd0d22284232495d999a', 'ADMIN', 'cdb3c65d483ee728fd1a11527'),
('cc149bfd8baa190f97a67b02d', 'ADMIN', 'cd9314f5475da6c448073c59f'),
('cbcf03ebfb79c9f66eec1cb43', 'ADMIN', 'cd4c0ed775241eb194174869a'),
('c1bc25c33d59d7af9b342d4a2', 'ADMIN', 'ccbd0067840abd1cce1e30c27'),
('cfcd7439fbe4bebb822ec55d1', 'ADMIN', 'c8bf59dffbdc25b60a97ad671'),
('c19b6804f41a45fac8767e637', 'ADMIN', 'cb78623f1ea7b9de81dafe32d'),
('c76fb43dae2ce2e3d1c30b9e2', 'ADMIN', 'c254eaf27a3d0ba4fa1d3e072'),
('cbd54bf57c2e50a4c0e54dc21', 'ADMIN', 'cd1d8e1c953dfbcdc601332a2'),
('cf539b303786a690bb14abe25', 'ADMIN', 'c97ae251b401e3082c9370cd9'),
('c054d2eff6a3aa61805cc9e9d', 'ADMIN', 'c0ff710fc86a7eea202de244c'),
('ce69bc53bb81c083974838cb2', 'ADMIN', 'cbdfa98068cf0c0f8585d1ffe'),
('c9b2203b7beba8fdf0b632f0e', 'ADMIN', 'c0bce69fd09198c47f6960a86'),
('ccfeecca16e442d74f322c787', 'ADMIN', 'c03899ec14882295d83d4a0c7'),
('c71318863f04e4933e697025f', 'ADMIN', 'c078a11ade9629a16a7cb2cbc'),
('c8cd6109f4723b426a2558ee1', 'ADMIN', 'c8fd0f96bae3e98cadc05430b'),
('c52caef9373d64d203986f407', 'ADMIN', 'cfe2ca7720876d9b0d9e58360'),
('cc45da737f371af5df34d74a4', 'ADMIN', 'c740e45c3a585cf15ba4167d8'),
('cb653c30c04bedbb5b04008d9', 'ADMIN', 'c52be2161062afe22c7790aee'),
('c9623bc0c4d55c1a7b42a68d3', 'ADMIN', 'c88b1a2330a256e0d646357cb'),
('c831c239f3bec4aa63b6fec0e', 'ADMIN', 'c6d7c38e5dab2511cd57148c8'),
('cc4e15264fff67c308a1896e2', 'ADMIN', 'c2cfdb3182ce05b625e501006'),
('c7f4bcad4c26e8a78ced33e45', 'ADMIN', 'c3cf4c9676df46b3819c7e04c'),
('cf3eb98b3ee9e7f521a4d64cc', 'ADMIN', 'c081a7480ba92afcc2b9c3c2b'),
('c7b7f3d8c13d998ff38e64cb9', 'ADMIN', 'cade18930288cc51f99b7536d'),
('cc44ee6daef2a3f59995ccc62', 'ADMIN', 'c2f221f296d51d7829fb61a4d'),
('c402fcc963a67bb58fcc5bc3f', 'ADMIN', 'c56b7180f57931280dc6e6773'),
('c45507812d14f11f4a00fbb0e', 'ADMIN', 'c0ad267096cea2e52a6984fdc'),
('c615e7b0bf7d37bd7eb2181e0', 'ADMIN', 'cf347e60e4fed6bf5515808f7'),
('cb630a120c8e01a81f134dc35', 'ADMIN', 'c88cddf6c0c84c47df12933f9'),
('ca21795317054cfb837621447', 'ADMIN', 'ca87a6d8e7e3a64582e00d19e');

