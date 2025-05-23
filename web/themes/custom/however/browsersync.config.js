// browsersync.config.js
// Configuration for BrowserSync development server with DDEV integration

module.exports = {
  // Proxy to your DDEV site instead of serving static files
  // This lets BrowserSync inject its scripts into your Drupal site
  proxy: "https://however-drupal.ddev.site/",

  // Files to watch for changes that should trigger browser reload
  files: [
    "dist/css/*.css", // Compiled CSS output
    "dist/js/*.js", // Compiled JavaScript output
    "templates/**/*.html.twig", // Drupal template files
    "components/**/*.html.twig", // Component template files
  ],

  // Don't automatically open browser when starting (can be annoying during dev)
  open: false,

  // Show notifications in browser when files change
  notify: true,

  // Improved file watching options to prevent rapid-fire reloads
  watchOptions: {
    ignoreInitial: true, // Don't trigger on startup, only on actual changes
    awaitWriteFinish: {
      stabilityThreshold: 100, // Wait 100ms after file stops changing
      pollInterval: 10, // Check every 10ms if file is still changing
    },
  },

  // Restart BrowserSync when config changes
  reloadOnRestart: true,

  // Try to inject CSS changes without full page reload (faster)
  injectChanges: true,

  // DDEV-specific settings
  // Use HTTP instead of HTTPS for the BrowserSync proxy
  // (DDEV handles the HTTPS, BrowserSync just proxies)
  https: false,

  // Listen on all interfaces so DDEV can access it
  host: "0.0.0.0",

  // HTTP module configuration for handling DDEV's SSL setup
  httpModule: {
    // Don't reject self-signed certificates (DDEV uses them)
    rejectUnauthorized: false,
  },
};
