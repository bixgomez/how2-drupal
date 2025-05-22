(function ($) {
  // Slick carousel initialization
  $(".block-inline-blockimage-carousel > .field--name-field-image").slick();
  $(
    ".block-inline-blockcontent-carousel > .field--name-field-content-embed"
  ).slick({
    infinite: true,
    speed: 300,
    slidesToShow: 4,
    slidesToScroll: 1,
    responsive: [
      // Add responsive settings if needed...
    ],
  });

  // Featherlight gallery
  $(".field--name-field-journal-page-images-media a").featherlightGallery({
    gallery: {
      fadeIn: 300,
      fadeOut: 300,
    },
    openSpeed: 300,
    closeSpeed: 300,
  });
})(jQuery);
