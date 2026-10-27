ALTER TABLE users
  MODIFY role ENUM('owner','admin','manager','worker','viewer') NOT NULL DEFAULT 'viewer';

UPDATE users SET role = 'worker' WHERE role = 'manager';

ALTER TABLE users
  MODIFY role ENUM('owner','admin','worker','viewer') NOT NULL DEFAULT 'viewer';

ALTER TABLE invitations
  MODIFY role ENUM('admin','manager','worker','viewer') NOT NULL;

UPDATE invitations SET role = 'worker' WHERE role = 'manager';

ALTER TABLE invitations
  MODIFY role ENUM('admin','worker','viewer') NOT NULL;
