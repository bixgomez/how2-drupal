# HOW(ever) Drupal Theme

A modern Drupal 10 theme built with Tailwind CSS, Sass, and component-based architecture for the HOW(ever) publication platform archiving two poetry journals.

## Overview

This theme provides a clean, responsive design for academic publications, featuring two main publication types:
- **HOW2** - Academic articles and sections
- **HOW(ever)** - Journal articles and issues

## Features

- 🎨 **Tailwind CSS** integration with custom color variables
- 📱 **Responsive design** optimized for all devices
- 🧩 **Component-based architecture** for maintainable code
- ⚡ **Modern build pipeline** with automated CSS/JS processing
- 🔄 **Live reload** development environment with BrowserSync
- 📸 **Gallery support** with Featherlight lightbox
- 🎠 **Carousel functionality** with Slick slider
- 🔍 **Search optimization** with custom templates

## Quick Start

### Prerequisites

- Node.js (v14 or higher)
- npm or yarn
- Drupal 10 site
- DDEV (recommended for local development)

### Installation

1. **Clone or download** the theme to your Drupal themes directory:
   ```bash
   cd web/themes/custom/
   git clone [your-repo] however
   ```

2. **Install dependencies**:
   ```bash
   cd however
   npm install
   ```

3. **Build assets**:
   ```bash
   npm run build
   ```

4. **Enable the theme** in Drupal admin or via Drush:
   ```bash
   drush theme:enable however
   drush config:set system.theme default however
   ```

### Available Scripts

- `npm run build` - Build production assets (minified CSS/JS)
- `npm run watch` - Watch for changes and rebuild automatically  
- `npm run dev` - Start development server with live reload

## Key Files & What They Do

### Core Theme Files

- **`however.info.yml`** - Theme definition, regions, component namespaces
- **`however.libraries.yml`** - CSS/JS library definitions for Drupal
- **`however.theme`** - PHP preprocessing functions for all content types

### Build System

- **`process-css.js`** - Main build script that combines Tailwind + Sass + component CSS
- **`package.json`** - Dependencies and npm scripts
- **`postcss.config.js`** - PostCSS configuration (just Tailwind)
- **`tailwind.config.js`** - Tailwind configuration and content paths
- **`browsersync.config.js`** - BrowserSync config for live reloading

### CSS Architecture

- **`src/css/tailwind.css`** - Tailwind imports + CSS custom properties + utility overrides
- **`src/scss/styles.scss`** - Main Sass entry point (imports abstracts, base, components)
- **`src/scss/abstracts/`** - Variables, mixins, utilities
- **`src/scss/base/`** - Layout foundations
- **`src/scss/components/`** - Component-specific styles
- **`components/*/[name].css`** - Individual component CSS files

### JavaScript

- **`src/js/scripts.js`** - Main vanilla JS
- **`src/js/scripts-jquery.js`** - jQuery-dependent scripts
- **`dist/js/`** - Minified output files

## How the Build Works

1. **`process-css.js` combines three sources:**
   - Tailwind CSS (from `src/css/tailwind.css`)
   - Component CSS (from `components/**/*.css`)
   - Sass compilation (from `src/scss/styles.scss`)

2. **Runs everything through PostCSS** with Tailwind processing

3. **Outputs minified CSS** to `dist/css/styles.min.css`

4. **Minifies JS** from `src/js/` to `dist/js/`

## Variables & Configuration

### CSS Custom Properties (in `src/css/tailwind.css`)
```css
:root {
  --color-yellow: #ebe6d1;
  --color-primary: #9e2727;
  --color-primary-dark: #6b1919;
  --color-secondary: #d3ceb6;
  --color-accent: #fffcf0;
}
```

### Tailwind Config (in `tailwind.config.js`)
- **Content paths:** Where Tailwind scans for classes
- **Color extensions:** Maps CSS custom properties to Tailwind utilities

### Sass Variables (in `src/scss/abstracts/_variables.scss`)
- Additional color definitions and component-specific variables

## Component System

Each component lives in `components/[name]/` with:
- `[name].html.twig` - Template
- `[name].css` - Styles (gets included in build)
- `[name].js` - JavaScript (if needed)

**Registered components in `however.info.yml`:**
- `issue`
- `volume` 
- `item-teaser`
- `publication-teaser`
- `issues-grid`

## Template Hierarchy

### Content Types
- **How2:** `templates/content/how2/node--how2-[type].html.twig`
- **How(ever):** `templates/content/however/node--how-ever-[type].html.twig`
- **Generic:** `templates/content/node--[type].html.twig`

### Field Templates
- Custom field rendering in `templates/field/`
- Paragraph templates in `templates/paragraph/`

## Theme Functions (in `however.theme`)

### What Gets Added to Template Variables

**For Issues (`how2_issue`, `journal_issue`):**
- `$variables['volume']` - "vol. X"
- `$variables['issue']` - "no. X" 
- `$variables['volume_issue']` - "vol. X, no. Y"
- `$variables['label']` - Short description

**For Articles/Sections:**
- Same volume/issue variables
- `$variables['issue_pub_date']` - Publication date from referenced issue
- `$variables['issue_url']` - URL to parent issue
- `$variables['contributors']` - Comma-separated contributor names (teaser view)

## Development Workflow

For local development with live reload:

```bash
npm run dev
```

This starts:
- File watching for automatic rebuilds
- BrowserSync server for live reload
- Proxy to your DDEV site (configured for `however-drupal.ddev.site`)

### Development Process

