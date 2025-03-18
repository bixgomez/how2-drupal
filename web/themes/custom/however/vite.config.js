const { defineConfig } = require('vite');
const legacy = require('@vitejs/plugin-legacy');
const { resolve } = require('path');

module.exports = defineConfig({
  plugins: [
    legacy({
      targets: ['> 0.5%, last 2 versions, Firefox ESR, not dead'],
    }),
  ],
  // Enable fast HMR (Hot Module Replacement)
  server: {
    hmr: true,
    watch: {
      usePolling: true, // Helps with file watching on some systems
    },
  },
  build: {
    // Watch settings for better performance
    watch: {
      clearScreen: false,
    },
    outDir: 'assets',
    emptyOutDir: false,
    sourcemap: true,
    minify: 'terser',
    rollupOptions: {
      input: {
        scripts: resolve(__dirname, 'assets/scripts/scripts.js'),
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
      external: ['jquery'],
    },
  },
  css: {
    devSourcemap: true,
  },
  resolve: {
    alias: {
      'jquery': 'jquery/dist/jquery.min.js',
    }
  },
});