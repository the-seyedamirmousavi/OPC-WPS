import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { fileURLToPath, URL } from "node:url";
import { defineConfig } from "vite";

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
    },
  },
  // public/ is served by Laravel (index.php lives there), not copied by Vite
  publicDir: false,
  build: {
    outDir: "public/build",
    emptyOutDir: true,
    // Laravel's @vite() helper reads public/build/manifest.json
    manifest: "manifest.json",
    rollupOptions: {
      input: ["resources/js/main.tsx"],
    },
  },
});
