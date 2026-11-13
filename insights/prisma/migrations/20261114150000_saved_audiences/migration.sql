-- CreateTable
CREATE TABLE "SavedAudience" (
    "id" TEXT NOT NULL,
    "accountId" TEXT NOT NULL,
    "externalId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "kind" TEXT NOT NULL DEFAULT 'other',
    "stage" TEXT NOT NULL DEFAULT 'retargeting',
    "sizeLow" BIGINT,
    "sizeHigh" BIGINT,
    "excludeFrom" TEXT[] DEFAULT ARRAY[]::TEXT[],
    "createdHere" BOOLEAN NOT NULL DEFAULT false,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "SavedAudience_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "SavedAudience_accountId_externalId_key" ON "SavedAudience"("accountId", "externalId");

