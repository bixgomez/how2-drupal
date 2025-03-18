// Import jQuery (Drupal already has it, but this makes it available for Vite)
import jQuery from 'jquery';

// You might need to import other dependencies if they're not provided by Drupal
// For example:
// import 'slick-carousel';
// import 'featherlight';
// import 'featherlight/release/featherlight.gallery';

// Ensure your code runs after the document is ready
jQuery(document).ready(function($) {
    // Slick carousel initialization
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

    // Mobile menu handling
    $('.mobile-menu-icon > a').click(function(e) {
       e.preventDefault();
       $(this).toggleClass('active').toggleClass('inactive');
       $('#mobile-navigation').toggleClass('active');
    });

    $('.close-modal').click(function(e) {
        $('.mobile-menu-icon > a').toggleClass('active').toggleClass('inactive');
        $('#mobile-navigation').toggleClass('active');
    });

    // Tab functionality
    $('.issue-tabs a.tab').click(function(e) {
        e.preventDefault();
        $('.issue-tabs a.active').removeClass('active');
        $(this).addClass('active');
        var tabBody = $(this).attr('href');
        $('.tab-body.active').removeClass('active');
        $(tabBody).addClass('active');
    });

    // Featherlight gallery initialization
    $('.field--name-field-journal-page-images-media a').featherlightGallery({
        gallery: {
            fadeIn: 300,
            fadeOut: 300
        },
        openSpeed:    300,
        closeSpeed:   300
    });
});