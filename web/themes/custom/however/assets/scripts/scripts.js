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
                    slidesToShow: 2,
                    slidesToScroll: 2
                }
            },
            {
                breakpoint: 480,
                settings: {
                    slidesToShow: 1,
                    slidesToScroll: 1
                }
            }
        ]
    });

    $('.mobile-menu-icon > a').click(function(e) {
       e.preventDefault();
       $(this).toggleClass('active').toggleClass('inactive');
       $('#mobile-navigation').toggleClass('active');
    });

    $('.close-modal').click(function(e) {
        $('.mobile-menu-icon > a').toggleClass('active').toggleClass('inactive');
        $('#mobile-navigation').toggleClass('active');
    });

})(jQuery);