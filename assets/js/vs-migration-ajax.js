jQuery(document).ready(function ($) {
    $('#vs-migration-button').on('click', function (e) {
        e.preventDefault();
        // console.log($("#api-url").val());.
        var api_Link = $("#api-url").val();
        $.ajax({
            url: migrationAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'vs_xml_migration',
                api_Link: api_Link
            },
            success: function (response) {
                $('#vsblc-results').html(response);
            }
        });
    });
});