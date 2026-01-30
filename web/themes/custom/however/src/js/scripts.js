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
