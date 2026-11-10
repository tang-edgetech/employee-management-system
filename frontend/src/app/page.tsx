"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

// "/" goes to the dashboard; the app layout sends signed-out visitors to the sign-in page.
export default function Home() {
  const router = useRouter();
  useEffect(() => router.replace("/dashboard/"), [router]);
  return null;
}
