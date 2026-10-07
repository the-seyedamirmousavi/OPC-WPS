import type { Metadata } from "next";
import { Geist, Geist_Mono, Vazirmatn } from "next/font/google";
import { cookies } from "next/headers";
import { Providers, type Lang } from "@/components/Providers";
import "./globals.css";

const vazir = Vazirmatn({ variable: "--font-vazir", subsets: ["arabic", "latin"] });
const geistSans = Geist({ variable: "--font-geist-sans", subsets: ["latin"] });
const geistMono = Geist_Mono({ variable: "--font-geist-mono", subsets: ["latin"] });

export const metadata: Metadata = {
  title: "AISO",
  description: "Operations dependency engine and task assignment (algorithm or LLM)",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  const jar = await cookies();
  const lang: Lang = jar.get("aiso_lang")?.value === "en" ? "en" : "fa";
  return (
    <html
      lang={lang}
      dir={lang === "fa" ? "rtl" : "ltr"}
      className={`${vazir.variable} ${geistSans.variable} ${geistMono.variable}`}
    >
      <body className="min-h-screen">
        <Providers initialLang={lang}>{children}</Providers>
      </body>
    </html>
  );
}
