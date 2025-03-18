module.exports = {
  plugins: {
    autoprefixer: {
      // Targeting modern browsers plus IE11
      browsers: ['> 1%', 'last 2 versions', 'Firefox ESR', 'not dead']
    },
    cssnano: {
      preset: ['default', {
        discardComments: {
          removeAll: true,
        },
        // Optimize and minify
        colormin: true,
        convertValues: true,
        reduceIdents: false, // Keep this false to avoid breaking animations
        zindex: false, // Don't mess with z-index values
      }],
    },
  },
};