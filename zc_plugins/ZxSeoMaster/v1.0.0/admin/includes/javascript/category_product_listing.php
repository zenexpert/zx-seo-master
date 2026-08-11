<?php
if (ZX_INDEXNOW_STATUS == 'true') { ?>
    <script>
        $(document).ready(function () {
            const $statusButtons = $('a[href*="action=setflag"]');
            if ($statusButtons.length === 0) return;

            // initialize jQuery UI Dialog
            const $indexNowDialog = $('<div><p style="margin-top:10px;">Do you want to submit this status update to <strong>IndexNow</strong>?</p></div>').dialog({
                autoOpen: false,
                title: 'IndexNow Submission',
                modal: true,
                resizable: false,
                width: 350,
                dialogClass: 'ui-dialog-osx',
                buttons: {
                    "Yes": function () {
                        const activeUrl = $(this).data('activeUrl');
                        if (activeUrl) {
                            const urlObj = new URL(activeUrl);
                            urlObj.searchParams.set('indexnow', '1');
                            window.location.href = urlObj.toString();
                        }
                    },
                    "No": function () {
                        const activeUrl = $(this).data('activeUrl');
                        if (activeUrl) {
                            const urlObj = new URL(activeUrl);
                            urlObj.searchParams.set('indexnow', '0');
                            window.location.href = urlObj.toString();
                        }
                    }
                }
            });

            // intercept status clicks
            $statusButtons.on('click', function (event) {
                event.preventDefault();

                // store the exact href in the dialog's data attribute before opening
                $indexNowDialog.data('activeUrl', this.href);
                $indexNowDialog.dialog('open');
            });

            // maintain keyboard accessibility
            $statusButtons.on('keydown', function (event) {
                if (event.key === ' ' || event.key === 'Spacebar') {
                    event.preventDefault();
                    $(this).trigger('click');
                }
            });
        });
    </script>
<?php } ?>
