import type { NextConfig } from "next";

// Static export (ADR 0001): `next build` writes plain files to out/, which Apache serves
// next to the Laravel API. There is no Node process in production, so server-only
// features (route handlers, cacheComponents, image optimisation, middleware) are not used.
const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
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
