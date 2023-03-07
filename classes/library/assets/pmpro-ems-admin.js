'use strict';

var PMPro_EMS = {

    init: function ($) {
        PMPro_EMS.$ = $;
        $('#pmproDownloadCSV, #pmproDownloadCSVNonSynced').on( 'click', function(e){
            e.preventDefault();
            const action = $(this).attr('data-action') || 'download_csv';
            PMPro_EMS.downloadCSV( action );
        });

        $(document.body).on( 'click', '[data-pmpro-action]', function (e){
            e.preventDefault();

            const action = $(this).attr('data-pmpro-action');
            const $this  = $(this);
            $this.html(pmpro_ems.text.clearing_cache);
            $.ajax({
                method: 'POST',
                url: pmpro_ems.ajaxurl,
                data: {
                    action: action,
                    nonce: pmpro_ems.nonce
                },
                success: function ( resp ) {
                    if ( ! resp.success ) {
                        alert( resp.data );
                    }
                },
                complete: function (){
                    $this.html(pmpro_ems.text.clear_cache);
                    $this.parent().html(pmpro_ems.text.cache_cleared);
                }
            });
        });
    },
    /**
     * Download CSV
     */
    downloadCSV: function downloadCSV( action ) {
        PMPro_EMS.$.ajax({
                url: window.ajaxurl,
                data: { action: 'pmpro_ems_' + action },
                success: function (resp) {
                    if ( ! resp.success ) {
                        alert( resp.data );
                        return;
                    }
                    PMPro_EMS.exportCSV( resp.data, 'pmpro-users' );
                }
            }
        );
    },
    /**
     * Convert object to CSV
     *
     * @param objArray
     * @returns {string}
     */
    convertToCSV: function convertToCSV(objArray) {
        var array = typeof objArray != 'object' ? JSON.parse(objArray) : objArray;
        var str = '';

        for (var i = 0; i < array.length; i++) {
            var line = '';
            for (var index in array[i]) {
                if (line != '') line += ','

                line += array[i][index];
            }

            str += line + '\r\n';
        }

        return str;
    },
    /**
     * Export CSV
     * @param items
     * @param fileTitle
     */
    exportCSV: function exportCSV(items, fileTitle) {
        // Convert Object to JSON
        var jsonObject = JSON.stringify(items);

        var csv = PMPro_EMS.convertToCSV(jsonObject);

        var exportedFilenmae = fileTitle + '.csv' || 'export.csv';

        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        if (navigator.msSaveBlob) { // IE 10+
            navigator.msSaveBlob(blob, exportedFilenmae);
        } else {
            var link = document.createElement("a");
            if (link.download !== undefined) { // feature detection
                // Browsers that support HTML5 download attribute
                var url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", exportedFilenmae);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
    }
};

(function ($){
    $(function(){
        PMPro_EMS.init($);
    });
})(jQuery);