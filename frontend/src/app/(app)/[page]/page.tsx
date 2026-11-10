import { notFound } from "next/navigation";
import { COMING_SOON } from "@/lib/nav";
import ComingSoon from "./ComingSoon";

// Menu items whose module isn't built yet ("Coming in <module>", D3). Static export:
// one HTML file per placeholder, nothing else.
export const dynamicParams = false;

export function generateStaticParams() {
  return COMING_SOON.map(({ slug }) => ({ page: slug }));
}

export default async function Page({ params }: PageProps<"/[page]">) {
  const { page } = await params;
  const entry = COMING_SOON.find((c) => c.slug === page);
  if (!entry) notFound();
  return <ComingSoon id={entry.item.id} />;
}
