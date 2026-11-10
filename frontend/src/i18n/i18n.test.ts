import { describe, expect, it } from "vitest";
import en from "./en.json";
import zh from "./zh-CN.json";
import { format, translate } from "./index";

describe("dictionaries", () => {
  it("have exactly the same keys", () => {
    expect(Object.keys(zh).sort()).toEqual(Object.keys(en).sort());
  });

  it("have no empty translations", () => {
    for (const [key, text] of [...Object.entries(en), ...Object.entries(zh)]) {
      expect(text.trim(), key).not.toBe("");
    }
  });

  it("use the same placeholders in both languages", () => {
    const names = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
    for (const key of Object.keys(en) as (keyof typeof en)[]) {
      expect(names(zh[key]), key).toEqual(names(en[key]));
    }
  });
});

describe("format", () => {
  it("fills known placeholders and keeps unknown ones", () => {
    expect(format("Hi {name}, {seq} stays", { name: "Ellie" })).toBe("Hi Ellie, {seq} stays");
  });

  it("translates with variables", () => {
    expect(translate("zh-CN", "tfa.max", { n: 2 })).toBe("最多 2 台设备");
    expect(translate("en", "tfa.max", { n: 2 })).toBe("Maximum 2 devices");
  });
});
