UPDATE users u
JOIN companies c ON c.id = u.company_id
SET u.role = 'worker'
WHERE u.role = 'viewer' AND c.is_demo = 0;
