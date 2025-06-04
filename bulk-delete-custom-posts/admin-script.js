jQuery(document).ready(function($) {
    // Configuration from PHP
    const ajaxUrl = bdcpPluginData.ajax_url;
    const findPostsNonce = bdcpPluginData.find_posts_nonce;
    const deleteBatchNonce = bdcpPluginData.delete_batch_nonce;
    const postTypeTaxonomies = bdcpPluginData.postTypeTaxonomies || {};
    const confirmDeleteText = bdcpPluginData.confirm_delete_text || 'Are you sure you want to delete these posts?';

    // Form Elements
    const $form = $('#bdcp-form');
    const $postTypeSelect = $('#bdcp_post_type');
    const $taxonomySelect = $('#bdcp_taxonomy');
    const $slugTermInput = $('#bdcp_slug_term');
    const $batchSizeInput = $('#bdcp_batch_size');
    const $batchPauseInput = $('#bdcp_batch_pause');
    const $dryRunCheckbox = $('#bdcp_dry_run');
    const $deleteEmptyTermsCheckbox = $('#bdcp_delete_empty_terms');

    // Buttons
    const $findPostsButton = $('#bdcp-find-posts-button');
    const $deletePostsButton = $('#bdcp-delete-posts-button');

    // Results & Logging Area
    const $resultsArea = $('#bdcp-results-area');
    const $messagesDiv = $('#bdcp-messages');
    const $progressBarContainer = $('#bdcp-progress-bar-container');
    const $progressBar = $('#bdcp-progress-bar');
    const $logArea = $('#bdcp-log-area');
    const $logList = $('#bdcp-log-list');

    let postsToProcess = []; // Array of post IDs to be processed/deleted
    let currentPostType = '';
    let currentTaxonomy = '';
    let currentSlugTerm = '';

    function updateLog(message, type = 'info') {
        $logArea.show();
        const item = $('<li>').html(`[${new Date().toLocaleTimeString()}] ${message}`);
        if (type === 'error') {
            item.css('color', 'red');
        }
        $logList.prepend(item);
    }

    function populatePostTypeDropdown() {
        $postTypeSelect.find('option:not(:first)').remove();
        if (Object.keys(postTypeTaxonomies).length > 0) {
            $.each(postTypeTaxonomies, function(postTypeName, data) {
                if (data.taxonomies && data.taxonomies.length > 0) {
                    $postTypeSelect.append($('<option>', {
                        value: postTypeName,
                        text: data.label + ' (' + postTypeName + ')'
                    }));
                }
            });
        }
        const preSelectedPostType = $postTypeSelect.data('current') || $postTypeSelect.val();
        if (preSelectedPostType) {
            $postTypeSelect.val(preSelectedPostType);
        }
        $postTypeSelect.trigger('change');
    }

    function updateTaxonomyDropdown() {
        const selectedPostType = $postTypeSelect.val();
        $taxonomySelect.find('option:not(:first)').remove();
        $taxonomySelect.find('option:first').text(bdcpPluginData.selectTaxonomyText || '-- Select a Taxonomy --');

        if (selectedPostType && postTypeTaxonomies[selectedPostType] && postTypeTaxonomies[selectedPostType].taxonomies.length > 0) {
            $.each(postTypeTaxonomies[selectedPostType].taxonomies, function(index, tax) {
                $taxonomySelect.append($('<option>', {
                    value: tax.name,
                    text: tax.label + ' (' + tax.name + ')'
                }));
            });
        } else if (selectedPostType) {
            $taxonomySelect.find('option:first').text(bdcpPluginData.noTaxonomyText || '-- No public taxonomies --');
        } else {
            $taxonomySelect.find('option:first').text('-- Select a Post Type First --');
        }
        const preSelectedTaxonomy = $taxonomySelect.data('current') || $taxonomySelect.val();
        if (preSelectedTaxonomy) {
            $taxonomySelect.val(preSelectedTaxonomy);
        }
    }

    function showMessage(htmlMessage, isError = false) {
        $resultsArea.show();
        $messagesDiv.html(htmlMessage);
        if (isError) {
            $messagesDiv.css('color', 'red');
        } else {
            $messagesDiv.css('color', '');
        }
        updateLog(htmlMessage.replace(/(<([^>]+)>)/ig,""), isError ? 'error' : 'info'); // Log stripped message
    }

    function resetResultsArea() {
        $messagesDiv.html(`<p>${bdcpPluginData.initialMessagesText || 'Click "Find Posts" to see which posts match your criteria.'}</p>`).css('color', '');
        $progressBarContainer.hide();
        $progressBar.width('0%').text('0%');
        $deletePostsButton.hide();
        postsToProcess = [];
    }

    $findPostsButton.on('click', function() {
        currentPostType = $postTypeSelect.val();
        currentTaxonomy = $taxonomySelect.val();
        currentSlugTerm = $slugTermInput.val();
        const deleteEmptyTerms = $deleteEmptyTermsCheckbox.is(':checked');

        if (!currentPostType || !currentTaxonomy) {
            showMessage('Please select a Post Type and a Taxonomy.', true);
            return;
        }

        resetResultsArea();
        $resultsArea.show();
        showMessage('Finding posts...');
        $findPostsButton.prop('disabled', true);
        $deletePostsButton.hide();

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'bdcp_find_posts',
                nonce: findPostsNonce,
                post_type: currentPostType,
                taxonomy: currentTaxonomy,
                slug_term: currentSlugTerm,
                delete_empty_terms: deleteEmptyTerms
            },
            success: function(response) {
                if (response.success) {
                    let messageHtml = response.data.message;
                    postsToProcess = response.data.posts.map(p => p.id);
                    messageHtml += `<br><strong>Found ${response.data.count} posts.</strong>`;
                    if (response.data.count > 0) {
                        messageHtml += '<ul style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 5px; margin-top:5px;">';
                        response.data.posts.forEach(function(post) {
                            messageHtml += `<li>${post.title} (ID: ${post.id})</li>`;
                        });
                        messageHtml += '</ul>';
                        if (!$dryRunCheckbox.prop('checked')) {
                           $deletePostsButton.show();
                        }
                    } else {
                        $deletePostsButton.hide();
                    }
                    showMessage(messageHtml);
                } else {
                    showMessage(response.data.message || 'Error finding posts.', true);
                    $deletePostsButton.hide();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                showMessage(`AJAX Error: ${textStatus} - ${errorThrown}`, true);
                $deletePostsButton.hide();
            },
            complete: function() {
                $findPostsButton.prop('disabled', false);
            }
        });
    });

    $deletePostsButton.on('click', function() {
        if ($dryRunCheckbox.prop('checked')) {
            showMessage('Dry Run is enabled. Uncheck it to delete posts.', true);
            return;
        }

        if (postsToProcess.length === 0) {
            showMessage('No posts selected for deletion. Please run "Find Posts" first.', true);
            return;
        }

        if (!confirm(confirmDeleteText)) {
            return;
        }

        const batchSize = parseInt($batchSizeInput.val()) || 50;
        const batchPause = parseInt($batchPauseInput.val()) * 1000 || 1000; // in ms
        let totalDeleted = 0;
        let totalErrors = 0;
        let currentBatch = 0;
        const totalBatches = Math.ceil(postsToProcess.length / batchSize);

        $progressBarContainer.show();
        $deletePostsButton.prop('disabled', true);
        $findPostsButton.prop('disabled', true);
        showMessage('Starting deletion process...');
        updateLog(`Starting deletion. Total posts: ${postsToProcess.length}, Batch size: ${batchSize}, Pause: ${batchPause/1000}s`);

        function processNextBatch() {
            if (postsToProcess.length === 0) {
                const finalMessage = `Deletion complete. Total Deleted: ${totalDeleted}, Total Errors: ${totalErrors}`;
                showMessage(finalMessage);
                updateLog(finalMessage + ". Process finished.");
                $progressBar.css('width', '100%').text('100% Complete');
                $deletePostsButton.prop('disabled', false).hide();
                $findPostsButton.prop('disabled', false);
                return;
            }

            currentBatch++;
            const batchPostIDs = postsToProcess.splice(0, batchSize);
            const progress = Math.round(((currentBatch-1) * batchSize + (batchSize - batchPostIDs.length)) / (totalBatches * batchSize) * 100);
            $progressBar.css('width', progress + '%').text(progress + '%');
            updateLog(`Processing batch ${currentBatch}/${totalBatches} with ${batchPostIDs.length} posts.`);

            // Determine if this is the last batch
            const isLastBatch = postsToProcess.length === 0;

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bdcp_delete_batch',
                    nonce: deleteBatchNonce,
                    post_ids: batchPostIDs,
                    is_last_batch: isLastBatch // Send the flag
                },
                success: function(response) {
                    let batchMsg = '';
                    if (response.success) {
                        totalDeleted += response.data.deleted_count || 0;
                        totalErrors += response.data.error_count || 0;
                        batchMsg = response.data.message;
                        if(response.data.details && response.data.details.length > 0){
                            response.data.details.forEach(detail => updateLog(` - ${detail}`));
                        }
                    } else {
                        totalErrors += batchPostIDs.length; // Assume all in batch failed if request fails hard
                        batchMsg = response.data.message || `Error processing batch ${currentBatch}.`;
                         updateLog(`Batch ${currentBatch} failed: ${batchMsg}`, 'error');
                    }
                    showMessage(`Batch ${currentBatch}/${totalBatches}: ${batchMsg}<br>Total Deleted: ${totalDeleted}, Errors: ${totalErrors}`);
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    totalErrors += batchPostIDs.length;
                    const errorMsg = `AJAX Error on batch ${currentBatch}: ${textStatus} - ${errorThrown}`;
                    showMessage(errorMsg, true);
                    updateLog(errorMsg, 'error');
                },
                complete: function() {
                    if (postsToProcess.length > 0) {
                        if (batchPause > 0) {
                            setTimeout(processNextBatch, batchPause);
                        } else {
                            processNextBatch();
                        }
                    } else {
                        processNextBatch(); // Call once more to finalize
                    }
                }
            });
        }
        processNextBatch(); // Start the first batch
    });

    // Event listener for the dry run checkbox
    $dryRunCheckbox.on('change', function() {
        if ($(this).prop('checked')) {
            $deletePostsButton.hide(); // Hide delete button if dry run is checked
            showMessage('Dry Run mode enabled. Posts will be listed but not deleted.');
        } else {
            if (postsToProcess.length > 0) {
                $deletePostsButton.show(); // Show delete button if dry run is unchecked and posts are found
                 showMessage('Dry Run mode disabled. Posts will be deleted if you proceed.', true);
            }
        }
    });

    // Event listener for the "Find Posts" button
    $( '#bdcp-find-posts-button' ).on( 'click', function() {
        currentPostIds = []; // Reset post IDs
        updateLog( 'Initiating find posts...' );
        $( '#bdcp-results-area' ).show();
        $( '#bdcp-messages' ).html( '<p>Fetching posts... <span class="spinner is-active" style="float:none; vertical-align: middle;"></span></p>' );
        $( '#bdcp-delete-posts-button' ).hide();
        $( '#bdcp-progress-bar-container' ).hide();
        $( '#bdcp-progress-bar' ).width( '0%' ).text( '0%' );
        $( '#bdcp-log-area' ).fadeIn();

        const postType = $( '#bdcp_post_type' ).val();
        const taxonomy = $( '#bdcp_taxonomy' ).val();
        const slugTerm = $( '#bdcp_slug_term' ).val();
        const deleteEmptyTerms = $( '#bdcp_delete_empty_terms' ).is( ':checked' ); 

        if ( !postType || !taxonomy ) {
            showMessage( 'Error: Post Type and Taxonomy must be selected.', 'error', true );
            return;
        }

        const data = {
            action: 'bdcp_find_posts',
            nonce: bdcpPluginData.find_posts_nonce,
            post_type: postType,
            taxonomy: taxonomy,
            slug_term: slugTerm,
            delete_empty_terms: deleteEmptyTerms // Send the flag to backend
        };

        $.post( bdcpPluginData.ajax_url, data )
            .done(function( response ) {
                if ( response.success ) {
                    let messageHtml = response.data.message;
                    postsToProcess = response.data.posts.map(p => p.id);
                    messageHtml += `<br><strong>Found ${response.data.count} posts.</strong>`;
                    if (response.data.count > 0) {
                        messageHtml += '<ul style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 5px; margin-top:5px;">';
                        response.data.posts.forEach(function(post) {
                            messageHtml += `<li>${post.title} (ID: ${post.id})</li>`;
                        });
                        messageHtml += '</ul>';
                        if (!$dryRunCheckbox.prop('checked')) {
                           $deletePostsButton.show();
                        }
                    } else {
                        $deletePostsButton.hide();
                    }
                    showMessage(messageHtml);
                } else {
                    showMessage(response.data.message || 'Error finding posts.', true);
                    $deletePostsButton.hide();
                }
            })
            .fail(function( jqXHR, textStatus, errorThrown ) {
                showMessage( `AJAX Error: ${textStatus} - ${errorThrown}`, true );
                $deletePostsButton.hide();
            })
            .always(function() {
                $findPostsButton.prop('disabled', false);
            });
    });

    // Initial setup
    populatePostTypeDropdown();
    $postTypeSelect.on('change', updateTaxonomyDropdown);
    resetResultsArea(); // Set initial message
    updateLog('Bulk Delete Custom Posts interface loaded.');

});