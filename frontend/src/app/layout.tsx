import type { Metadata } from "next";
import { IBM_Plex_Mono, Plus_Jakarta_Sans } from "next/font/google";
import "./globals.css";
import Providers from "./providers";

// Fonts are downloaded at build time and served with the app (no request to Google).
const jakarta = Plus_Jakarta_Sans({
  variable: "--font-jakarta",
  subsets: ["latin"],
  weight: ["400", "500", "600", "700", "800"],
});
const plexMono = IBM_Plex_Mono({
  variable: "--font-plex-mono",
  subsets: ["latin"],
  weight: ["400", "500", "600"],
});

export const metadata: Metadata = {
  title: "Meridian Workforce",
  description: "Hire, run projects and score KPIs in one place.",
};

// The sidebar state is applied before first paint so the rail doesn't flicker open.
const sidebarScript = `try{var s=localStorage.getItem("mw_sb");document.documentElement.dataset.sb=s==="rail"?"rail":"open"}catch(e){document.documentElement.dataset.sb="open"}`;

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" data-sb="open" className={`${jakarta.variable} ${plexMono.variable}`} suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: sidebarScript }} />
      </head>
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
