const { defineConfig } = require('vite');
const legacy = require('@vitejs/plugin-legacy');
const { resolve } = require('path');

module.exports = defineConfig({
  plugins: [
    legacy({
      targets: ['> 0.5%, last 2 versions, Firefox ESR, not dead'],
    }),
  ],
  build: {
    outDir: 'assets',
    emptyOutDir: false, // Don't empty the output directory to preserve other assets
    sourcemap: true,
    minify: 'terser',
    rollupOptions: {
      input: {
        // Correct path to your scripts.js file
        scripts: resolve(__dirname, 'assets/scripts/scripts.js'),
        // Path to your SCSS file - adjust if needed
        styles: resolve(__dirname, 'assets/scss/styles.scss')
      },
      output: {
        entryFileNames: 'js/[name].min.js',
        chunkFileNames: 'js/[name].min.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name.endsWith('.css')) {
            return 'css/[name].min.[ext]';
          }
          return 'assets/[name].[ext]';
        },
      },
      // External libraries provided by Drupal
      external: ['jquery'],
    },
  },
  css: {
    devSourcemap: true,
    preprocessorOptions: {
      scss: {
        // Global imports if needed
      },
    },
  },
  // Make jQuery available in the global scope
  define: {
    'window.jQuery': 'jQuery',
    'window.$': 'jQuery',
  },
  // Resolve jQuery when imported
  resolve: {
    alias: {
      'jquery': 'jquery/dist/jquery.min.js',
    }
  },
});