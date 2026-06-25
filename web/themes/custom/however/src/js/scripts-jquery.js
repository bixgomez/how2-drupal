(function ($, Drupal, once) {
  Drupal.behaviors.howeverCarousels = {
    attach: function (context) {
      once('however-image-carousel', '.block-inline-blockimage-carousel > .field--name-field-image', context).forEach(function (el) {
        $(el).slick();
      });

      once('however-content-carousel', '.block-inline-blockcontent-carousel > .field--name-field-content-embed', context).forEach(function (el) {
        $(el).slick({
          infinite: true,
          speed: 300,
          slidesToShow: 4,
          slidesToScroll: 1,
        });
      });
    }
  };

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
