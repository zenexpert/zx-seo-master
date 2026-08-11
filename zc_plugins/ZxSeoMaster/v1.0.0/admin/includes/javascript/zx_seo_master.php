<script>
    jQuery(document).ready(function($) {
        'use strict';

        <?php if (isset($entityId) && $entityId > 0) { ?>

        // build a dictionary of real data for the live preview parser
        const seoDataDictionary = {
            '%SITE_NAME%': <?= json_encode(defined('STORE_NAME') ? STORE_NAME : '') ?>,
            '%SITE_TAGLINE%': <?= json_encode(defined('HEADER_SALES_TEXT') ? HEADER_SALES_TEXT : '') ?>,
            '%PRODUCT_NAME%': <?= json_encode($contextData['raw_name'] ?? ($contextData['title'] ?? '')) ?>,
            '%PRODUCT_MODEL%': <?= json_encode($contextData['raw_model'] ?? '') ?>,
            '%PRODUCT_PRICE%': <?= json_encode($contextData['raw_price'] ?? '') ?>,
            '%CATEGORY_NAME%': <?= json_encode($contextData['raw_name'] ?? ($contextData['title'] ?? '')) ?>
        };

        // live preview
        const updateLivePreview = (inputId) => {
            const inputField = $('#' + inputId);
            const previewField = $('#preview_' + inputId);

            // map the input ID to its corresponding counter ID
            const isTitle = inputId.includes('meta_title');
            const countId = isTitle ? inputId.replace('meta_title', 'meta_title_count') : inputId.replace('meta_description', 'meta_description_count');
            const counter = $('#' + countId);

            if (!inputField.length) return;

            let rawText = inputField.val() || '';

            // parse the tags into real data
            $.each(seoDataDictionary, function(tag, realValue) {
                if (realValue) {
                    let regex = new RegExp(tag, 'g');
                    rawText = rawText.replace(regex, realValue);
                }
            });

            // update live preview
            if (previewField.length) {
                let fallbackText = isTitle
                    ? <?= json_encode(JAVASCRIPT_SNIPPET_TITLE_DEFAULT) ?>
                    : <?= json_encode(JAVASCRIPT_SNIPPET_DESC_DEFAULT) ?>;

                previewField.text(rawText ? rawText : fallbackText);
            }

            // update character counter based on the PARSED data length
            if (counter.length) {
                const currentLength = rawText.length;
                const maxChars = parseInt(inputField.attr('data-max'), 10) || (isTitle ? 60 : 160);

                counter.text(currentLength);
                counter.removeClass('char-count-good char-count-warn char-count-over text-success text-warning text-danger');

                if (currentLength > 0) {
                    if (currentLength <= maxChars) {
                        counter.addClass('char-count-good text-success');
                    } else if (currentLength <= maxChars + 10) {
                        counter.addClass('char-count-warn bg-warning');
                    } else {
                        counter.addClass('char-count-over text-danger');
                    }
                }
            }
        };

        // bind the parser to both the title and description inputs
        $('.meta-title-input, .meta-desc-input').on('input keyup', function() {
            updateLivePreview($(this).attr('id'));
        });

        // initialize previews on page load
        $('.meta-title-input, .meta-desc-input').each(function() {
            updateLivePreview($(this).attr('id'));
        });

        // bind the parser to the input's typing events
        $('.meta-title-input').on('input keyup', function() {
            updateLivePreview($(this).attr('id'));
        });

        // initialize previews on page load
        $('.meta-title-input').each(function() {
            updateLivePreview($(this).attr('id'));
        });

        // insert dynamic SEO variables into the title field
        $('.insert-variable').on('click', function(e) {
            e.preventDefault();

            const targetId = $(this).data('target');
            const insertValue = $(this).data('val');
            const inputField = document.getElementById(targetId);

            if (!inputField) return;

            // get cursor position to insert tag exactly where the user is typing
            const startPos = inputField.selectionStart;
            const endPos = inputField.selectionEnd;
            const currentValue = inputField.value;

            inputField.value = currentValue.substring(0, startPos) + insertValue + currentValue.substring(endPos, currentValue.length);

            // move cursor past the newly inserted variable
            inputField.selectionStart = inputField.selectionEnd = startPos + insertValue.length;
            inputField.focus();

            // trigger the input event to update the character counter and live preview
            $(inputField).trigger('input');
        });

        <?php } ?>

        // check if the search field actually exists in the DOM
        const searchFieldCount = $('#entity_search').length;

        if (searchFieldCount === 0) {
            return; // stop if the HTML isn't present
        }

        // set initial visual text if an ID was pre-loaded via PHP
        const currentId = $('#entity_id').val();
        if (currentId) {
            // Append a small helper text below the search field instead of blocking the input
            $('#entity_search').after('<div class="help-block text-info" style="font-size: 12px; margin-top: 4px;"><i class="fa fa-info-circle"></i> Currently editing ID: ' + currentId + '</div>');
        }

        let searchTimer;
        $('#entity_search').on('keyup', function() {
            clearTimeout(searchTimer);
            const query = $(this).val();
            const type = $('#entity_type').val();
            const $resultsList = $('#search_results');

            if (query.length < 1) {
                $resultsList.hide();
                return;
            }

            // debounce to prevent flooding the server
            searchTimer = setTimeout(function() {
                $.ajax({
                    url: '<?php echo zen_href_link(FILENAME_ZX_SEO_MASTER); ?>',
                    type: 'GET',
                    data: {
                        action: 'search_entity',
                        q: query,
                        type: type
                    },
                    dataType: 'json',
                    success: function(data) {
                        $resultsList.empty();

                        if (data.length > 0) {
                            $.each(data, function(index, item) {
                                $resultsList.append('<li><a href="#" class="search-result-item" data-id="' + item.id + '">' + item.text + '</a></li>');
                            });
                        } else {
                            $resultsList.append('<li class="disabled"><a href="#">' + <?= json_encode(JAVASCRIPT_NO_MATCHES_FOUND) ?> + '</a></li>');
                        }
                        $resultsList.show();
                    },
                    error: function(xhr, status, error) {
                    }
                });
            }, 300);
        });

        $(document).on('click', '.search-result-item', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            const text = $(this).text();

            $('#entity_id').val(id);
            $('#entity_search').val(text);
            $('#search_results').hide();

            $('#loadEntityBtn').click();
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('#entity_search, #search_results').length) {
                $('#search_results').hide();
            }
        });

        const loadBtn = document.getElementById('loadEntityBtn');
        if (loadBtn) {
            loadBtn.addEventListener('click', () => {
                const entityType = document.getElementById('entity_type').value;
                const entityId = document.getElementById('entity_id').value;

                if (entityId > 0) {
                    // append #editor to force the tab to switch on page load
                    window.location.href = '<?php echo zen_href_link(FILENAME_ZX_SEO_MASTER); ?>&entity_type=' + entityType + '&entity_id=' + entityId + '#editor';
                } else {
                    alert(<?= json_encode(JAVASCRIPT_SEARCH_FIRST) ?>);
                }
            });
        }

        // --- tab memory management ---
        // read the URL hash and open the corresponding tab on page load
        const activeTab = window.location.hash;
        if (activeTab) {
            $('.nav-tabs a[href="' + activeTab + '"]').tab('show');
        }

        // update the URL hash quietly when you click different tabs
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            window.history.replaceState(null, null, e.target.hash);
        });

        // copy to clipboard for Sitemap
        $('#copySitemapBtn').on('click', function() {
            const copyInput = document.getElementById('sitemapRobotsLine');
            const $btn = $(this);
            const originalHtml = $btn.html();

            // select the text for visual feedback
            copyInput.select();
            copyInput.setSelectionRange(0, 99999); // For mobile compatibility

            // execute the copy
            if (navigator.clipboard) {
                navigator.clipboard.writeText(copyInput.value).then(() => {
                    showSuccess();
                }).catch(err => {
                    document.execCommand('copy');
                    showSuccess();
                });
            } else {
                // fallback for older browsers
                document.execCommand('copy');
                showSuccess();
            }

            function showSuccess() {
                $btn.html('<i class="fa fa-check text-success"></i> ' + <?= json_encode(JAVASCRIPT_SUCCESS_COPIED) ?>).prop('disabled', true);
                setTimeout(() => {
                    $btn.html(originalHtml).prop('disabled', false);
                }, 2000);
            }
        });

        // Content Analysis
        $('#btnAnalyze').on('click', function() {
            var $btn = $(this);
            var type = $('#analyze_type').val();
            var id = $('#analyze_id').val();
            var focusKeyword = $('#analyze_focus_keyword').val().trim().toLowerCase();
            var $resultsContainer = $('#analysis_results');

            if (!id || id <= 0) {
                alert(<?= json_encode(JAVASCRIPT_ENTER_VALID_ID) ?>);
                return;
            }
            if (!focusKeyword) {
                alert(<?= json_encode(JAVASCRIPT_ENTER_FOCUS_KEYWORD) ?>);
                return;
            }

            $btn.html('<i class="fa fa-spinner fa-spin"></i> ' + <?= json_encode(JAVASCRIPT_TEXT_ANALYZING) ?>).prop('disabled', true);
            $resultsContainer.html('<div class="text-center" style="padding: 40px;"><i class="fa fa-spinner fa-spin fa-3x text-muted"></i></div>');

            $.ajax({
                url: '<?php echo zen_href_link(FILENAME_ZX_SEO_MASTER); ?>',
                type: 'GET',
                data: { action: 'analyze_content', cType: type, cId: id },
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        $resultsContainer.html('<div class="alert alert-danger">' + response.error + '</div>');
                        return;
                    }

                    // prioritize Custom Meta, fallback to Native
                    var metaTitle = response.customMetaTitle || response.nativeMetaTitle || response.title;
                    var metaDesc = response.customMetaDesc || response.nativeMetaDesc || '';
                    var contentText = response.content.toLowerCase();
                    var titleText = metaTitle.toLowerCase();
                    var descText = metaDesc.toLowerCase();

                    var totalScore = 0;
                    var maxScore = 100;
                    var html = '';

                    // helper to generate the card UI
                    const createCard = (title, points, maxPoints, description) => {
                        let statusClass = 'score-poor';
                        let textClass = 'text-poor';
                        let word = <?= json_encode(JAVASCRIPT_SCORE_NEEDS_IMPROVEMENT) ?>;

                        let percentage = points / maxPoints;
                        if (percentage >= 0.8) {
                            statusClass = 'score-excellent';
                            textClass = 'text-excellent';
                            word = <?= json_encode(JAVASCRIPT_SCORE_EXCELLENT) ?>;
                        } else if (percentage >= 0.5) {
                            statusClass = 'score-good';
                            textClass = 'text-good';
                            word = <?= json_encode(JAVASCRIPT_SCORE_GOOD) ?>;
                        }

                        return `
                            <div class="seo-score-card">
                                <div class="seo-score-circle ${statusClass}">
                                    ${points}
                                </div>
                                <div class="seo-score-content">
                                    <h4 class="seo-score-title">${title} &bull; <span class="${textClass}">${word}</span></h4>
                                    <p class="seo-score-text">${description}</p>
                                </div>
                            </div>
                        `;
                    };

                    // Meta Title Length (Max 20 points)
                    var titleLen = metaTitle.length;
                    var titlePts = 0;
                    var titleMsg = <?= json_encode(JAVASCRIPT_META_TITLE_BASE) ?>.replace('%s', titleLen);
                    if (titleLen >= 40 && titleLen <= 60) {
                        titlePts = 20;
                        titleMsg += <?= json_encode(JAVASCRIPT_META_TITLE_OPTIMAL) ?>;
                    } else if (titleLen > 0 && titleLen < 40) {
                        titlePts = 10;
                        titleMsg += <?= json_encode(JAVASCRIPT_META_TITLE_SHORT) ?>;
                    } else if (titleLen > 60) {
                        titlePts = 5;
                        titleMsg += <?= json_encode(JAVASCRIPT_META_TITLE_LONG) ?>;
                    } else {
                        titleMsg += <?= json_encode(JAVASCRIPT_META_TITLE_NONE) ?>;
                    }
                    totalScore += titlePts;
                    html += createCard(<?= json_encode(JAVASCRIPT_META_TITLE_CARD) ?>, titlePts, 20, titleMsg);

                    // Meta Description Length (Max 20 points)
                    var descLen = metaDesc.length;
                    var descPts = 0;
                    var descMsg = <?= json_encode(JAVASCRIPT_META_DESCRIPTION_BASE) ?>.replace('%s', descLen);
                    if (descLen >= 120 && descLen <= 160) {
                        descPts = 20;
                        descMsg += <?= json_encode(JAVASCRIPT_META_DESCRIPTION_OPTIMAL) ?>;
                    } else if (descLen > 0 && descLen < 120) {
                        descPts = 10;
                        descMsg += <?= json_encode(JAVASCRIPT_META_DESCRIPTION_SHORT) ?>;
                    } else if (descLen > 160) {
                        descPts = 5;
                        descMsg += <?= json_encode(JAVASCRIPT_META_DESCRIPTION_LONG) ?>;
                    } else {
                        descMsg += <?= json_encode(JAVASCRIPT_META_DESCRIPTION_NONE) ?>;
                    }
                    totalScore += descPts;
                    html += createCard(<?= json_encode(JAVASCRIPT_META_DESCRIPTION_CARD) ?>, descPts, 20, descMsg);

                    // Keyword in Title (Max 20 points)
                    var hasKeyInTitle = titleText.includes(focusKeyword);
                    var ktPts = hasKeyInTitle ? 20 : 0;
                    var ktMsg = hasKeyInTitle
                        ? <?= json_encode(JAVASCRIPT_KEYWORD_TITLE_FOUND) ?>.replace('%s', focusKeyword)
                        : <?= json_encode(JAVASCRIPT_KEYWORD_TITLE_MISSING) ?>;
                    html += createCard(<?= json_encode(JAVASCRIPT_KEYWORD_TITLE_CARD) ?>, ktPts, 20, ktMsg);

                    // Keyword in Description (Max 20 points)
                    var hasKeyInDesc = descText.includes(focusKeyword);
                    var kdPts = hasKeyInDesc ? 20 : 0;
                    var kdMsg = hasKeyInDesc ? <?= json_encode(JAVASCRIPT_KEYWORD_DESCRIPTION_FOUND) ?> : <?= json_encode(JAVASCRIPT_KEYWORD_DESCRIPTION_MISSING) ?>;
                    totalScore += kdPts;
                    html += createCard(<?= json_encode(JAVASCRIPT_KEYWORD_DESCRIPTION_CARD) ?>, kdPts, 20, kdMsg);

                    // Keyword in Content (Max 20 points)
                    var contentCount = (contentText.split(focusKeyword).length - 1);
                    var kcPts = 0;
                    var kcMsg = <?= json_encode(JAVASCRIPT_DENSITY_BASE) ?>.replace('%s', contentCount);
                    if (contentCount >= 2 && contentCount <= 6) {
                        kcPts = 20;
                        kcMsg += <?= json_encode(JAVASCRIPT_DENSITY_GOOD) ?>;
                    } else if (contentCount == 1) {
                        kcPts = 10;
                        kcMsg += <?= json_encode(JAVASCRIPT_DENSITY_LOW) ?>;
                    } else if (contentCount > 6) {
                        kcPts = 5;
                        kcMsg += <?= json_encode(JAVASCRIPT_DENSITY_HIGH) ?>;
                    } else {
                        kcMsg += <?= json_encode(JAVASCRIPT_DENSITY_NONE) ?>;
                    }
                    totalScore += kcPts;
                    html += createCard(<?= json_encode(JAVASCRIPT_DENSITY_CARD) ?>, kcPts, 20, kcMsg);

                    // render Total Score at the top
                    var totalColor = totalScore >= 80 ? 'text-excellent' : (totalScore >= 50 ? 'text-good' : 'text-poor');
                    var headerHtml = `
                        <div class="seo-total-wrap">
                            <div class="seo-total-score ${totalColor}">${totalScore} / 100</div>
                            <div class="seo-total-label">` + <?= json_encode(JAVASCRIPT_OVERALL_SCORE) ?> + `</div>
                        </div>
                    `;

                    $resultsContainer.html(headerHtml + html);
                },
                error: function() {
                    $resultsContainer.html('<div class="alert alert-danger">' + <?= json_encode(JAVASCRIPT_ERROR_SERVER) ?> + '</div>');
                },
                complete: function() {
                    $btn.html(<?= json_encode(JAVASSCRIPT_BUTTON_RUN_ANALYSIS) ?>).prop('disabled', false);
                }
            });
        });

        // SEO Audit
        $('#btnRunAudit').on('click', function() {
            var $btn = $(this);
            var $container = $('#audit_results_container');
            var $tbody = $('#audit_results_body');

            $btn.html('<i class="fa fa-spinner fa-spin"></i> ' + <?= json_encode(JAVASCRIPT_SCANNING_DATABASE) ?>).prop('disabled', true);

            $.ajax({
                url: '<?php echo zen_href_link(FILENAME_ZX_SEO_MASTER); ?>',
                type: 'GET',
                data: { action: 'audit_missing_meta' },
                dataType: 'json',
                success: function(response) {
                    $tbody.empty();

                    if (response.success && response.data.length > 0) {
                        $.each(response.data, function(index, item) {
                            var badgeClass = item.type === 'product' ? 'label-info' : 'label-default';
                            var uppercaseType = item.type.charAt(0).toUpperCase() + item.type.slice(1);

                            var tr = '<tr>' +
                                '<td><span class="label ' + badgeClass + '">' + uppercaseType + '</span></td>' +
                                '<td>' + item.id + '</td>' +
                                '<td>' + item.name + '</td>' +
                                '<td class="text-right">' +
                                '<button type="button" class="btn btn-sm btn-success btn-fix-now" data-id="' + item.id + '" data-type="' + item.type + '"><i class="fa fa-wrench"></i> ' + <?= json_encode(JAVASSCRIPT_BUTTON_FIX_NOW) ?> + '</button>' +
                                '</td>' +
                                '</tr>';
                            $tbody.append(tr);
                        });
                        $container.slideDown();
                    } else if (response.success) {
                        $tbody.append('<tr><td colspan="4" class="text-center text-success" style="padding: 30px;"><i class="fa fa-check-circle fa-2x"></i><br>' + <?= json_encode(JAVASCRIPT_AUDIT_EXCELLENT) ?> + '</td></tr>');
                        $container.slideDown();
                    } else {
                        alert(<?= json_encode(JAVASCRIPT_AUDIT_ERROR) ?>);
                    }
                },
                error: function() {
                    alert(<?= json_encode(JAVASCRIPT_AUDIT_NETWORK_ERROR) ?>);
                },
                complete: function() {
                    $btn.html('<i class="fa fa-search"></i> ' + <?= json_encode(JAVASCRIPT_AUDIT_RUN) ?>).prop('disabled', false);
                }
            });
        });

        // handle "Fix Now" button
        $(document).on('click', '.btn-fix-now', function() {
            var id = $(this).data('id');
            var type = $(this).data('type');

            $('#entity_type').val(type);
            $('#entity_id').val(id);

            $('#loadEntityBtn').click();
        });
    });

    // LLMS.txt Flyout
    function toggleLlmPanel() {
        var panel = document.getElementById('llmFlyout');
        var backdrop = document.getElementById('llmBackdrop');

        if (panel.classList.contains('is-open')) {
            panel.classList.remove('is-open');
            backdrop.classList.remove('is-visible');
        } else {
            panel.classList.add('is-open');
            backdrop.classList.add('is-visible');
        }
    }

    function copyLlmsContent() {
        var copyText = document.getElementById("llmsPreview");
        var feedbackMsg = document.getElementById("copy-feedback");

        copyText.select();
        copyText.setSelectionRange(0, 99999);

        function showFeedback() {
            feedbackMsg.style.display = "inline-block";
            setTimeout(function () {
                feedbackMsg.style.display = "none";
            }, 2000);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(copyText.value).then(function () {
                showFeedback();
            }).catch(function (err) {
                console.error('Failed to copy: ', err);
            });
        } else {
            try {
                document.execCommand('copy');
                showFeedback();
            } catch (err) {
                console.error('Fallback copy failed', err);
            }
        }
    }
</script>
