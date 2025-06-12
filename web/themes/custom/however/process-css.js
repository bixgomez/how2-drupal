// process-css.js - Pure CSS + Tailwind v4 build system

// ── Patch module resolution so `require('tailwindcss')` always picks up
//    this folder's node_modules, even if you run npm from above.
const path = require("path");
process.env.NODE_PATH = path.join(__dirname, "node_modules");
require("module").Module._initPaths();

// ── Also make sure cwd is this file's folder (just in case)
process.chdir(path.dirname(__filename));

const fs = require("fs");
const postcss = require("postcss");
const glob = require("glob");
const chokidar = require("chokidar");
const { minify } = require("terser");

// Helper function: mkdir -p equivalent for ensuring directories exist
function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

// Define all our paths relative to this script's location
const projectRoot = __dirname;
const srcDir = path.join(projectRoot, "src");
const distDir = path.join(projectRoot, "dist");

// CSS input paths - we process multiple CSS sources:
const tailwindDirectives = `@import "tailwindcss";`; // Tailwind base
const customCssInput = path.join(srcDir, "css", "tailwind.css");
const componentCssGlob = path.join("components", "**", "*.css"); // Individual component styles
const srcBaseCssGlob = path.join("src", "css", "base", "**", "*.css");
const srcComponentCssGlob = path.join("src", "css", "components", "**", "*.css");

// CSS output paths
const distCssDir = path.join(distDir, "css");
const distCssFile = path.join(distCssDir, "styles.min.css");

// JS paths
const jsSrcDir = path.join(srcDir, "js");
const distJsDir = path.join(distDir, "js");

// Ensure output directories exist before we start building
ensureDir(distCssDir);
ensureDir(distJsDir);

// Load PostCSS plugins from our config file
const postcssConfig = require("./postcss.config.js");
const postcssPlugins = Object.entries(postcssConfig.plugins).map(
  ([pkg, opts]) => {
    const plugin = require(pkg);
    return typeof plugin === "function" ? plugin(opts) : plugin;
  }
);

/**
 * Process CSS by combining multiple sources into one minified file:
 * 1. Tailwind CSS (utilities + custom properties)
 * 2. Base CSS (from src/css/base/)
 * 3. Component CSS (from src/css/components/)
 * 4. Component folder CSS (from components/ directory)
 *
 * All sources get combined and run through PostCSS with Tailwind processing.
 */
async function processCSS() {
  try {
    console.log("▶️  Building CSS…");

    // 1) Read main Tailwind CSS file (includes @import "tailwindcss" + custom properties)
    const customCss = fs.readFileSync(customCssInput, "utf8");

    // 2) Collect base CSS files
    const srcBaseCss = glob
      .sync(srcBaseCssGlob)
      .map((f) => `/* —— ${f} —— */\n` + fs.readFileSync(f, "utf8"))
      .join("\n\n");

    // 3) Collect component CSS files from src/css/components/
    const srcCompCss = glob
      .sync(srcComponentCssGlob)
      .map((f) => `/* —— ${f} —— */\n` + fs.readFileSync(f, "utf8"))
      .join("\n\n");

    // 4) Collect component folder CSS files from components/
    const compCss = glob
      .sync(componentCssGlob)
      .map((f) => `/* —— ${f} —— */\n` + fs.readFileSync(f, "utf8"))
      .join("\n\n");

    // 5) Combine all CSS sources into one string
    //    Order matters: Tailwind base → Base styles → Component styles
    const combined = [
      tailwindDirectives,
      customCss,
      srcBaseCss,
      srcCompCss,
      compCss,
    ].join("\n\n");

    // 6) Run the combined CSS through PostCSS (primarily Tailwind processing)
    //    This processes @apply directives, adds vendor prefixes, etc.
    const result = await postcss(postcssPlugins).process(combined, {
      from: undefined,
      to: distCssFile,
    });

    // 7) Write the final minified CSS file
    fs.writeFileSync(distCssFile, result.css);
    console.log(
      `✅  CSS written to ${path.relative(projectRoot, distCssFile)}`
    );
  } catch (err) {
    console.error("‼️  CSS build error:", err);
  }
}

/**
 * Process JavaScript files by minifying them.
 * We handle two separate JS files:
 * - scripts.js (vanilla JavaScript)
 * - scripts-jquery.js (jQuery-dependent code)
 */
async function processJS() {
  try {
    console.log("▶️  Building JS…");

    // Process vanilla JavaScript file
    const jsIn = fs.readFileSync(path.join(jsSrcDir, "scripts.js"), "utf8");
    const jsMin = await minify(jsIn, {
      sourceMap: true,
      format: { comments: false }, // Remove comments for smaller file size
    });
    fs.writeFileSync(path.join(distJsDir, "scripts.min.js"), jsMin.code);

    // Process jQuery-dependent JavaScript file
    const jqIn = fs.readFileSync(
      path.join(jsSrcDir, "scripts-jquery.js"),
      "utf8"
    );
    const jqMin = await minify(jqIn, {
      sourceMap: true,
      format: { comments: false },
    });
    fs.writeFileSync(path.join(distJsDir, "scripts-jquery.min.js"), jqMin.code);

    console.log(`✅  JS written to ${path.relative(projectRoot, distDir)}/js`);
  } catch (err) {
    console.error("‼️  JS build error:", err);
  }
}

/**
 * Main build function that processes both CSS and JavaScript
 */
async function build() {
  await processCSS();
  await processJS();
  console.log("🏁  Build complete");
}

// Check if we're in watch mode (npm run watch or npm run dev)
if (process.argv.includes("--watch")) {
  console.log("👀  Watching for changes…");

  // Do an initial build
  build();

  // Watch for CSS-related file changes and rebuild CSS when they change
  chokidar
    .watch([
      "templates/**/*.twig", // Template changes might affect Tailwind class usage
      componentCssGlob, // Component CSS files
      srcBaseCssGlob, // Base CSS files
      srcComponentCssGlob, // Component CSS files in src/
      "tailwind.config.js", // Tailwind configuration changes
      "postcss.config.js", // PostCSS configuration changes
      customCssInput, // Main Tailwind file
      "src/css/**/*.css", // Watch all CSS files in src/css
    ])
    .on("change", () => processCSS());

  // Watch for JavaScript changes and rebuild JS when they change
  chokidar.watch("src/js/**/*.js").on("change", () => processJS());
} else {
  // Single build run (npm run build)
  build();
}