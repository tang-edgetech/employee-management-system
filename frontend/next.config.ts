import type { NextConfig } from "next";
import pkg from "./package.json";

// Static export (ADR 0001): `next build` writes plain files to out/, which Apache serves
// next to the Laravel API. There is no Node process in production, so server-only
// features (route handlers, cacheComponents, image optimisation, middleware) are not used.
const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  // Shown on the sign-in page; the deploy script (M8) keeps it in step with the API version.
  env: {
    NEXT_PUBLIC_APP_VERSION: process.env.MERIDIAN_VERSION ?? pkg.version,
  },
  images: {
    unoptimized: true,
  },
  // Dev only: the app is opened through Apache at http://meridian.localhost, not localhost:3000.
  allowedDevOrigins: ["meridian.localhost"],
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
