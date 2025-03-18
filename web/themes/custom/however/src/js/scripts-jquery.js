// scripts-jquery.js
(function ($) {
    // Slick carousel initialization
    $('.block-inline-blockimage-carousel > .field--name-field-image').slick();
    $('.block-inline-blockcontent-carousel > .field--name-field-content-embed').slick({
        infinite: true,
        speed: 300,
        slidesToShow: 4,
        slidesToScroll: 1,
        responsive: [
            // responsive settings here...
        ]
    });

    // Mobile menu functionality
    $('.mobile-menu-icon > a').click(function(e) {
       e.preventDefault();
       $(this).toggleClass('active').toggleClass('inactive');
       $('#mobile-navigation').toggleClass('active');
    });

    // Close modal
    $('.close-modal').click(function(e) {
        $('.mobile-menu-icon > a').toggleClass('active').toggleClass('inactive');
        $('#mobile-navigation').toggleClass('active');
    });

    // Issue tabs
    $('.issue-tabs a.tab').click(function(e) {
        e.preventDefault();
        $('.issue-tabs a.active').removeClass('active');
        $(this).addClass('active');
        var tabBody = $(this).attr('href');
        $('.tab-body.active').removeClass('active');
        $(tabBody).addClass('active');
    });

    // Featherlight gallery
    $('.field--name-field-journal-page-images-media a').featherlightGallery({
        gallery: {
            fadeIn: 300,
            fadeOut: 300
        },
        openSpeed:    300,
        closeSpeed:   300
    });
})(jQuery);