"use client";

// Collects Ant Design's CSS-in-JS during the static build so pages arrive styled
// (no flash), and puts it in the "antd" cascade layer declared in globals.css.

import { createCache, extractStyle, StyleProvider } from "@ant-design/cssinjs";
import { useServerInsertedHTML } from "next/navigation";
import { useRef, useState } from "react";

export default function AntdRegistry({ children }: { children: React.ReactNode }) {
  const [cache] = useState(() => createCache());
  const inserted = useRef(false);

  useServerInsertedHTML(() => {
    if (inserted.current) return null;
    inserted.current = true;
    return <style id="antd" dangerouslySetInnerHTML={{ __html: extractStyle(cache, true) }} />;
  });

  return (
    <StyleProvider cache={cache} layer>
      {children}
    </StyleProvider>
  );
}
