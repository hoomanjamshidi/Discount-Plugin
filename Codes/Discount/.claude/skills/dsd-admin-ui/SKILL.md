---
name: dsd-admin-ui
description: "Use when changing the Smart Bulk Discount admin screen (admin-app/: React 19 + Tailwind v4 + Tarhak/FarsiUI components): wizard steps, campaign pages, product picker basket, Jalali date fields, CSS scoping inside wp-admin, building into smart-bulk-discount/build."
---

# Smart Bulk Discount — admin UI

## Stack

- React 19 + TypeScript, Vite 8 **library build → one IIFE** (`build/index.js`) + one CSS (`build/index.css`).
- Tailwind v4, initialised once with `npx farsiui@latest init` (farsiui itself is not a dependency).
- Components from Tarhak (`npx @tarhak/cli@latest add <slug>`; on Windows use
  `npx farsiui@latest add @tarhak/<slug> -y -s` because the Tarhak CLI cannot spawn npx there).
  Installed: date-field (Jalali), discrete-tabs, animated-switches, toast-stack, save-button,
  delete-button, number-ticker, status-mark, glide-select, tooltip, slosh-gauge,
  animated-spark-chart, animated-checkboxes. Demo-only components were adapted to props — keep
  the Tarhak header comment when editing them.
- Font: Vazirmatn (bundled via @fontsource, inlined). No external requests from wp-admin.

## Rules

1. **CSS scoping is mandatory.** `npm run build` runs `scripts/scope-css.mjs`, which removes `@layer`
   and prefixes every selector with `#dsd-root`. Never ship CSS that skipped this step: unlayered
   wp-admin CSS would otherwise beat Tailwind, and preflight would reset all of wp-admin.
2. RTL everywhere (`dir="rtl"` on the root). Use logical utilities (`ps-*`, `pe-*`, `start/end`).
3. Copy is Persian and plain: say what happens to the shop ("قیمت‌ها برمی‌گردند"), never WP jargon.
4. Dates: the UI sends site-local wall-clock strings `YYYY-MM-DD HH:mm`; the server converts with the
   site timezone. Display with `jalali()` from `src/lib/format.ts`. Never send browser timestamps.
5. The product "basket" (`src/lib/basket.ts`) lives above the search UI and in localStorage — searching
   or paging must never clear it.
6. Destructive actions use the countdown `DeleteButton` (forgiving undo); async actions use `SaveButton`.
7. Long work is never awaited in the UI: start it, then poll (`campaigns/{id}` every 2.5s while working)
   and POST `/tick` every 8s (the server decides whether to run).

## Commands

```bash
cd admin-app
npm run typecheck
npm test            # vitest: Jalali formatting, digit parsing, wizard validation
npm run build       # → ../smart-bulk-discount/build (scoped)
```

## Verify

Open http://localhost:8088/wp-admin/admin.php?page=smart-bulk-discount (admin/admin) after
`bash docker/setup.sh`; check the WP admin menu/sidebar still looks normal (scoping) and that the
wizard works at 1280px and ~800px widths.
