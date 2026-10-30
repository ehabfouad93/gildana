import type { Metadata } from "next";
import { getLang } from "@/lib/i18n";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "Gildana Insights", template: "%s · Gildana Insights" },
  description: "Multi-channel ads, website analytics, SEO and GEO in one dashboard.",
};

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const lang = await getLang();
  return (
    <html lang={lang} dir={lang === "ar" ? "rtl" : "ltr"}>
      <body className="min-h-screen font-sans antialiased">{children}</body>
    </html>
  );
}