1. **Start dev mode:** `npm run dev`
2. **Edit templates:** Auto-reload via BrowserSync
3. **Edit CSS:** 
   - Tailwind classes → edit in templates
   - Component styles → edit in `components/*/[name].css`
   - Global styles → edit in `src/scss/`
4. **Edit JS:** Files in `src/js/` auto-minify to `dist/js/`

### File Structure

```
however/
├── components/             # Reusable UI components
│   ├── issue/              # Issue display component
│   ├── issues-grid/        # Issues grid layout
│   ├── publication-teaser/ # Publication preview cards
│   ├── item-teaser/        # Section/article preview cards
│   └── volume/             # Volume display component
├── dist/                   # Built assets (auto-generated)
│   ├── css/
│   └── js/
├── src/                    # Source files
│   ├── css/
│   ├── js/
│   └── scss/
├── templates/              # Drupal template overrides
│   ├── content/            # Node templates
│   ├── field/              # Field templates
│   ├── layout/             # Layout templates
│   └── paragraph/          # Paragraph templates
└── vendor/                 # Third-party libraries
```

## Customization

### Colors

Custom colors are defined in CSS variables in `src/css/tailwind.css`:

```css
:root {
  --color-primary: #9e2727;
  --color-primary-dark: #6b1919;
  --color-secondary: #d3ceb6;
  --color-accent: #fffcf0;
  --color-yellow: #ebe6d1;
}
```

These are available as Tailwind utilities:
- `text-primary`, `bg-primary`
- `text-primary-dark`, `bg-primary-dark`
- `text-secondary`, `bg-secondary`
- `text-accent`, `bg-accent`
- `text-yellow`, `bg-yellow`

### Components

Each component in the `/components` directory includes:
- `.html.twig` - Template markup
- `.css` - Component-specific styles
- `.js` - Component behavior (if needed)

Components are automatically included in the build process.

### Adding New Components

1. Create a new directory in `/components`
2. Add your `.html.twig`, `.css`, and `.js` files
3. Register the component namespace in `however.info.yml` if needed
4. Run `npm run build` to include in the build

## Content Types

### HOW2 Publications
- **Articles** (`how2_article`) - Individual academic articles
- **Sections** (`how2_section`) - Article collections
- **Issues** (`how2_issue`) - Published issue collections
- **Volumes** (`how2_volume`) - Volume containers

### HOW(ever) Publications
- **Articles** (`how_ever_article`) - Journal articles
- **Sections** (`how_ever_section`) - Article groupings
- **Issues** (`journal_issue`) - Journal issue collections
- **Volumes** (`however_volume`) - Volume containers

## Template System

### View Modes
- `full` - Complete content display
- `teaser` - Summary/preview display
- `teaser-card` - Card-style preview
- `cross-post-teaser-card` - Cross-publication previews

### Custom Variables

The theme provides additional template variables:
- `volume` - Formatted volume number ("vol. X")
- `issue` - Formatted issue number ("no. X")
- `volume_issue` - Combined format ("vol. X, no. Y")
- `issue_pub_date` - Publication date from issue
- `issue_url` - Link to parent issue
- `contributors` - Formatted contributor list

## Libraries and Dependencies

### CSS Libraries
- **Tailwind CSS** - Utility-first CSS framework
- **Featherlight** - Lightbox gallery functionality
- **Slick** - Carousel and slider functionality

### JavaScript Libraries
- **jQuery** - DOM manipulation (Drupal core dependency)
- **Featherlight** - Gallery interactions
- **Slick** - Carousel behavior

### Build Tools
- **PostCSS** - CSS processing and optimization
- **Sass** - CSS preprocessing
- **Terser** - JavaScript minification
- **BrowserSync** - Development server and live reload

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Internet Explorer 11+ (with polyfills as needed)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Performance

- **CSS**: Minified and optimized with PostCSS
- **JavaScript**: Minified with Terser
- **Images**: Optimized loading with responsive techniques
- **Fonts**: Google Fonts with display=swap for better loading

## File Watching

The build system watches:
- `templates/**/*.twig`
- `components/**/*.css` 
- `src/scss/**/*.scss`
- `src/css/tailwind.css`
- `tailwind.config.js`
- `postcss.config.js`
- `src/js/**/*.js`

## Libraries Loaded

**Global (every page):**
- `however/global-styling` - Main CSS
- `however/theme-scripts` - Vanilla JS
- `however/jquery-scripts` - jQuery + Slick + Featherlight
- `however/slick` - Carousel functionality  
- `however/featherlight` - Lightbox for images

## Troubleshooting

### Build Issues

**CSS not compiling:**
```bash
# Clear node_modules and reinstall
rm -rf node_modules package-lock.json
npm install
npm run build
```

**BrowserSync not connecting:**
- Check that your DDEV site URL matches `browsersync.config.js`
- Ensure ports 3000-3001 are available
- Verify DDEV is running

**Tailwind classes not working:**
- Verify your templates are in the `content` paths in `tailwind.config.js`
- Run `npm run build` after adding new templates
- Check for typos in class names

**CSS not updating?** Check that `process-css.js` is running without errors

**Component styles missing?** Make sure the CSS file is in `components/[name]/[name].css`

### Development Tips

1. **Use the development server** (`npm run dev`) for faster iteration
2. **Check the browser console** for JavaScript errors
3. **Validate your Twig templates** for syntax errors
4. **Clear Drupal cache** after template changes
5. **Use Drupal's theme debug** for template suggestions

## Contributing

1. Follow Drupal coding standards
2. Test changes across different content types
3. Ensure responsive design works on all devices
4. Update documentation for new features
5. Run builds before committing changes