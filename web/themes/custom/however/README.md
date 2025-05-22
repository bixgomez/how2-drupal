# HOW(ever) Drupal Theme

A modern Drupal 10 theme built with Tailwind CSS, Sass, and component-based architecture for the HOW(ever) publication platform.

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

## Development Workflow

### Available Scripts

- `npm run build` - Build production assets (minified CSS/JS)
- `npm run watch` - Watch for changes and rebuild automatically
- `npm run dev` - Start development server with live reload

### Development Server

For local development with live reload:

```bash
npm run dev
```

This starts:
- File watching for automatic rebuilds
- BrowserSync server for live reload
- Proxy to your DDEV site (configured for `however-drupal.ddev.site`)

### File Structure

```
however/
├── components/              # Reusable UI components
│   ├── issue/              # Issue display component
│   ├── issues-grid/        # Issues grid layout
│   ├── publication-teaser/ # Publication preview cards
│   ├── section-teaser/     # Section preview cards
│   └── volume/             # Volume display component
├── dist/                   # Built assets (auto-generated)
│   ├── css/
│   └── js/
├── src/                    # Source files
│   ├── css/
│   ├── js/
│   └── scss/
├── templates/              # Drupal template overrides
│   ├── content/           # Node templates
│   ├── field/             # Field templates
│   ├── layout/            # Layout templates
│   └── paragraph/         # Paragraph templates
└── vendor/                # Third-party libraries
```

## Customization

### Colors

Custom colors are defined in CSS variables and can be modified in your main stylesheet:

```css
:root {
  --color-primary: #your-color;
  --color-primary-dark: #your-dark-color;
  --color-secondary: #your-secondary;
  --color-accent: #your-accent;
  --color-yellow: #your-yellow;
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

## License

[Add your license information here]

## Support

[Add support contact information or links]