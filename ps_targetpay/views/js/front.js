jQuery(document).ready(function () {
    jQuery('body').on('click', '#ideal-toggle', function () {
        jQuery('#ideal-bankselect').toggle();
        return false;
    });
    jQuery('body').on('click', '#sofort-toggle', function () {
        jQuery('#sofort-bankselect').toggle();
        return false;
    });
});
