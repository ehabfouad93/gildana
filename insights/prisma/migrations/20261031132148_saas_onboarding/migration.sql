-- AlterTable
ALTER TABLE "AdConnection" ADD COLUMN     "agencySourceId" TEXT,
ADD COLUMN     "isAgency" BOOLEAN NOT NULL DEFAULT false,
ALTER COLUMN "clientId" DROP NOT NULL;

-- AlterTable
ALTER TABLE "Client" ADD COLUMN     "onboardedAt" TIMESTAMP(3);

-- AlterTable
ALTER TABLE "PasswordReset" ADD COLUMN     "purpose" TEXT NOT NULL DEFAULT 'reset';

-- CreateTable
CREATE TABLE "AppSetting" (
    "key" TEXT NOT NULL,
    "valueEnc" TEXT NOT NULL,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "AppSetting_pkey" PRIMARY KEY ("key")
);

-- CreateTable
CREATE TABLE "AccountClaim" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "companyId" TEXT,
    "platform" "Platform" NOT NULL,
    "externalId" TEXT NOT NULL,
    "name" TEXT NOT NULL DEFAULT '',
    "visible" BOOLEAN NOT NULL DEFAULT false,
    "status" TEXT NOT NULL DEFAULT 'pending',
    "note" TEXT NOT NULL DEFAULT '',
    "accountId" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "decidedAt" TIMESTAMP(3),

    CONSTRAINT "AccountClaim_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "AccountClaim_status_idx" ON "AccountClaim"("status");

-- CreateIndex
CREATE UNIQUE INDEX "AccountClaim_clientId_platform_externalId_key" ON "AccountClaim"("clientId", "platform", "externalId");

-- AddForeignKey
ALTER TABLE "AccountClaim" ADD CONSTRAINT "AccountClaim_clientId_fkey" FOREIGN KEY ("clientId") REFERENCES "Client"("id") ON DELETE CASCADE ON UPDATE CASCADE;
