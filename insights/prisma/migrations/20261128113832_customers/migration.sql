-- AlterTable
ALTER TABLE "Lead" ADD COLUMN     "customerId" TEXT;

-- AlterTable
ALTER TABLE "TrackEvent" ADD COLUMN     "props" JSONB;

-- CreateTable
CREATE TABLE "Project" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "companyId" TEXT,
    "name" TEXT NOT NULL,
    "slug" TEXT NOT NULL,
    "aliases" TEXT[] DEFAULT ARRAY[]::TEXT[],
    "priceFrom" DOUBLE PRECISION,
    "priceTo" DOUBLE PRECISION,
    "currency" TEXT NOT NULL DEFAULT 'EGP',
    "active" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "Project_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Customer" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "scopeId" TEXT NOT NULL,
    "companyId" TEXT,
    "name" TEXT NOT NULL DEFAULT '',
    "phoneEnc" TEXT,
    "emailEnc" TEXT,
    "phoneHint" TEXT NOT NULL DEFAULT '',
    "emailHint" TEXT NOT NULL DEFAULT '',
    "stage" TEXT NOT NULL DEFAULT 'visitor',
    "projectIds" TEXT[] DEFAULT ARRAY[]::TEXT[],
    "budget" DOUBLE PRECISION,
    "budgetText" TEXT NOT NULL DEFAULT '',
    "budgetCurrency" TEXT NOT NULL DEFAULT '',
    "city" TEXT NOT NULL DEFAULT '',
    "preferences" JSONB NOT NULL DEFAULT '{}',
    "identityScore" INTEGER NOT NULL DEFAULT 0,
    "intentScore" INTEGER NOT NULL DEFAULT 0,
    "valueScore" INTEGER NOT NULL DEFAULT 0,
    "scoreWhy" JSONB NOT NULL DEFAULT '{}',
    "firstTouch" JSONB,
    "lastTouch" JSONB,
    "firstSeenAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "lastSeenAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "mergedIntoId" TEXT,
    "isDemo" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "Customer_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "CustomerIdentifier" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "scopeId" TEXT NOT NULL,
    "customerId" TEXT NOT NULL,
    "type" TEXT NOT NULL,
    "hash" TEXT NOT NULL,
    "valueEnc" TEXT,
    "display" TEXT NOT NULL DEFAULT '',
    "verified" BOOLEAN NOT NULL DEFAULT false,
    "source" TEXT NOT NULL DEFAULT '',
    "firstSeenAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "lastSeenAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "CustomerIdentifier_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "IdentityLink" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "customerId" TEXT NOT NULL,
    "otherCustomerId" TEXT,
    "identifierId" TEXT,
    "kind" TEXT NOT NULL,
    "method" TEXT NOT NULL,
    "confidence" INTEGER NOT NULL,
    "evidence" JSONB NOT NULL DEFAULT '{}',
    "source" TEXT NOT NULL DEFAULT '',
    "status" TEXT NOT NULL,
    "moved" JSONB,
    "createdById" TEXT,
    "reviewedById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewedAt" TIMESTAMP(3),

    CONSTRAINT "IdentityLink_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "CustomerEvent" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "customerId" TEXT NOT NULL,
    "source" TEXT NOT NULL,
    "type" TEXT NOT NULL,
    "occurredAt" TIMESTAMP(3) NOT NULL,
    "projectId" TEXT,
    "title" TEXT NOT NULL DEFAULT '',
    "props" JSONB NOT NULL DEFAULT '{}',
    "dedupKey" TEXT NOT NULL,

    CONSTRAINT "CustomerEvent_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "ConsentRecord" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "customerId" TEXT NOT NULL,
    "purpose" TEXT NOT NULL,
    "status" TEXT NOT NULL,
    "source" TEXT NOT NULL DEFAULT '',
    "at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "ConsentRecord_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "ApiKey" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "prefix" TEXT NOT NULL,
    "hash" TEXT NOT NULL,
    "scopes" TEXT[] DEFAULT ARRAY[]::TEXT[],
    "createdById" TEXT,
    "lastUsedAt" TIMESTAMP(3),
    "revokedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "ApiKey_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "AuditLog" (
    "id" TEXT NOT NULL,
    "clientId" TEXT,
    "userId" TEXT,
    "apiKeyId" TEXT,
    "action" TEXT NOT NULL,
    "target" TEXT NOT NULL DEFAULT '',
    "detail" JSONB NOT NULL DEFAULT '{}',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "AuditLog_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "Project_clientId_slug_key" ON "Project"("clientId", "slug");

-- CreateIndex
CREATE INDEX "Customer_clientId_mergedIntoId_lastSeenAt_idx" ON "Customer"("clientId", "mergedIntoId", "lastSeenAt");

-- CreateIndex
CREATE INDEX "Customer_scopeId_idx" ON "Customer"("scopeId");

-- CreateIndex
CREATE INDEX "Customer_clientId_stage_idx" ON "Customer"("clientId", "stage");

-- CreateIndex
CREATE INDEX "Customer_clientId_intentScore_idx" ON "Customer"("clientId", "intentScore");

-- CreateIndex
CREATE INDEX "CustomerIdentifier_customerId_idx" ON "CustomerIdentifier"("customerId");

-- CreateIndex
CREATE UNIQUE INDEX "CustomerIdentifier_scopeId_type_hash_key" ON "CustomerIdentifier"("scopeId", "type", "hash");

-- CreateIndex
CREATE INDEX "IdentityLink_clientId_status_createdAt_idx" ON "IdentityLink"("clientId", "status", "createdAt");

-- CreateIndex
CREATE INDEX "IdentityLink_customerId_idx" ON "IdentityLink"("customerId");

-- CreateIndex
CREATE INDEX "IdentityLink_otherCustomerId_idx" ON "IdentityLink"("otherCustomerId");

-- CreateIndex
CREATE INDEX "CustomerEvent_customerId_occurredAt_idx" ON "CustomerEvent"("customerId", "occurredAt");

-- CreateIndex
CREATE INDEX "CustomerEvent_clientId_type_occurredAt_idx" ON "CustomerEvent"("clientId", "type", "occurredAt");

-- CreateIndex
CREATE UNIQUE INDEX "CustomerEvent_clientId_dedupKey_key" ON "CustomerEvent"("clientId", "dedupKey");

-- CreateIndex
CREATE INDEX "ConsentRecord_customerId_purpose_at_idx" ON "ConsentRecord"("customerId", "purpose", "at");

-- CreateIndex
CREATE UNIQUE INDEX "ApiKey_hash_key" ON "ApiKey"("hash");

-- CreateIndex
CREATE INDEX "ApiKey_clientId_idx" ON "ApiKey"("clientId");

-- CreateIndex
CREATE INDEX "AuditLog_clientId_createdAt_idx" ON "AuditLog"("clientId", "createdAt");

-- CreateIndex
CREATE INDEX "Lead_customerId_idx" ON "Lead"("customerId");

-- AddForeignKey
ALTER TABLE "Project" ADD CONSTRAINT "Project_clientId_fkey" FOREIGN KEY ("clientId") REFERENCES "Client"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Customer" ADD CONSTRAINT "Customer_clientId_fkey" FOREIGN KEY ("clientId") REFERENCES "Client"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "CustomerIdentifier" ADD CONSTRAINT "CustomerIdentifier_customerId_fkey" FOREIGN KEY ("customerId") REFERENCES "Customer"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "CustomerEvent" ADD CONSTRAINT "CustomerEvent_customerId_fkey" FOREIGN KEY ("customerId") REFERENCES "Customer"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "ConsentRecord" ADD CONSTRAINT "ConsentRecord_customerId_fkey" FOREIGN KEY ("customerId") REFERENCES "Customer"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "ApiKey" ADD CONSTRAINT "ApiKey_clientId_fkey" FOREIGN KEY ("clientId") REFERENCES "Client"("id") ON DELETE CASCADE ON UPDATE CASCADE;
