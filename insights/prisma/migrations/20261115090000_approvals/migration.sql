-- AlterTable
ALTER TABLE "Client" ADD COLUMN     "requireApproval" BOOLEAN NOT NULL DEFAULT false;

-- CreateTable
CREATE TABLE "Approval" (
    "id" TEXT NOT NULL,
    "token" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "accountId" TEXT NOT NULL,
    "kind" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "summary" JSONB NOT NULL,
    "payload" JSONB NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'pending',
    "comment" TEXT NOT NULL DEFAULT '',
    "contactName" TEXT NOT NULL DEFAULT '',
    "decidedAt" TIMESTAMP(3),
    "launchedAt" TIMESTAMP(3),
    "launchNote" TEXT NOT NULL DEFAULT '',
    "createdById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "Approval_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "Approval_token_key" ON "Approval"("token");

-- CreateIndex
CREATE INDEX "Approval_clientId_status_idx" ON "Approval"("clientId", "status");

