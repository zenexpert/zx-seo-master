<script>
    $(document).ready(function() {
        // UI Toggle
        $('#zxSeoToggleBtn, #zxSeoCloseBtn').on('click', function() {
            $('#zxSeoSidebox').toggleClass('open');
        });

        // Core Analysis Function
        const runAnalysis = () => {
            // get current input values, prioritizing custom data from the sidebox hidden fields
            let customTitle = $('#sidebox_meta_title').val();
            let customDesc = $('#sidebox_meta_desc').val();

            let pNameInput = $('input[name^="products_name["]').first();
            let pName = pNameInput.length ? (pNameInput.val() || '') : '';

            // use Custom Title if it exists; otherwise fallback to DOM Title input; otherwise fallback to Product Name
            let mTitleInput = $('input[name^="metatags_title["]').first();
            let mTitle = customTitle ? customTitle : (mTitleInput.length && mTitleInput.val() ? mTitleInput.val() : pName);

            // use Custom Desc if it exists; otherwise fallback to DOM Desc input
            let mDescInput = $('textarea[name^="metatags_description["]').first();
            let mDesc = customDesc ? customDesc : (mDescInput.length ? (mDescInput.val() || '') : '');

            let kwInput = $('#sidebox_focus_keyword');
            let keyword = kwInput.length ? (kwInput.val() || '').toLowerCase().trim() : '';

            // handle CKEditor dynamically based on the discovered textarea name
            let pDescHtml = '';
            let pDescTextArea = $('textarea[name^="products_description["]').first();
            let textAreaName = pDescTextArea.length ? pDescTextArea.attr('name') : '';

            if (typeof CKEDITOR !== 'undefined' && textAreaName && CKEDITOR.instances[textAreaName]) {
                pDescHtml = CKEDITOR.instances[textAreaName].getData();
            } else if (pDescTextArea.length) {
                pDescHtml = pDescTextArea.val() || '';
            }

            // strip HTML for word counts
            let tempDiv = document.createElement("div");
            tempDiv.innerHTML = pDescHtml;
            let pDescText = tempDiv.textContent || tempDiv.innerText || "";

            // update snippet preview
            $('#snip_title').text(mTitle ? mTitle : <?= json_encode(JAVASCRIPT_SNIPPET_TITLE_DEFAULT) ?>);
            $('#snip_desc').text(mDesc ? mDesc : <?= json_encode(JAVASCRIPT_SNIPPET_DESC_DEFAULT) ?>);

            // HTML helpers
            const renderResult = (selector, condition, goodMsg, badMsg) => {
                let icon = condition ? '<i class="fa fa-check-circle icon-good"></i>' : '<i class="fa fa-times-circle icon-bad"></i>';
                let msg = condition ? goodMsg : badMsg;
                $(selector).html('<div class="analysis-icon">' + icon + '</div><div>' + msg + '</div>');
            };
            const renderWarn = (selector, condition, msg) => {
                let icon = condition ? '<i class="fa fa-exclamation-triangle icon-warn"></i>' : '<i class="fa fa-check-circle icon-good"></i>';
                $(selector).html('<div class="analysis-icon">' + icon + '</div><div>' + msg + '</div>');
            };

            // Basic SEO
            let words = pDescText.match(/\b[-?(\w+)?]+\b/gi);
            let wordCount = words ? words.length : 0;
            renderResult('#res_content_length', wordCount >= 300,
                <?= json_encode(JAVASCRIPT_CONTENT_LENGTH_GOOD) ?>.replace('%s', wordCount),
                <?= json_encode(JAVASCRIPT_CONTENT_LENGTH_BAD) ?>.replace('%s', wordCount));

            // Link Analysis (Internal vs External)
            let internalCount = 0;
            let externalCount = 0;
            let currentDomain = window.location.hostname;

            $(tempDiv).find('a').each(function() {
                let href = $(this).attr('href');
                if (href) {
                    if (href.startsWith('http') && !href.includes(currentDomain)) {
                        externalCount++;
                    } else {
                        internalCount++;
                    }
                }
            });

            renderResult('#res_internal_links', internalCount > 0,
                <?= json_encode(JAVASCRIPT_INTERNAL_LINKS_GOOD) ?>.replace('%s', internalCount),
                <?= json_encode(JAVASCRIPT_INTERNAL_LINKS_BAD) ?>);
            renderResult('#res_external_links', externalCount > 0,
                <?= json_encode(JAVASCRIPT_EXTERNAL_LINKS_GOOD) ?>.replace('%s', externalCount),
                <?= json_encode(JAVASCRIPT_EXTERNAL_LINKS_BAD) ?>);

            // Keyword Density
            if (keyword) {
                let keywordRegex = new RegExp('\\b' + keyword + '\\b', 'gi');
                let keywordMatches = pDescText.match(keywordRegex);
                let kwCount = keywordMatches ? keywordMatches.length : 0;
                renderResult('#res_keyword_density', kwCount > 0,
                    <?= json_encode(JAVASCRIPT_KEYWORD_DENSITY_GOOD) ?>.replace('%s', kwCount),
                    <?= json_encode(JAVASCRIPT_KEYWORD_DENSITY_BAD) ?>);
            } else {
                $('#res_keyword_density').html('<div class="analysis-icon"><i class="fa fa-info-circle" style="color:#aaa;"></i></div><div>' + <?= json_encode(JAVASCRIPT_KEYWORD_DENSITY_INFO) ?> + '</div>');
            }

            // Title & Meta
            renderResult('#res_title_length', mTitle.length >= 40 && mTitle.length <= 60,
                <?= json_encode(JAVASCRIPT_TITLE_LENGTH_GOOD) ?>.replace('%s', mTitle.length),
                <?= json_encode(JAVASCRIPT_TITLE_LENGTH_BAD) ?>.replace('%s', mTitle.length));

            renderResult('#res_desc_length', mDesc.length >= 120 && mDesc.length <= 160,
                <?= json_encode(JAVASCRIPT_DESC_LENGTH_GOOD) ?>.replace('%s', mDesc.length),
                <?= json_encode(JAVASCRIPT_DESC_LENGTH_BAD) ?>.replace('%s', mDesc.length));

            if (keyword) {
                renderResult('#res_title_keyword', mTitle.toLowerCase().includes(keyword),
                    <?= json_encode(JAVASCRIPT_TITLE_KEYWORD_GOOD) ?>,
                    <?= json_encode(JAVASCRIPT_TITLE_KEYWORD_BAD) ?>);
            } else {
                $('#res_title_keyword').html('');
            }

            // Readability
            let sentences = pDescText.split(/[.!?]+/).filter(Boolean);
            let longSentences = 0;
            sentences.forEach(function(s) {
                let w = s.match(/\b[-?(\w+)?]+\b/gi);
                if (w && w.length > 20) longSentences++;
            });

            let longPercentage = sentences.length > 0 ? (longSentences / sentences.length) * 100 : 0;
            renderWarn('#res_sentence_length', longPercentage > 25,
                <?= json_encode(JAVASCRIPT_SENTENCE_LENGTH_BASE) ?>.replace('%s', longPercentage.toFixed(1)) + (longPercentage > 25 ? <?= json_encode(JAVASCRIPT_SENTENCE_LENGTH_BAD) ?> : <?= json_encode(JAVASCRIPT_SENTENCE_LENGTH_GOOD) ?>));

            renderWarn('#res_readability', wordCount < 50,
                <?= json_encode(JAVASCRIPT_READABILITY_BAD) ?>);
        };

        // bind the analysis to inputs dynamically using prefix selectors
        $('#sidebox_focus_keyword').on('input keyup', runAnalysis);
        $(document).on('input keyup', 'input[name^="products_name["], input[name^="metatags_title["], textarea[name^="metatags_description["]', runAnalysis);

        // if CKEditor is fully loaded, bind to its change event dynamically
        if (typeof CKEDITOR !== 'undefined') {
            CKEDITOR.on('instanceReady', function(evt) {
                if (evt.editor.name && evt.editor.name.startsWith('products_description[')) {
                    evt.editor.on('change', runAnalysis);
                }
            });
        } else {
            $(document).on('input keyup', 'textarea[name^="products_description["]', runAnalysis);
        }

        // run once on load
        setTimeout(runAnalysis, 500);
    });
</script>
