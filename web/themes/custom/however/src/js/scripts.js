(function (Drupal) {
  Drupal.behaviors.customScripts = {
    attach: function (context, settings) {
      const setMobileMenuBodyClass = () => {
        const isOpen = document
          .getElementById("mobile-navigation")
          ?.classList.contains("active");
        document.body.classList.toggle("mobile-menu-open", Boolean(isOpen));
      };

      // Mobile menu functionality
      document
        .querySelectorAll(".mobile-menu-icon > a", context)
        .forEach((item) => {
          item.addEventListener("click", function (e) {
            e.preventDefault();
            this.classList.toggle("active");
            this.classList.toggle("inactive");
            document
              .getElementById("mobile-navigation")
              ?.classList.toggle("active");
            setMobileMenuBodyClass();
          });
        });

      // Close modal
      document.querySelectorAll(".close-modal", context).forEach((item) => {
        item.addEventListener("click", function () {
          document
            .querySelectorAll(".mobile-menu-icon > a", context)
            .forEach((menuItem) => {
              menuItem.classList.toggle("active");
              menuItem.classList.toggle("inactive");
            });
          document
            .getElementById("mobile-navigation")
            ?.classList.toggle("active");
          setMobileMenuBodyClass();
        });
      });

      // Issue tabs
      document.querySelectorAll(".issue-tabs a.tab", context).forEach((tab) => {
        tab.addEventListener("click", function (e) {
          e.preventDefault();
          document
            .querySelector(".issue-tabs a.active", context)
            ?.classList.remove("active");
          this.classList.add("active");

          let tabBody = document.querySelector(
            this.getAttribute("href"),
            context
          );
          document
            .querySelector(".tab-body.active", context)
            ?.classList.remove("active");
          tabBody?.classList.add("active");
        });
      });
    },
  };
})(Drupal);

/**
 * Legacy content fixes for migrated HTML.
 */
document.addEventListener("DOMContentLoaded", function () {
  /**
   * Fix legacy spacer GIFs used for indentation.
   *
   * Selects all <img> elements with src="0.gif", reads their width and hspace
   * attributes, and converts them to margin-left spacing while hiding the image.
   * This preserves the original indentation visually.
   */
  const spacerImages = document.querySelectorAll('img[src="0.gif"]');

  spacerImages.forEach(function (img) {
    const widthAttr = img.getAttribute("width");
    const hspaceAttr = img.getAttribute("hspace");

    const width = parseInt(widthAttr, 10) || 0;
    const hspace = parseInt(hspaceAttr, 10) || 0;
    const totalIndent = width + hspace;

    img.style.marginLeft = totalIndent + "px";
    img.style.marginRight = "0";
    img.style.width = "0";
    img.style.height = "0";
    img.style.pointerEvents = "none";
  });

  /**
   * Fix relative image paths missing leading slash.
   *
   * Converts src="images/..." to src="/images/..." so paths resolve
   * correctly from any URL depth.
   */
  const relativeImages = document.querySelectorAll('img[src^="images/"]');

  relativeImages.forEach(function (img) {
    img.setAttribute("src", "/" + img.getAttribute("src"));
  });
});
