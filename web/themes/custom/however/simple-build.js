const fs = require("fs");
const path = require("path");
const sass = require("sass");
const { minify } = require("terser");
const chokidar = require("chokidar");

// Ensure directory exists
function ensureDir(dir) {
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

// Process SCSS
async function processCSS() {
  try {
    console.log("Processing SCSS...");
    const result = sass.compile("src/scss/styles.scss", {
      style: "compressed",
      sourceMap: true,
      loadPaths: ["node_modules"],
    });

    ensureDir("dist/css");
    fs.writeFileSync("dist/css/styles.min.css", result.css);
    console.log("CSS processed successfully");
  } catch (err) {
    console.error("CSS Error:", err.message);
  }
}

// Process JS
async function processJS() {
  try {
    console.log("Processing JS...");

    // Process vanilla JS
    const jsContent = fs.readFileSync("src/js/scripts.js", "utf8");
    const minified = await minify(jsContent, {
      sourceMap: true,
      format: { comments: false },
    });

    // Process jQuery JS
    const jQueryContent = fs.readFileSync("src/js/scripts-jquery.js", "utf8");
    const minifiedJQuery = await minify(jQueryContent, {
      sourceMap: true,
      format: { comments: false },
    });

    ensureDir("dist/js");
    fs.writeFileSync("dist/js/scripts.min.js", minified.code);
    fs.writeFileSync("dist/js/scripts-jquery.min.js", minifiedJQuery.code);
    console.log("JS processed successfully");
  } catch (err) {
    console.error("JS Error:", err.message);
  }
}

// Main build function
async function build() {
  await processCSS();
  await processJS();
  console.log("Build completed");
}

// Watch for changes
function watch() {
  console.log("Watching for changes...");

  // Do initial build
  build();

  // Watch SCSS files
  chokidar.watch("src/scss/**/*.scss").on("change", (path) => {
    console.log(`SCSS file changed: ${path}`);
    processCSS();
  });

  // Watch JS files
  chokidar.watch("src/js/**/*.js").on("change", (path) => {
    console.log(`JS file changed: ${path}`);
    processJS();
  });
}

// Handle command line arguments
const args = process.argv.slice(2);
if (args.includes("--watch")) {
  watch();
} else {
  build();
}
