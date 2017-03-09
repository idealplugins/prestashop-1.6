jQuery(document).ready(function () {
    jQuery('#ideal-toggle').click(function () {
        jQuery('#ideal-bankselect').toggle();
        return false;
    });
    jQuery('#sofort-toggle').click(function () {
        jQuery('#sofort-bankselect').toggle();
        return false;
    });
});
