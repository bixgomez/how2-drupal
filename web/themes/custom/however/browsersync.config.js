module.exports = {
  proxy: "https://however-drupal.ddev.site/",

  files: [
    "dist/css/*.css",
    "dist/js/*.js",
    "templates/**/*.html.twig",
    "components/**/*.html.twig",
  ],

  open: false,
  notify: true,

  // Add these options for better file watching
  watchOptions: {
    ignoreInitial: true,
    awaitWriteFinish: {
      stabilityThreshold: 100,
      pollInterval: 10,
    },
  },

  // Better reload behavior
  reloadOnRestart: true,
  injectChanges: true,

  // DDEV-specific settings
  https: false,
  host: "0.0.0.0",

  httpModule: {
    rejectUnauthorized: false,
  },
};
