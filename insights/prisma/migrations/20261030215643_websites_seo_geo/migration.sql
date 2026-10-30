-- CreateTable
CREATE TABLE "Site" (
    "id" TEXT NOT NULL,
    "companyId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "domain" TEXT NOT NULL,
    "trackingKey" TEXT NOT NULL,
    "replay" BOOLEAN NOT NULL DEFAULT false,
    "replayRate" INTEGER NOT NULL DEFAULT 20,
    "isDemo" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "Site_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "TrackSession" (
    "id" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "visitorId" TEXT NOT NULL,
    "isNew" BOOLEAN NOT NULL DEFAULT true,
    "startedAt" TIMESTAMP(3) NOT NULL,
    "lastSeenAt" TIMESTAMP(3) NOT NULL,
    "landingPath" TEXT NOT NULL DEFAULT '',
    "exitPath" TEXT NOT NULL DEFAULT '',
    "referrer" TEXT NOT NULL DEFAULT '',
    "refHost" TEXT NOT NULL DEFAULT '',
    "utmSource" TEXT NOT NULL DEFAULT '',
    "utmMedium" TEXT NOT NULL DEFAULT '',
    "utmCampaign" TEXT NOT NULL DEFAULT '',
    "utmContent" TEXT NOT NULL DEFAULT '',
    "utmTerm" TEXT NOT NULL DEFAULT '',
    "clickIdType" TEXT NOT NULL DEFAULT '',
    "channel" TEXT NOT NULL DEFAULT 'Direct',
    "device" TEXT NOT NULL DEFAULT 'desktop',
    "browser" TEXT NOT NULL DEFAULT '',
    "os" TEXT NOT NULL DEFAULT '',
    "country" TEXT NOT NULL DEFAULT '',
    "city" TEXT NOT NULL DEFAULT '',
    "pageviews" INTEGER NOT NULL DEFAULT 0,
    "events" INTEGER NOT NULL DEFAULT 0,
    "durationSec" INTEGER NOT NULL DEFAULT 0,
    "maxScroll" INTEGER NOT NULL DEFAULT 0,
    "converted" BOOLEAN NOT NULL DEFAULT false,
    "hasReplay" BOOLEAN NOT NULL DEFAULT false,

    CONSTRAINT "TrackSession_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "TrackPageview" (
    "id" TEXT NOT NULL,
    "sessionId" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "path" TEXT NOT NULL,
    "title" TEXT NOT NULL DEFAULT '',
    "ts" TIMESTAMP(3) NOT NULL,
    "activeSec" INTEGER NOT NULL DEFAULT 0,
    "scrollPct" INTEGER NOT NULL DEFAULT 0,
    "viewportW" INTEGER NOT NULL DEFAULT 0,
    "viewportH" INTEGER NOT NULL DEFAULT 0,
    "docH" INTEGER NOT NULL DEFAULT 0,
    "device" TEXT NOT NULL DEFAULT 'desktop',
    "pvKey" TEXT NOT NULL DEFAULT '',

    CONSTRAINT "TrackPageview_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "TrackEvent" (
    "id" TEXT NOT NULL,
    "sessionId" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "path" TEXT NOT NULL,
    "type" TEXT NOT NULL,
    "name" TEXT NOT NULL DEFAULT '',
    "value" DOUBLE PRECISION,
    "ts" TIMESTAMP(3) NOT NULL,
    "xPct" DOUBLE PRECISION,
    "yPx" INTEGER,
    "docW" INTEGER,
    "docH" INTEGER,
    "selector" TEXT NOT NULL DEFAULT '',
    "text" TEXT NOT NULL DEFAULT '',
    "device" TEXT NOT NULL DEFAULT 'desktop',

    CONSTRAINT "TrackEvent_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "TrackReplay" (
    "id" TEXT NOT NULL,
    "sessionId" TEXT NOT NULL,
    "seq" INTEGER NOT NULL,
    "ts" TIMESTAMP(3) NOT NULL,
    "data" BYTEA NOT NULL,

    CONSTRAINT "TrackReplay_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Goal" (
    "id" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "kind" TEXT NOT NULL,
    "match" TEXT NOT NULL,

    CONSTRAINT "Goal_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "PageSnapshot" (
    "id" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "path" TEXT NOT NULL,
    "device" TEXT NOT NULL,
    "width" INTEGER NOT NULL,
    "height" INTEGER NOT NULL,
    "image" BYTEA NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "PageSnapshot_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "SeoCrawl" (
    "id" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'queued',
    "maxPages" INTEGER NOT NULL DEFAULT 200,
    "pagesDone" INTEGER NOT NULL DEFAULT 0,
    "score" INTEGER,
    "summary" JSONB,
    "error" TEXT NOT NULL DEFAULT '',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "finishedAt" TIMESTAMP(3),

    CONSTRAINT "SeoCrawl_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "SeoPage" (
    "id" TEXT NOT NULL,
    "crawlId" TEXT NOT NULL,
    "url" TEXT NOT NULL,
    "status" INTEGER NOT NULL,
    "redirectTo" TEXT NOT NULL DEFAULT '',
    "ms" INTEGER NOT NULL DEFAULT 0,
    "bytes" INTEGER NOT NULL DEFAULT 0,
    "title" TEXT NOT NULL DEFAULT '',
    "description" TEXT NOT NULL DEFAULT '',
    "h1" TEXT NOT NULL DEFAULT '',
    "h1Count" INTEGER NOT NULL DEFAULT 0,
    "canonical" TEXT NOT NULL DEFAULT '',
    "robots" TEXT NOT NULL DEFAULT '',
    "lang" TEXT NOT NULL DEFAULT '',
    "wordCount" INTEGER NOT NULL DEFAULT 0,
    "internalLinks" INTEGER NOT NULL DEFAULT 0,
    "externalLinks" INTEGER NOT NULL DEFAULT 0,
    "images" INTEGER NOT NULL DEFAULT 0,
    "imagesNoAlt" INTEGER NOT NULL DEFAULT 0,
    "schemaTypes" TEXT NOT NULL DEFAULT '',
    "hreflang" TEXT NOT NULL DEFAULT '',
    "ogTitle" TEXT NOT NULL DEFAULT '',
    "depth" INTEGER NOT NULL DEFAULT 0,

    CONSTRAINT "SeoPage_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "SeoIssue" (
    "id" TEXT NOT NULL,
    "crawlId" TEXT NOT NULL,
    "url" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "severity" TEXT NOT NULL,
    "detail" TEXT NOT NULL DEFAULT '',

    CONSTRAINT "SeoIssue_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "GeoAudit" (
    "id" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'queued',
    "score" INTEGER,
    "checks" JSONB,
    "aiReview" TEXT NOT NULL DEFAULT '',
    "error" TEXT NOT NULL DEFAULT '',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "finishedAt" TIMESTAMP(3),

    CONSTRAINT "GeoAudit_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "AiPrompt" (
    "id" TEXT NOT NULL,
    "siteId" TEXT NOT NULL,
    "prompt" TEXT NOT NULL,
    "brand" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "AiPrompt_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "AiVisibility" (
    "id" TEXT NOT NULL,
    "promptId" TEXT NOT NULL,
    "engine" TEXT NOT NULL,
    "mentioned" BOOLEAN NOT NULL,
    "position" INTEGER,
    "cited" BOOLEAN NOT NULL DEFAULT false,
    "competitors" TEXT NOT NULL DEFAULT '',
    "answer" TEXT NOT NULL,
    "checkedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "AiVisibility_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "Site_trackingKey_key" ON "Site"("trackingKey");

-- CreateIndex
CREATE INDEX "Site_companyId_idx" ON "Site"("companyId");

-- CreateIndex
CREATE INDEX "TrackSession_siteId_startedAt_idx" ON "TrackSession"("siteId", "startedAt");

-- CreateIndex
CREATE INDEX "TrackSession_siteId_visitorId_idx" ON "TrackSession"("siteId", "visitorId");

-- CreateIndex
CREATE INDEX "TrackPageview_siteId_ts_idx" ON "TrackPageview"("siteId", "ts");

-- CreateIndex
CREATE INDEX "TrackPageview_siteId_path_ts_idx" ON "TrackPageview"("siteId", "path", "ts");

-- CreateIndex
CREATE INDEX "TrackPageview_sessionId_pvKey_idx" ON "TrackPageview"("sessionId", "pvKey");

-- CreateIndex
CREATE INDEX "TrackEvent_siteId_path_type_ts_idx" ON "TrackEvent"("siteId", "path", "type", "ts");

-- CreateIndex
CREATE INDEX "TrackEvent_siteId_type_ts_idx" ON "TrackEvent"("siteId", "type", "ts");

-- CreateIndex
CREATE UNIQUE INDEX "TrackReplay_sessionId_seq_key" ON "TrackReplay"("sessionId", "seq");

-- CreateIndex
CREATE INDEX "Goal_siteId_idx" ON "Goal"("siteId");

-- CreateIndex
CREATE UNIQUE INDEX "PageSnapshot_siteId_path_device_key" ON "PageSnapshot"("siteId", "path", "device");

-- CreateIndex
CREATE INDEX "SeoCrawl_siteId_createdAt_idx" ON "SeoCrawl"("siteId", "createdAt");

-- CreateIndex
CREATE INDEX "SeoPage_crawlId_idx" ON "SeoPage"("crawlId");

-- CreateIndex
CREATE INDEX "SeoIssue_crawlId_code_idx" ON "SeoIssue"("crawlId", "code");

-- CreateIndex
CREATE INDEX "GeoAudit_siteId_createdAt_idx" ON "GeoAudit"("siteId", "createdAt");

-- CreateIndex
CREATE INDEX "AiVisibility_promptId_checkedAt_idx" ON "AiVisibility"("promptId", "checkedAt");

-- AddForeignKey
ALTER TABLE "Site" ADD CONSTRAINT "Site_companyId_fkey" FOREIGN KEY ("companyId") REFERENCES "Company"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "TrackSession" ADD CONSTRAINT "TrackSession_siteId_fkey" FOREIGN KEY ("siteId") REFERENCES "Site"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "TrackPageview" ADD CONSTRAINT "TrackPageview_sessionId_fkey" FOREIGN KEY ("sessionId") REFERENCES "TrackSession"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "TrackEvent" ADD CONSTRAINT "TrackEvent_sessionId_fkey" FOREIGN KEY ("sessionId") REFERENCES "TrackSession"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "TrackReplay" ADD CONSTRAINT "TrackReplay_sessionId_fkey" FOREIGN KEY ("sessionId") REFERENCES "TrackSession"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Goal" ADD CONSTRAINT "Goal_siteId_fkey" FOREIGN KEY ("siteId") REFERENCES "Site"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "PageSnapshot" ADD CONSTRAINT "PageSnapshot_siteId_fkey" FOREIGN KEY ("siteId") REFERENCES "Site"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "SeoCrawl" ADD CONSTRAINT "SeoCrawl_siteId_fkey" FOREIGN KEY ("siteId") REFERENCES "Site"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "SeoPage" ADD CONSTRAINT "SeoPage_crawlId_fkey" FOREIGN KEY ("crawlId") REFERENCES "SeoCrawl"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "SeoIssue" ADD CONSTRAINT "SeoIssue_crawlId_fkey" FOREIGN KEY ("crawlId") REFERENCES "SeoCrawl"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "GeoAudit" ADD CONSTRAINT "GeoAudit_siteId_fkey" FOREIGN KEY ("siteId") REFERENCES "Site"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "AiPrompt" ADD CONSTRAINT "AiPrompt_siteId_fkey" FOREIGN KEY ("siteId") REFERENCES "Site"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "AiVisibility" ADD CONSTRAINT "AiVisibility_promptId_fkey" FOREIGN KEY ("promptId") REFERENCES "AiPrompt"("id") ON DELETE CASCADE ON UPDATE CASCADE;
