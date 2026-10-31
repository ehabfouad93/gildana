import type { Platform } from "@prisma/client";

/**
 * What Gildana sets up once per platform so every client can connect with a
 * click, and how a client shares access instead of logging in. Shown on
 * Admin → Platforms and in the client's connect screens.
 */

export type Field = { key: string; label: string; secret?: boolean; placeholder?: string; help?: string };

export type PlatformSetup = {
  portal: { label: string; url: string };
  fields: Field[];
  /** Keys that must be set for the Connect button to go live. */
  required: string[];
  steps: string[];
  /** How a client gives Gildana access without logging in here. {id} is replaced by the agency id below. */
  share?: { idKey: string; idLabel: string; steps: string[]; accountIdHelp: string };
};

export const PLATFORM_SETUP: Record<Platform, PlatformSetup> = {
  META: {
    portal: { label: "Meta for Developers", url: "https://developers.facebook.com/apps" },
    fields: [
      { key: "META_APP_ID", label: "App ID" },
      { key: "META_APP_SECRET", label: "App secret", secret: true },
      { key: "META_LOGIN_CONFIG_ID", label: "Login configuration ID", help: "Facebook Login for Business → Configurations. Required for Business-type apps." },
      { key: "META_LOGIN_CONFIG_ID_WRITE", label: "Split-test configuration ID (optional)", help: "A second configuration that also includes ads_management." },
      { key: "AGENCY_META_BUSINESS_ID", label: "Gildana Business Manager ID", help: "Clients share ad accounts with this Business ID." },
    ],
    required: ["META_APP_ID", "META_APP_SECRET"],
    steps: [
      "Create an app: My Apps → Create app → use case “Other” → type “Business” → link Gildana’s Business Manager.",
      "Add the products “Facebook Login for Business” and “Marketing API”.",
      "Facebook Login for Business → Settings: turn on Client OAuth login, Web OAuth login and Enforce HTTPS; paste the redirect URI below into “Valid OAuth Redirect URIs”; Save.",
      "Facebook Login for Business → Configurations → Create: login variation “General”, token “User access token”, assets Ad accounts + Pages + Business, permissions ads_read, business_management, leads_retrieval, pages_show_list, pages_read_engagement. Copy its Configuration ID here.",
      "App settings → Basic: App domain = your Insights domain, add a privacy policy URL, then copy the App ID and App secret here.",
      "Business Settings → Security Center: start Business Verification.",
      "App Review → request Advanced Access for ads_read, business_management, leads_retrieval, pages_show_list, pages_read_engagement (ads_management only if you use split tests). Then switch the app to Live — after that any client can connect.",
      "Until review is approved, people with a role on the app (admins, developers, testers) can already connect.",
    ],
    share: {
      idKey: "AGENCY_META_BUSINESS_ID", idLabel: "Gildana Business ID",
      steps: [
        "Open Meta Business Settings (business.facebook.com/settings) → Accounts → Ad accounts.",
        "Select the ad account → “Assign partners” → enter Business ID {id} → give “View performance” (or “Manage campaigns”) → Save.",
        "Copy the ad account ID (shown under its name, e.g. 123456789) and request it below.",
      ],
      accountIdHelp: "Ad account ID, e.g. 123456789 or act_123456789",
    },
  },
  GOOGLE: {
    portal: { label: "Google Cloud Console", url: "https://console.cloud.google.com/apis/credentials" },
    fields: [
      { key: "GOOGLE_ADS_CLIENT_ID", label: "OAuth client ID" },
      { key: "GOOGLE_ADS_CLIENT_SECRET", label: "OAuth client secret", secret: true },
      { key: "GOOGLE_ADS_DEVELOPER_TOKEN", label: "Developer token", secret: true, help: "Google Ads manager account → Tools → API Center." },
      { key: "GOOGLE_ADS_LOGIN_CUSTOMER_ID", label: "Gildana manager (MCC) ID", placeholder: "1234567890", help: "Digits only. Clients link their accounts to this manager account." },
    ],
    required: ["GOOGLE_ADS_CLIENT_ID", "GOOGLE_ADS_CLIENT_SECRET", "GOOGLE_ADS_DEVELOPER_TOKEN"],
    steps: [
      "Google Cloud Console: create a project → APIs & Services → enable “Google Ads API” (and “Google Search Console API” for SEO).",
      "OAuth consent screen: External, app name Gildana Insights, add your domain; add the scopes adwords and webmasters.readonly; publish it (Google verifies apps that use the adwords scope).",
      "Credentials → Create OAuth client ID → Web application → add both redirect URIs below → copy the client ID and secret here.",
      "Google Ads manager (MCC) account → Tools → API Center: copy the developer token and apply for Basic access (a new token works only on test accounts until approved).",
    ],
    share: {
      idKey: "GOOGLE_ADS_LOGIN_CUSTOMER_ID", idLabel: "Gildana manager account ID",
      steps: [
        "Gildana sends a link request from its manager account {id} — or the client asks for one.",
        "In Google Ads → Admin → Access and security → Managers, accept the request from {id}.",
        "Copy the 10-digit customer ID (top right in Google Ads, e.g. 123-456-7890) and request it below.",
      ],
      accountIdHelp: "Customer ID, e.g. 123-456-7890",
    },
  },
  LINKEDIN: {
    portal: { label: "LinkedIn Developers", url: "https://www.linkedin.com/developers/apps" },
    fields: [
      { key: "LINKEDIN_CLIENT_ID", label: "Client ID" },
      { key: "LINKEDIN_CLIENT_SECRET", label: "Client secret", secret: true },
      { key: "AGENCY_LINKEDIN_MEMBER", label: "Gildana LinkedIn member email", help: "Clients add this person to their ad account." },
    ],
    required: ["LINKEDIN_CLIENT_ID", "LINKEDIN_CLIENT_SECRET"],
    steps: [
      "Create an app linked to Gildana’s LinkedIn company page.",
      "Products → request “Advertising API” (Marketing Developer Platform). LinkedIn reviews it.",
      "Auth → add the redirect URI below; copy the client ID and secret here.",
    ],
    share: {
      idKey: "AGENCY_LINKEDIN_MEMBER", idLabel: "Gildana LinkedIn member",
      steps: ["Campaign Manager → the ad account → Account settings → Manage access → add {id} as Viewer.", "Copy the account ID (in the Campaign Manager URL) and request it below."],
      accountIdHelp: "Ad account ID, e.g. 512345678",
    },
  },
  SNAPCHAT: {
    portal: { label: "Snapchat Business", url: "https://business.snapchat.com" },
    fields: [
      { key: "SNAPCHAT_CLIENT_ID", label: "Client ID" },
      { key: "SNAPCHAT_CLIENT_SECRET", label: "Client secret", secret: true },
      { key: "AGENCY_SNAPCHAT_MEMBER", label: "Gildana Snapchat member email" },
    ],
    required: ["SNAPCHAT_CLIENT_ID", "SNAPCHAT_CLIENT_SECRET"],
    steps: ["Business Manager → Business Details → OAuth Apps → create an app for the Marketing API.", "Add the redirect URI below; copy the client ID and secret here."],
    share: {
      idKey: "AGENCY_SNAPCHAT_MEMBER", idLabel: "Gildana Snapchat member",
      steps: ["Snapchat Ads Manager → Members → invite {id} and give access to the ad account.", "Copy the ad account ID and request it below."],
      accountIdHelp: "Ad account ID (from Ads Manager)",
    },
  },
  TIKTOK: {
    portal: { label: "TikTok for Business Developers", url: "https://business-api.tiktok.com/portal/apps" },
    fields: [
      { key: "TIKTOK_APP_ID", label: "App ID" },
      { key: "TIKTOK_APP_SECRET", label: "App secret", secret: true },
      { key: "AGENCY_TIKTOK_BC_ID", label: "Gildana Business Center ID" },
    ],
    required: ["TIKTOK_APP_ID", "TIKTOK_APP_SECRET"],
    steps: ["Create a Marketing API app and request the Ads Management / Reporting scopes.", "Set the advertiser redirect URL below; after approval copy the App ID and secret here."],
    share: {
      idKey: "AGENCY_TIKTOK_BC_ID", idLabel: "Gildana Business Center ID",
      steps: ["TikTok Business Center → Partners → add partner {id} → share the advertiser account with “Analyst” access.", "Copy the advertiser ID and request it below."],
      accountIdHelp: "Advertiser ID, e.g. 7012345678901234567",
    },
  },
};

export const GENERAL_FIELDS: Field[] = [
  { key: "SMTP_URL", label: "Email server (SMTP URL)", secret: true, placeholder: "smtp://noreply%40gildana.net:PASSWORD@smtp.hostinger.com:587", help: "For password resets and invitations. Write @ in the username as %40." },
  { key: "MAIL_FROM", label: "Send emails as", placeholder: "Gildana Insights <noreply@gildana.net>" },
  { key: "ANTHROPIC_API_KEY", label: "Claude API key", secret: true, help: "GEO reviews and AI-visibility checks." },
  { key: "PAGESPEED_API_KEY", label: "PageSpeed Insights API key", secret: true, help: "Optional — higher quota for Core Web Vitals." },
];

export const SETTING_KEYS: string[] = [
  ...Object.values(PLATFORM_SETUP).flatMap((p) => p.fields.map((f) => f.key)),
  ...GENERAL_FIELDS.map((f) => f.key),
  "CLAIMS_AUTO_APPROVE",
];
