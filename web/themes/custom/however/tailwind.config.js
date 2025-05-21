module.exports = {
  content: [
    "./templates/**/*.{html,twig}",
    "./components/**/*.{html,twig}",
    "./src/js/**/*.js",
  ],
  theme: {
    extend: {
      colors: {
        primary: "var(--color-primary)",
        "primary-dark": "var(--color-primary-dark)",
        secondary: "var(--color-secondary)",
        accent: "var(--color-accent)",
        yellow: "var(--color-yellow)",
      },
    },
  },
};
