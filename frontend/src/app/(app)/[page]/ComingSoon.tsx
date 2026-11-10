"use client";

import { Button } from "antd";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useI18n } from "@/i18n";
import { ALL_ITEMS, type PageId } from "@/lib/nav";

export default function ComingSoon({ id }: { id: PageId }) {
  const { t } = useI18n();
  const item = ALL_ITEMS.find((i) => i.id === id)!;
  const Icon = item.icon;
  return (
    <section className="mw-section mw-coming">
      <div className="mw-coming__icon">
        <Icon size={26} />
      </div>
      <h2 className="text-xl font-extrabold m-0">{t("coming.title", { page: t(item.label) })}</h2>
      <p className="mw-muted mt-2">{t("coming.body", { module: t(`module.${item.module ?? "tasks"}`) })}</p>
      <Link href="/dashboard/">
        <Button className="mt-2" icon={<ArrowLeft size={16} />}>
          {t("coming.back")}
        </Button>
      </Link>
    </section>
  );
}
