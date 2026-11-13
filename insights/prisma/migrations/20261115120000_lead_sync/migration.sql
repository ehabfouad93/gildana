-- CreateTable
CREATE TABLE "LeadDestination" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "companyId" TEXT,
    "name" TEXT NOT NULL,
    "urlEnc" TEXT NOT NULL,
    "secretEnc" TEXT NOT NULL,
    "enabled" BOOLEAN NOT NULL DEFAULT true,
    "lastError" TEXT NOT NULL DEFAULT '',
    "lastSentAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "createdById" TEXT,

    CONSTRAINT "LeadDestination_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "LeadDelivery" (
    "id" TEXT NOT NULL,
    "leadId" TEXT NOT NULL,
    "destinationId" TEXT NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'failed',
    "attempts" INTEGER NOT NULL DEFAULT 0,
    "error" TEXT NOT NULL DEFAULT '',
    "nextTryAt" TIMESTAMP(3),
    "sentAt" TIMESTAMP(3),

    CONSTRAINT "LeadDelivery_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "LeadDestination_clientId_idx" ON "LeadDestination"("clientId");

-- CreateIndex
CREATE INDEX "LeadDelivery_destinationId_status_idx" ON "LeadDelivery"("destinationId", "status");

-- CreateIndex
CREATE UNIQUE INDEX "LeadDelivery_leadId_destinationId_key" ON "LeadDelivery"("leadId", "destinationId");

-- AddForeignKey
ALTER TABLE "LeadDelivery" ADD CONSTRAINT "LeadDelivery_leadId_fkey" FOREIGN KEY ("leadId") REFERENCES "Lead"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "LeadDelivery" ADD CONSTRAINT "LeadDelivery_destinationId_fkey" FOREIGN KEY ("destinationId") REFERENCES "LeadDestination"("id") ON DELETE CASCADE ON UPDATE CASCADE;

