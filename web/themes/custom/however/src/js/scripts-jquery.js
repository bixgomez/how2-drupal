(function ($, Drupal, once) {
  Drupal.behaviors.howeverFeatherlight = {
    attach: function (context) {
      once('however-featherlight', '.field--name-field-journal-page-images-media', context).forEach(function (el) {
        $(el).find('a').featherlightGallery({
          gallery: {
            fadeIn: 300,
            fadeOut: 300,
          },
          openSpeed: 300,
          closeSpeed: 300,
        });
      });
    }
  };
})(jQuery, Drupal, once);
