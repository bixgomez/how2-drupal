// process-css.js

// ── Patch module resolution so `require('tailwindcss')` always picks up
//    this folder's node_modules, even if you run npm from above.
const path = require("path");
process.env.NODE_PATH = path.join(__dirname, "node_modules");
require("module").Module._initPaths();

// ── Also make sure cwd is this file’s folder (just in case)
process.chdir(path.dirname(__filename));

const fs = require("fs");
const postcss = require("postcss");
const sass = require("sass");
const glob = require("glob");
const chokidar = require("chokidar");
const { minify } = require("terser");

// Helper: mkdir -p
function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

const projectRoot = __dirname;
const srcDir = path.join(projectRoot, "src");
const distDir = path.join(projectRoot, "dist");

// CSS paths
const tailwindInput = path.join(srcDir, "css", "tailwind.css");
const scssEntry = path.join(srcDir, "scss", "styles.scss");
const componentCssGlob = path.join("components", "**", "*.css");
const distCssDir = path.join(distDir, "css");
const distCssFile = path.join(distCssDir, "styles.min.css");

// JS paths
const jsSrcDir = path.join(srcDir, "js");
const distJsDir = path.join(distDir, "js");

// ensure output dirs exist
ensureDir(distCssDir);
ensureDir(distJsDir);

// if tailwind.css doesn’t exist, stub it
if (!fs.existsSync(tailwindInput)) {
  fs.writeFileSync(tailwindInput, '@import "tailwindcss";\n');
}

// load PostCSS plugins
const postcssConfig = require("./postcss.config.js");
const postcssPlugins = Object.entries(postcssConfig.plugins).map(
  ([pkg, opts]) => {
    const plugin = require(pkg);
    return typeof plugin === "function" ? plugin(opts) : plugin;
  }
);

async function processCSS() {
  try {
    console.log("▶️  Building CSS…");

    // 1) Tailwind import + any @apply you’ve added there
    const twCss = fs.readFileSync(tailwindInput, "utf8");

    // 2) Raw component CSS
    const compCss = glob
      .sync(componentCssGlob)
      .map((f) => `/* —— ${f} —— */\n` + fs.readFileSync(f, "utf8"))
      .join("\n\n");

    // 3) Compile Sass
    const sassResult = sass.compile(scssEntry, {
      style: "compressed",
      sourceMap: true,
      loadPaths: ["node_modules"],
    });

    // 4) One PostCSS pass over everything
    const combined = [twCss, compCss, sassResult.css].join("\n\n");
    const result = await postcss(postcssPlugins).process(combined, {
      from: undefined,
      to: distCssFile,
    });

    fs.writeFileSync(distCssFile, result.css);
    console.log(
      `✅  CSS written to ${path.relative(projectRoot, distCssFile)}`
    );
  } catch (err) {
    console.error("‼️  CSS build error:", err);
  }
}

async function processJS() {
  try {
    console.log("▶️  Building JS…");

    // scripts.js → scripts.min.js
    const jsIn = fs.readFileSync(path.join(jsSrcDir, "scripts.js"), "utf8");
    const jsMin = await minify(jsIn, {
      sourceMap: true,
      format: { comments: false },
    });
    fs.writeFileSync(path.join(distJsDir, "scripts.min.js"), jsMin.code);

    // scripts-jquery.js → scripts-jquery.min.js
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

async function build() {
  await processCSS();
  await processJS();
  console.log("🏁  Build complete");
}

if (process.argv.includes("--watch")) {
  console.log("👀  Watching for changes…");
  build();
  chokidar
    .watch([
      "templates/**/*.twig",
      componentCssGlob,
      "tailwind.config.js",
      "postcss.config.js",
      tailwindInput,
      "src/scss/**/*.scss",
    ])
    .on("change", () => processCSS());
  chokidar.watch("src/js/**/*.js").on("change", () => processJS());
} else {
  build();
}
