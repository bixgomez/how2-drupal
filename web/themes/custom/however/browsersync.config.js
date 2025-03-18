module.exports = {
  // Your DDEV site URL
  proxy: "https://however-drupal.ddev.site/",
  
  // Watch these files for changes
  files: [
    "dist/css/*.css",
    "dist/js/*.js",
    "templates/**/*.html.twig"
  ],
  
  // Auto-open browser window
  open: true,
  
  // Notify on changes
  notify: true,
  
  // Use HTTPS
  https: true,
  
  // Ignore certificate errors
  httpModule: {
    rejectUnauthorized: false
  }
};