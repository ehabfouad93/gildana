-- Per-user ad account access and allowed sections.
ALTER TABLE "User" ADD COLUMN "accountIds" TEXT[] DEFAULT ARRAY[]::TEXT[],
ADD COLUMN "modules" TEXT[] DEFAULT ARRAY[]::TEXT[];
