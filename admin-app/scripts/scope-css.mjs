// Post-build: make the compiled Tailwind CSS safe inside wp-admin.
//
// 1. Unwrap @layer blocks. wp-admin's CSS is unlayered, and unlayered rules beat
//    every layered rule regardless of specificity — so layered Tailwind utilities
//    would lose to rules like `p { font-size: 13px }` or `input[type=text] {...}`.
// 2. Prefix every selector with #dsd-root (and map :root/html/body/:host to it), so
//    the preflight reset and theme variables never touch the rest of wp-admin, and our
//    utilities out-rank wp-admin element/attribute selectors.
//
// Usage: node scripts/scope-css.mjs ../smart-bulk-discount/build/index.css
import fs from "node:fs";
import postcss from "postcss";

const SCOPE = "#dsd-root";
const file = process.argv[2];
if (!file) {
  console.error("usage: scope-css.mjs <file.css>");
  process.exit(1);
}

function scopeSelector(sel) {
  const s = sel.trim();
  if (!s) return s;
  if (s.startsWith(SCOPE)) return s;
  // Whole-document selectors become the app root.
  const replaced = s.replace(/^(:root|html|body|:host)(?=$|[\s.:[>+~])/, SCOPE);
  if (replaced !== s) return replaced;
  return `${SCOPE} ${s}`;
}

const plugin = {
  postcssPlugin: "dsd-scope",
  Once(root) {
    // Unwrap layers (repeat until none are left; layers can nest).
    let found = true;
    while (found) {
      found = false;
      root.walkAtRules("layer", (at) => {
        found = true;
        if (at.nodes && at.nodes.length) at.replaceWith(at.nodes);
        else at.remove(); // `@layer a, b;` order statements
      });
    }
    root.walkRules((rule) => {
      // Skip keyframe steps (from/to/50%).
      if (rule.parent && rule.parent.type === "atrule" && /keyframes$/.test(rule.parent.name)) return;
      rule.selectors = rule.selectors.map(scopeSelector);
    });
  },
};

const css = fs.readFileSync(file, "utf8");
const out = await postcss([plugin]).process(css, { from: file, to: file });
fs.writeFileSync(file, out.css);
const kb = (Buffer.byteLength(out.css) / 1024).toFixed(1);
console.log(`scoped ${file} (${kb} KB)`);
