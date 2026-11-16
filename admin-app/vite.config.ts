import path from "node:path";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

// Builds one IIFE bundle + one CSS file straight into the plugin:
//   smart-bulk-discount/build/index.js, index.css, fonts/*
// (WordPress enqueues a classic script, so no ES modules / code splitting.)
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { "@": path.resolve(__dirname, "./src") } },
  base: "./",
  define: { "process.env.NODE_ENV": JSON.stringify("production") },
  build: {
    outDir: path.resolve(__dirname, "../smart-bulk-discount/build"),
    emptyOutDir: true,
    assetsInlineLimit: 0,
    cssCodeSplit: false,
    lib: {
      entry: path.resolve(__dirname, "src/main.tsx"),
      formats: ["iife"],
      name: "DSDAdmin",
      fileName: () => "index.js",
      cssFileName: "index",
    },
    rollupOptions: {
      output: {
        assetFileNames: (info) => {
          const name = info.names?.[0] ?? info.name ?? "";
          if (/\.(woff2?|ttf|otf)$/.test(name)) return "fonts/[name][extname]";
          if (name.endsWith(".css")) return "index.css";
          return "assets/[name][extname]";
        },
      },
    },
  },
});
