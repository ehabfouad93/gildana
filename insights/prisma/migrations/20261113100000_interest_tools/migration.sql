-- Per-user feature switches, split-test campaigns, cached AI suggestions.
ALTER TABLE "User" ADD COLUMN "featuresOff" TEXT[] DEFAULT ARRAY[]::TEXT[];

ALTER TABLE "InterestTest" ADD COLUMN "kind" TEXT NOT NULL DEFAULT 'adset',
ADD COLUMN "newCampaignExternalId" TEXT NOT NULL DEFAULT '',
ADD COLUMN "name" TEXT NOT NULL DEFAULT '',
ADD COLUMN "endsAt" TIMESTAMP(3);

CREATE TABLE "AudienceCache" (
    "id" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "kind" TEXT NOT NULL,
    "scopeKey" TEXT NOT NULL,
    "content" JSONB NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "AudienceCache_pkey" PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX "AudienceCache_clientId_kind_scopeKey_key" ON "AudienceCache"("clientId", "kind", "scopeKey");
