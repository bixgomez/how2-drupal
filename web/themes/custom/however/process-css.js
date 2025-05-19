// process-css.js
const fs = require("fs");
const path = require("path");
const postcss = require("postcss");
const sass = require("sass");
const { minify } = require("terser");
const chokidar = require("chokidar");

// Ensure directory exists
function ensureDir(dir) {
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

const srcCssDir = path.join(__dirname, "src", "css");
const distCssDir = path.join(__dirname, "dist", "css");

ensureDir(srcCssDir);
ensureDir(distCssDir);

// Create or update the tailwind import file
const tailwindImportFile = path.join(srcCssDir, "tailwind.css");
if (!fs.existsSync(tailwindImportFile)) {
  fs.writeFileSync(tailwindImportFile, '@import "tailwindcss";\n');
}

// Process Tailwind CSS and return the output directly (no file write)
async function processTailwindCSS() {
  try {
    console.log("Processing Tailwind CSS...");
    const css = fs.readFileSync(tailwindImportFile, "utf8");

    // Use the PostCSS configuration defined in postcss.config.js
    const postcssConfig = require("./postcss.config.js");
    const plugins = [];

    // Convert postcss.config.js plugins object to array of instantiated plugins
    Object.entries(postcssConfig.plugins).forEach(([name, options]) => {
      const plugin = require(name);
      if (typeof plugin === "function") {
        plugins.push(plugin(options));
      }
    });

    // Process with PostCSS
    const result = await postcss(plugins).process(css, {
      from: tailwindImportFile,
      to: path.join(distCssDir, "styles.min.css"), // Change destination to final file
    });

    console.log("Tailwind CSS processed successfully");
    return result.css;
  } catch (err) {
    console.error("Error processing Tailwind CSS:", err);
    return "";
  }
}

// Process SCSS
async function processSass() {
  try {
    console.log("Processing SCSS...");
    const result = sass.compile("src/scss/styles.scss", {
      style: "compressed",
      sourceMap: true,
      loadPaths: ["node_modules"],
    });

    ensureDir(distCssDir);

    // Get the processed Tailwind CSS
    const tailwindCSS = await processTailwindCSS();

    // Combine Tailwind + Sass output
    const combinedCSS = tailwindCSS + result.css;

    // Write the combined file
    fs.writeFileSync(path.join(distCssDir, "styles.min.css"), combinedCSS);
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
  await processSass(); // This now internally calls processTailwindCSS
  await processJS();
  console.log("Build completed");
}

// Execute based on command-line arguments
if (process.argv.includes("--watch")) {
  console.log("Watching for changes...");

  // Initial build
  build();

  // Watch templates for Tailwind class changes
  chokidar.watch("./templates/**/*.twig").on("change", async (path) => {
    console.log(`Template file changed: ${path}`);
    await processSass();
  });

  chokidar.watch("./components/**/*.twig").on("change", async (path) => {
    console.log(`Component file changed: ${path}`);
    await processSass();
  });

  // Watch Tailwind CSS input file
  chokidar.watch("src/css/tailwind.css").on("change", async (path) => {
    console.log(`Tailwind file changed: ${path}`);
    await processSass();
  });

  // Watch SCSS files
  chokidar.watch("src/scss/**/*.scss").on("change", async (path) => {
    console.log(`SCSS file changed: ${path}`);
    await processSass();
  });

  // Watch JS files
  chokidar.watch("src/js/**/*.js").on("change", async (path) => {
    console.log(`JS file changed: ${path}`);
    await processJS();
  });

  // Watch config files
  chokidar.watch("tailwind.config.js").on("change", async (path) => {
    console.log(`Tailwind config changed: ${path}`);
    await processSass();
  });

  chokidar.watch("postcss.config.js").on("change", async (path) => {
    console.log(`PostCSS config changed: ${path}`);
    await processSass();
  });
} else {
  build();
}
