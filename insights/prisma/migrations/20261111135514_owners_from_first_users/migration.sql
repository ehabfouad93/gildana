-- Each client's earliest active user becomes its owner (sign-ups and invited
-- first users were created as CLIENT before the OWNER role existed).
UPDATE "User" u SET role = 'OWNER'
WHERE u.role = 'CLIENT' AND u."clientId" IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM "User" o WHERE o."clientId" = u."clientId" AND o.role = 'OWNER')
  AND u.id = (SELECT f.id FROM "User" f WHERE f."clientId" = u."clientId" AND f.active ORDER BY f."createdAt" ASC LIMIT 1);
