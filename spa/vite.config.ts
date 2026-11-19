import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { fileURLToPath, URL } from "node:url";
import { defineConfig } from "vite";

const src = (p: string) => fileURLToPath(new URL(`./src/${p}`, import.meta.url));

// Built into Laravel's public/app and loaded by resources/views/spa.blade.php via the manifest.
export default defineConfig({
  base: "/app/",
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: [
      { find: "@", replacement: src("") },
      // The UI was written for Next.js; these tiny shims keep the components unchanged.
      { find: "next/link", replacement: src("shims/next-link.tsx") },
      { find: "next/navigation", replacement: src("shims/next-navigation.ts") },
      { find: "next-themes", replacement: src("shims/next-themes.ts") },
    ],
  },
  build: {
    outDir: "../public/app",
    emptyOutDir: true,
    manifest: true,
    rollupOptions: { input: "src/main.tsx" },
  },
});
