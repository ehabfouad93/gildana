import "server-only";
import { cookies } from "next/headers";

export type Lang = "en" | "ar";

const en = {
  overview: "Overview", spend: "Spend", performance: "Performance", campaigns: "Campaigns", audiences: "Audiences",
  google: "Google Search", connections: "Ad accounts", reports: "Reports", shares: "Shared links", sites: "Websites",
  companies: "Companies", admin: "Admin", clients: "Clients", health: "Sync health", settings: "Settings", logout: "Sign out",
  allCompanies: "All companies", allPlatforms: "All platforms", apply: "Apply", last: "Last", days: "days",
  spendLabel: "Spend", impressions: "Impressions", reach: "Reach", clicks: "Clicks", ctr: "CTR", cpc: "CPC", cpm: "CPM",
  conversions: "Conversions", leads: "Leads", cpl: "Cost / lead", cpa: "Cost / conv.", roas: "ROAS", value: "Conv. value",
  frequency: "Frequency", vsPrev: "vs previous period", noData: "No data for this range yet.",
  byPlatform: "By platform", byCompany: "By company", byAccount: "By ad account", trend: "Trend", topCampaigns: "Top campaigns",
  company: "Company", platform: "Platform", account: "Account", campaign: "Campaign", status: "Status", objective: "Objective",
};

const ar: typeof en = {
  overview: "نظرة عامة", spend: "الإنفاق", performance: "الأداء", campaigns: "الحملات", audiences: "الجماهير",
  google: "بحث جوجل", connections: "الحسابات الإعلانية", reports: "التقارير", shares: "الروابط المشتركة", sites: "المواقع",
  companies: "الشركات", admin: "الإدارة", clients: "العملاء", health: "حالة المزامنة", settings: "الإعدادات", logout: "تسجيل الخروج",
  allCompanies: "كل الشركات", allPlatforms: "كل المنصات", apply: "تطبيق", last: "آخر", days: "يوم",
  spendLabel: "الإنفاق", impressions: "مرات الظهور", reach: "الوصول", clicks: "النقرات", ctr: "نسبة النقر", cpc: "تكلفة النقرة", cpm: "تكلفة الألف ظهور",
  conversions: "التحويلات", leads: "العملاء المحتملون", cpl: "تكلفة العميل", cpa: "تكلفة التحويل", roas: "العائد على الإنفاق", value: "قيمة التحويلات",
  frequency: "معدل التكرار", vsPrev: "مقارنة بالفترة السابقة", noData: "لا توجد بيانات لهذه الفترة بعد.",
  byPlatform: "حسب المنصة", byCompany: "حسب الشركة", byAccount: "حسب الحساب", trend: "الاتجاه", topCampaigns: "أفضل الحملات",
  company: "الشركة", platform: "المنصة", account: "الحساب", campaign: "الحملة", status: "الحالة", objective: "الهدف",
};

export type Dict = typeof en;

export async function getLang(): Promise<Lang> {
  return (await cookies()).get("lang")?.value === "ar" ? "ar" : "en";
}

export async function getDict(): Promise<Dict> {
  return (await getLang()) === "ar" ? ar : en;
}
