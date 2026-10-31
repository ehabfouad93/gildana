-- CreateTable
CREATE TABLE "InterestTest" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "accountId" TEXT NOT NULL,
    "campaignId" TEXT NOT NULL,
    "sourceAdSetId" TEXT NOT NULL,
    "arms" JSONB NOT NULL,
    "dailyBudget" DECIMAL(14,2),
    "status" TEXT NOT NULL DEFAULT 'created',
    "error" TEXT NOT NULL DEFAULT '',
    "createdById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "InterestTest_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "InterestTest_clientId_idx" ON "InterestTest"("clientId");

-- CreateIndex
CREATE INDEX "InterestTest_campaignId_idx" ON "InterestTest"("campaignId");
