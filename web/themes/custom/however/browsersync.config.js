module.exports = {
    // Your DDEV site URL
    proxy: "https://however-drupal.ddev.site/",
    
    // Watch these files for changes
    files: [
      "assets/css/*.css",
      "assets/js/*.js"
    ],
    
    // Don't open a new browser window automatically
    open: true,
    
    // Notify on changes
    notify: true,
    
    // Use HTTPS since your site is using HTTPS
    https: true,
    
    // Ignore certificate errors for local development
    httpModule: {
      rejectUnauthorized: false
    }
  };