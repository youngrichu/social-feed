(function($) {
    'use strict';

    // Initialize frontend functionality
    $(document).ready(function() {
        initializeSocialFeed();
        initializeSocialStreams();
    });

    /**
     * Initialize social feed functionality
     */
    function initializeSocialFeed() {
        const $feed = $('.social-feed');
        if (!$feed.length) {
            return;
        }

        const settings = $feed.data('settings');
        let currentPage = 1;
        let loading = false;

        // Handle layout toggle
        $feed.find('.layout-button').on('click', function() {
            const $button = $(this);
            const layout = $button.data('layout');

            $button.addClass('active')
                .siblings().removeClass('active');

            $feed.find('.social-feed-items')
                .removeClass('grid list masonry')
                .addClass(layout);

            if (layout === 'masonry') {
                initializeMasonry();
            }
        });

        // Handle filters
        $feed.find('.filter-option input').on('change', function() {
            currentPage = 1;
            loadFeedItems(true);
        });

        // Handle load more
        $feed.find('.load-more').on('click', function() {
            if (loading) {
                return;
            }
            currentPage++;
            loadFeedItems(false);
        });

        // Initialize Masonry layout if active
        if (settings.layout === 'masonry') {
            initializeMasonry();
        }

        /**
         * Load feed items
         *
         * @param {boolean} replace Whether to replace existing items
         */
        function loadFeedItems(replace) {
            const $items = $feed.find('.social-feed-items');
            const $pagination = $feed.find('.social-feed-pagination');
            const $loadMore = $pagination.find('.load-more');

            // Get selected platforms and types
            const platforms = [];
            $feed.find('input[name="platform[]"]:checked').each(function() {
                platforms.push($(this).val());
            });

            const types = [];
            $feed.find('input[name="type[]"]:checked').each(function() {
                types.push($(this).val());
            });

            loading = true;
            $loadMore.prop('disabled', true)
                .text('Loading...');

            // Make API request
            $.ajax({
                url: socialFeed.ajaxUrl + '/feeds',
                headers: {
                    'Authorization': 'Bearer ' + socialFeed.nonce
                },
                data: {
                    platform: platforms,
                    type: types,
                    page: currentPage,
                    per_page: settings.per_page,
                    sort: settings.sort,
                    order: settings.order
                },
                success: function(response) {
                    if (response.status === 'success' && response.data && response.data.items) {
                        // Filter out duplicates
                        const existingIds = new Set();
                        if (!replace) {
                            $items.find('.feed-item').each(function() {
                                existingIds.add($(this).data('id'));
                            });
                        }

                        const newItems = response.data.items.filter(item => {
                            if (!item || !item.content || !item.content.id) {
                                console.error('Invalid item:', item);
                                return false;
                            }
                            if (existingIds.has(item.content.id)) {
                                return false;
                            }
                            existingIds.add(item.content.id);
                            return true;
                        });

                        const html = newItems.map(renderFeedItem).join('');
                        
                        if (replace) {
                            $items.html(html);
                        } else {
                            $items.append(html);
                        }

                        // Update pagination
                        if (currentPage >= response.data.pagination.total_pages) {
                            $pagination.hide();
                        } else {
                            $pagination.show();
                        }

                        // Reinitialize masonry if active
                        if (settings.layout === 'masonry') {
                            initializeMasonry();
                        }
                    } else {
                        console.error('Invalid response:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading feed items:', error);
                },
                complete: function() {
                    loading = false;
                    $loadMore.prop('disabled', false)
                        .text('Load More');
                }
            });
        }

        /**
         * Initialize masonry layout
         */
        function initializeMasonry() {
            if (typeof Masonry === 'undefined') {
                return;
            }

            const $items = $feed.find('.social-feed-items');
            new Masonry($items[0], {
                itemSelector: '.feed-item',
                columnWidth: '.feed-item',
                percentPosition: true
            });
        }

        /**
         * Render feed item
         *
         * @param {Object} item
         * @returns {string}
         */
        function renderFeedItem(item) {
            // Ensure we have the required data
            if (!item || !item.content || !item.author) {
                console.error('Invalid feed item:', item);
                return '';
            }

            const content = item.content;
            const author = item.author;

            return `
                <div class="feed-item" data-platform="${item.platform}" data-type="${item.type}" data-id="${item.id}">
                    <div class="feed-item-header">
                        <div class="author-info">
                            ${author.avatar ? 
                                `<img src="${author.avatar}" alt="${author.name}" class="author-avatar">` : 
                                ''
                            }
                            <div class="author-details">
                                ${author.name ? 
                                    `<a href="${author.profile_url}" target="_blank" class="author-name">
                                        ${author.name}
                                    </a>` : 
                                    ''
                                }
                                <span class="platform-badge">
                                    ${item.platform.charAt(0).toUpperCase() + item.platform.slice(1)}
                                </span>
                            </div>
                        </div>
                        <time datetime="${content.created_at}" class="post-date">
                            ${timeSince(new Date(content.created_at))} ago
                        </time>
                    </div>
                    <div class="feed-item-media">
                        ${item.type === 'video' || item.type === 'short' ?
                            `<div class="video-wrapper">
                                <img src="${content.thumbnail_url}" alt="${content.title}">
                                <a href="${content.media_url}" target="_blank" class="play-button">
                                    <span class="dashicons dashicons-controls-play"></span>
                                </a>
                            </div>` :
                            `<img src="${content.thumbnail_url}" alt="${content.title}">`
                        }
                    </div>
                    <div class="feed-item-content">
                        <h4>${content.title}</h4>
                        <p>${truncateText(content.description, 20)}</p>
                    </div>
                    <div class="feed-item-footer">
                        <div class="engagement">
                            <span class="engagement-stat">
                                👍 ${numberFormat(content.engagement.likes || 0)}
                            </span>
                            <span class="engagement-stat">
                                💬 ${numberFormat(content.engagement.comments || 0)}
                            </span>
                            ${content.engagement.shares ?
                                `<span class="engagement-stat">
                                    🔄 ${numberFormat(content.engagement.shares)}
                                </span>` :
                                ''
                            }
                        </div>
                        <a href="${content.original_url}" target="_blank" class="view-original">
                            View Original
                        </a>
                    </div>
                </div>
            `;
        }
    }

    /**
     * Initialize social streams functionality
     */
    function initializeSocialStreams() {
        const $streams = $('.social-streams');
        if (!$streams.length) {
            return;
        }

        const settings = $streams.data('settings');
        let currentPage = 1;
        let loading = false;

        // Handle filters
        $streams.find('.filter-option input').on('change', function() {
            currentPage = 1;
            loadStreamItems(true);
        });

        // Handle load more
        $streams.find('.load-more').on('click', function() {
            if (loading) {
                return;
            }
            currentPage++;
            loadStreamItems(false);
        });

        // Auto-refresh live streams
        setInterval(function() {
            if ($streams.find('.stream-item[data-status="live"]').length) {
                loadStreamItems(true);
            }
        }, 60000); // Every minute

        /**
         * Load stream items
         *
         * @param {boolean} replace Whether to replace existing items
         */
        function loadStreamItems(replace) {
            const $items = $streams.find('.stream-items');
            const $pagination = $streams.find('.stream-pagination');
            const $loadMore = $pagination.find('.load-more');

            // Get selected platforms and status
            const platforms = [];
            $streams.find('input[name="platform[]"]:checked').each(function() {
                platforms.push($(this).val());
            });

            const status = $streams.find('input[name="status"]:checked').val();

            loading = true;
            $loadMore.prop('disabled', true)
                .text('Loading...');

            // Make API request
            $.ajax({
                url: socialFeed.ajaxUrl + '/live-streams',
                headers: {
                    'Authorization': 'Bearer ' + socialFeed.nonce
                },
                data: {
                    platform: platforms,
                    status: status,
                    page: currentPage,
                    per_page: settings.per_page
                },
                success: function(response) {
                    if (response.status === 'success') {
                        const html = response.data.streams.map(renderStreamItem).join('');
                        
                        if (replace) {
                            $items.html(html);
                        } else {
                            $items.append(html);
                        }

                        // Update pagination
                        if (currentPage >= response.data.pagination.total_pages) {
                            $pagination.hide();
                        } else {
                            $pagination.show();
                        }
                    }
                },
                error: function() {
                    console.error('Error loading stream items');
                },
                complete: function() {
                    loading = false;
                    $loadMore.prop('disabled', false)
                        .text('Load More');
                }
            });
        }

        /**
         * Render stream item
         *
         * @param {Object} stream
         * @returns {string}
         */
        function renderStreamItem(stream) {
            return `
                <div class="stream-item" data-platform="${stream.platform}" data-status="${stream.status}">
                    <div class="stream-thumbnail">
                        <img src="${stream.thumbnail_url}" alt="${stream.title}">
                        ${stream.status === 'live' ?
                            `<span class="live-badge">LIVE</span>
                            <span class="viewer-count">
                                👥 ${numberFormat(stream.viewer_count)}
                            </span>` :
                            stream.status === 'upcoming' ?
                            `<span class="upcoming-badge">
                                ${timeSince(new Date(), new Date(stream.scheduled_for))} until live
                            </span>` :
                            ''
                        }
                    </div>
                    <div class="stream-info">
                        <div class="channel-info">
                            <img src="${stream.channel.avatar}" alt="${stream.channel.name}" class="channel-avatar">
                            <span class="channel-name">
                                ${stream.channel.name}
                            </span>
                            <span class="platform-badge">
                                ${stream.platform.charAt(0).toUpperCase() + stream.platform.slice(1)}
                            </span>
                        </div>
                        <h4 class="stream-title">
                            <a href="${stream.stream_url}" target="_blank">
                                ${stream.title}
                            </a>
                        </h4>
                        <p class="stream-description">
                            ${truncateText(stream.description, 30)}
                        </p>
                    </div>
                </div>
            `;
        }
    }

    /**
     * Format number with commas
     *
     * @param {number} num
     * @returns {string}
     */
    function numberFormat(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Get time since date
     *
     * @param {Date} date
     * @param {Date} [now]
     * @returns {string}
     */
    function timeSince(date, now = new Date()) {
        const seconds = Math.floor((now - date) / 1000);
        let interval = Math.floor(seconds / 31536000);

        if (interval > 1) {
            return interval + ' years';
        }
        interval = Math.floor(seconds / 2592000);
        if (interval > 1) {
            return interval + ' months';
        }
        interval = Math.floor(seconds / 86400);
        if (interval > 1) {
            return interval + ' days';
        }
        interval = Math.floor(seconds / 3600);
        if (interval > 1) {
            return interval + ' hours';
        }
        interval = Math.floor(seconds / 60);
        if (interval > 1) {
            return interval + ' minutes';
        }
        return Math.floor(seconds) + ' seconds';
    }

    /**
     * Truncate text to specified number of words
     *
     * @param {string} text
     * @param {number} words
     * @returns {string}
     */
    function truncateText(text, words) {
        const array = text.trim().split(' ');
        const ellipsis = array.length > words ? '...' : '';
        return array.slice(0, words).join(' ') + ellipsis;
    }
})(jQuery); 