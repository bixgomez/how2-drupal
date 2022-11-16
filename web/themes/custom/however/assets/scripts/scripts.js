(function ($) {

    $('.block-inline-blockimage-carousel > .field--name-field-image').slick();
    $('.block-inline-blockcontent-carousel > .field--name-field-content-embed').slick({
        infinite: true,
        speed: 300,
        slidesToShow: 4,
        slidesToScroll: 1,
        responsive: [
            {
                breakpoint: 880,
                settings: {
                    slidesToShow: 3,
                    slidesToScroll: 2
                }
            },
            {
                breakpoint: 480,
                settings: {
                    slidesToShow: 2,
                    slidesToScroll: 1
                }
            }
        ]
    });
})(jQuery);