-- AlterTable
ALTER TABLE "Ad" ADD COLUMN     "creativeExternalId" TEXT NOT NULL DEFAULT '';

-- AlterTable
ALTER TABLE "AdSet" ADD COLUMN     "funnelStage" TEXT NOT NULL DEFAULT '',
ADD COLUMN     "funnelStageManual" TEXT NOT NULL DEFAULT '';

-- CreateTable
CREATE TABLE "Action" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "accountId" TEXT NOT NULL,
    "kind" TEXT NOT NULL,
    "level" TEXT NOT NULL,
    "entityId" TEXT NOT NULL,
    "entityName" TEXT NOT NULL DEFAULT '',
    "payload" JSONB,
    "reason" TEXT NOT NULL DEFAULT '',
    "source" TEXT NOT NULL DEFAULT 'audit',
    "priority" INTEGER NOT NULL DEFAULT 0,
    "status" TEXT NOT NULL DEFAULT 'proposed',
    "before" JSONB,
    "after" JSONB,
    "dedupeKey" TEXT NOT NULL DEFAULT '',
    "error" TEXT NOT NULL DEFAULT '',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "createdById" TEXT,
    "decidedById" TEXT,
    "decidedAt" TIMESTAMP(3),

    CONSTRAINT "Action_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Rule" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "preset" TEXT NOT NULL DEFAULT '',
    "enabled" BOOLEAN NOT NULL DEFAULT true,
    "level" TEXT NOT NULL DEFAULT 'ADSET',
    "scope" JSONB NOT NULL,
    "conditions" JSONB NOT NULL,
    "windowDays" INTEGER NOT NULL DEFAULT 7,
    "action" JSONB NOT NULL,
    "lastRunAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "createdById" TEXT,

    CONSTRAINT "Rule_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Tag" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "color" TEXT NOT NULL DEFAULT '#64748b',

    CONSTRAINT "Tag_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "EntityTag" (
    "id" TEXT NOT NULL,
    "tagId" TEXT NOT NULL,
    "level" TEXT NOT NULL,
    "entityId" TEXT NOT NULL,

    CONSTRAINT "EntityTag_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "Action_clientId_status_idx" ON "Action"("clientId", "status");

-- CreateIndex
CREATE INDEX "Action_accountId_dedupeKey_idx" ON "Action"("accountId", "dedupeKey");

-- CreateIndex
CREATE INDEX "Rule_clientId_idx" ON "Rule"("clientId");

-- CreateIndex
CREATE UNIQUE INDEX "Tag_clientId_name_key" ON "Tag"("clientId", "name");

-- CreateIndex
CREATE INDEX "EntityTag_level_entityId_idx" ON "EntityTag"("level", "entityId");

-- CreateIndex
CREATE UNIQUE INDEX "EntityTag_tagId_level_entityId_key" ON "EntityTag"("tagId", "level", "entityId");

-- AddForeignKey
ALTER TABLE "EntityTag" ADD CONSTRAINT "EntityTag_tagId_fkey" FOREIGN KEY ("tagId") REFERENCES "Tag"("id") ON DELETE CASCADE ON UPDATE CASCADE;

