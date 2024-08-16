jQuery(document).ready(function ($) {
    $('#vs-migration-button').on('click', function (e) {
        e.preventDefault();

        var progressBar = $('#progress-bar');
        progressBar.val(0);
        progressBar.show();
        
        // console.log($("#api-url").val());.
        var api_Link = $("#api-url").val();
        $.ajax({
            url: migrationAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'vs_xml_migration',
                api_Link: api_Link
            },
            xhr: function () {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function (evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = evt.loaded / evt.total;
                        progressBar.val(percentComplete * 100);
                    }
                }, false);
                return xhr;
            },
            success: function (response) {
                progressBar.val(100);
                $('#vsblc-results').html(response);
            }
        });
    });
});