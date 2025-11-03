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
                <div class="feed-item" data-platform="${sanitizeHtml(item.platform)}" data-type="${sanitizeHtml(item.type)}" data-id="${sanitizeHtml(item.id)}">
                    <div class="feed-item-header">
                        <div class="author-info">
                            ${author.avatar ? 
                                `<img src="${sanitizeUrl(author.avatar)}" alt="${sanitizeHtml(author.name)}" class="author-avatar">` : 
                                ''
                            }
                            <div class="author-details">
                                ${author.name ? 
                                    `<a href="${sanitizeUrl(author.profile_url)}" target="_blank" class="author-name">
                                        ${sanitizeHtml(author.name)}
                                    </a>` : 
                                    ''
                                }
                                <span class="platform-badge">
                                    ${sanitizeHtml(item.platform.charAt(0).toUpperCase() + item.platform.slice(1))}
                                </span>
                            </div>
                        </div>
                        <time datetime="${sanitizeHtml(content.created_at)}" class="post-date">
                            ${sanitizeHtml(timeSince(new Date(content.created_at)))} ago
                        </time>
                    </div>
                    <div class="feed-item-media">
                        ${item.type === 'video' || item.type === 'short' ?
                            `<div class="video-wrapper">
                                <img src="${sanitizeUrl(content.thumbnail_url)}" alt="${sanitizeHtml(content.title)}">
                                <a href="${sanitizeUrl(content.media_url)}" target="_blank" class="play-button">
                                    <span class="dashicons dashicons-controls-play"></span>
                                </a>
                            </div>` :
                            `<img src="${sanitizeUrl(content.thumbnail_url)}" alt="${sanitizeHtml(content.title)}">`
                        }
                    </div>
                    <div class="feed-item-content">
                        <h4>${sanitizeHtml(content.title)}</h4>
                        <p>${sanitizeHtml(truncateText(content.description, 20))}</p>
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
                        <a href="${sanitizeUrl(content.original_url)}" target="_blank" class="view-original">
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
                <div class="stream-item" data-platform="${sanitizeHtml(stream.platform)}" data-status="${sanitizeHtml(stream.status)}">
                    <div class="stream-thumbnail">
                        <img src="${sanitizeUrl(stream.thumbnail_url)}" alt="${sanitizeHtml(stream.title)}">
                        ${stream.status === 'live' ?
                            `<span class="live-badge">LIVE</span>
                            <span class="viewer-count">
                                👥 ${numberFormat(stream.viewer_count)}
                            </span>` :
                            stream.status === 'upcoming' ?
                            `<span class="upcoming-badge">
                                ${sanitizeHtml(timeSince(new Date(), new Date(stream.scheduled_for)))} until live
                            </span>` :
                            ''
                        }
                    </div>
                    <div class="stream-info">
                        <div class="channel-info">
                            <img src="${sanitizeUrl(stream.channel.avatar)}" alt="${sanitizeHtml(stream.channel.name)}" class="channel-avatar">
                            <span class="channel-name">
                                ${sanitizeHtml(stream.channel.name)}
                            </span>
                            <span class="platform-badge">
                                ${sanitizeHtml(stream.platform.charAt(0).toUpperCase() + stream.platform.slice(1))}
                            </span>
                        </div>
                        <h4 class="stream-title">
                            <a href="${sanitizeUrl(stream.stream_url)}" target="_blank">
                                ${sanitizeHtml(stream.title)}
                            </a>
                        </h4>
                        <p class="stream-description">
                            ${sanitizeHtml(truncateText(stream.description, 30))}
                        </p>
                    </div>
                </div>
            `;
        }
    }

    /**
     * Sanitize HTML content to prevent XSS attacks
     *
     * @param {string} str
     * @returns {string}
     */
    function sanitizeHtml(str) {
        if (typeof str !== 'string') {
            return '';
        }
        
        const entityMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        };
        
        return str.replace(/[&<>"'`=\/]/g, function (s) {
            return entityMap[s];
        });
    }

    /**
     * Validate and sanitize URLs to prevent malicious links
     *
     * @param {string} url
     * @returns {string}
     */
    function sanitizeUrl(url) {
        if (typeof url !== 'string') {
            return '#';
        }
        
        // Remove any potential javascript: or data: protocols
        const cleanUrl = url.trim();
        const lowerUrl = cleanUrl.toLowerCase();
        
        // Allow only http, https, and relative URLs
        if (lowerUrl.startsWith('javascript:') || 
            lowerUrl.startsWith('data:') || 
            lowerUrl.startsWith('vbscript:') ||
            lowerUrl.startsWith('file:') ||
            lowerUrl.includes('<script') ||
            lowerUrl.includes('javascript')) {
            return '#';
        }
        
        // If it's a relative URL or starts with http/https, it's probably safe
        if (cleanUrl.startsWith('/') || 
            cleanUrl.startsWith('./') || 
            cleanUrl.startsWith('../') ||
            cleanUrl.startsWith('http://') || 
            cleanUrl.startsWith('https://')) {
            return cleanUrl;
        }
        
        // For other cases, prepend https:// if it looks like a domain
        if (cleanUrl.includes('.') && !cleanUrl.includes(' ')) {
            return 'https://' + cleanUrl;
        }
        
        return '#';
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
        // Safety check for input
        if (typeof text !== 'string') {
            return '';
        }
        
        // Ensure words is a positive number
        const wordLimit = Math.max(1, parseInt(words) || 20);
        
        const array = text.trim().split(' ');
        const ellipsis = array.length > wordLimit ? '...' : '';
        return array.slice(0, wordLimit).join(' ') + ellipsis;
    }

    /**
     * Initialize carousel functionality
     */
    function initializeCarousel() {
        $('.social-feed-carousel').each(function() {
            const $carousel = $(this);
            const $track = $carousel.find('.carousel-track');
            const $slides = $track.find('.carousel-slide');
            const $prevBtn = $carousel.find('.carousel-prev');
            const $nextBtn = $carousel.find('.carousel-next');
            const $dotsContainer = $carousel.find('.carousel-dots');
            
            if ($slides.length === 0) {
                return;
            }

            const settings = $carousel.data();
            const slidesToShow = parseInt(settings.slidesToShow) || 3;
            const autoplay = settings.autoplay === 'true';
            
            let currentIndex = 0;
            let totalSlides = $slides.length;
            let slidesToShowCurrent = slidesToShow;
            let autoplayInterval;
            let isTransitioning = false;

            // Responsive slides calculation
            function updateSlidesToShow() {
                const width = $(window).width();
                if (width <= 768) {
                    slidesToShowCurrent = 1;
                } else if (width <= 1024) {
                    slidesToShowCurrent = Math.min(2, slidesToShow);
                } else {
                    slidesToShowCurrent = Math.min(slidesToShow, totalSlides);
                }
            }

            // Create dots
            function createDots() {
                $dotsContainer.empty();
                const totalDots = Math.ceil(totalSlides / slidesToShowCurrent);
                for (let i = 0; i < totalDots; i++) {
                    const $dot = $('<button class="carousel-dot"></button>');
                    if (i === 0) $dot.addClass('active');
                    $dotsContainer.append($dot);
                }
            }

            // Update carousel position
            function updateCarousel(animate = true) {
                if (isTransitioning) return;
                
                isTransitioning = true;
                const translateX = -(currentIndex * (100 / slidesToShowCurrent));
                
                if (animate) {
                    $track.css('transform', `translateX(${translateX}%)`);
                    setTimeout(() => {
                        isTransitioning = false;
                    }, 500);
                } else {
                    $track.addClass('no-transition');
                    $track.css('transform', `translateX(${translateX}%)`);
                    setTimeout(() => {
                        $track.removeClass('no-transition');
                        isTransitioning = false;
                    }, 50);
                }

                // Update navigation buttons
                const maxIndex = Math.max(0, totalSlides - slidesToShowCurrent);
                $prevBtn.prop('disabled', currentIndex === 0);
                $nextBtn.prop('disabled', currentIndex >= maxIndex);

                // Update dots
                const dotIndex = Math.floor(currentIndex / slidesToShowCurrent);
                $dotsContainer.find('.carousel-dot')
                    .removeClass('active')
                    .eq(dotIndex)
                    .addClass('active');
            }

            // Navigate to specific slide
            function goToSlide(index, animate = true) {
                const maxIndex = Math.max(0, totalSlides - slidesToShowCurrent);
                currentIndex = Math.max(0, Math.min(index, maxIndex));
                updateCarousel(animate);
            }

            // Next slide
            function nextSlide() {
                const maxIndex = Math.max(0, totalSlides - slidesToShowCurrent);
                if (currentIndex < maxIndex) {
                    goToSlide(currentIndex + 1);
                } else if (autoplay) {
                    // Loop back to start for autoplay
                    goToSlide(0);
                }
            }

            // Previous slide
            function prevSlide() {
                if (currentIndex > 0) {
                    goToSlide(currentIndex - 1);
                }
            }

            // Autoplay functionality
            function startAutoplay() {
                if (!autoplay) return;
                autoplayInterval = setInterval(nextSlide, 4000);
            }

            function stopAutoplay() {
                if (autoplayInterval) {
                    clearInterval(autoplayInterval);
                }
            }

            // Event listeners
            $prevBtn.on('click', prevSlide);
            $nextBtn.on('click', nextSlide);

            // Dot navigation
            $dotsContainer.on('click', '.carousel-dot', function() {
                const dotIndex = $(this).index();
                goToSlide(dotIndex * slidesToShowCurrent);
            });

            // Touch/swipe support
            let startX = 0;
            let currentX = 0;
            let isDragging = false;
            let startTransform = 0;

            $track.on('mousedown touchstart', function(e) {
                if (isTransitioning) return;
                
                isDragging = true;
                $track.addClass('dragging');
                
                const clientX = e.type === 'mousedown' ? e.clientX : e.originalEvent.touches[0].clientX;
                startX = clientX;
                
                const transform = $track.css('transform');
                const matrix = new DOMMatrix(transform);
                startTransform = matrix.m41; // translateX value
                
                stopAutoplay();
            });

            $(document).on('mousemove touchmove', function(e) {
                if (!isDragging) return;
                
                e.preventDefault();
                const clientX = e.type === 'mousemove' ? e.clientX : e.originalEvent.touches[0].clientX;
                currentX = clientX;
                
                const deltaX = currentX - startX;
                const newTransform = startTransform + deltaX;
                
                $track.css('transform', `translateX(${newTransform}px)`);
            });

            $(document).on('mouseup touchend', function() {
                if (!isDragging) return;
                
                isDragging = false;
                $track.removeClass('dragging');
                
                const deltaX = currentX - startX;
                const threshold = 50; // Minimum swipe distance
                
                if (Math.abs(deltaX) > threshold) {
                    if (deltaX > 0) {
                        prevSlide();
                    } else {
                        nextSlide();
                    }
                } else {
                    // Snap back to current position
                    updateCarousel();
                }
                
                if (autoplay) {
                    startAutoplay();
                }
            });

            // Keyboard navigation
            $carousel.on('keydown', function(e) {
                switch(e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        prevSlide();
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        nextSlide();
                        break;
                }
            });

            // Pause autoplay on hover
            $carousel.on('mouseenter', stopAutoplay);
            $carousel.on('mouseleave', function() {
                if (autoplay) {
                    startAutoplay();
                }
            });

            // Handle window resize
            $(window).on('resize', function() {
                updateSlidesToShow();
                createDots();
                goToSlide(0, false); // Reset to first slide on resize
            });

            // Initialize
            updateSlidesToShow();
            createDots();
            updateCarousel(false);
            
            if (autoplay) {
                startAutoplay();
            }

            // Make carousel focusable for keyboard navigation
            $carousel.attr('tabindex', '0');
        });
    }

    // Global initialization function that can be called anytime
    window.socialFeedInitCarousel = function() {
        if (typeof jQuery !== 'undefined') {
            initializeCarousel();
        } else {
            // Fallback: try again in 100ms if jQuery isn't loaded yet
            setTimeout(window.socialFeedInitCarousel, 100);
        }
    };

    // Initialize carousel when document is ready
    $(document).ready(function() {
        initializeCarousel();
    });

    // Also initialize when new content is added (for dynamic loading)
    $(document).on('DOMNodeInserted', function(e) {
        if ($(e.target).find('.social-feed-carousel').length > 0 || $(e.target).hasClass('social-feed-carousel')) {
            setTimeout(initializeCarousel, 100);
        }
    });

    // Modern MutationObserver fallback for better performance
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            const $node = $(node);
                            if ($node.find('.social-feed-carousel').length > 0 || $node.hasClass('social-feed-carousel')) {
                                setTimeout(initializeCarousel, 100);
                            }
                        }
                    });
                }
            });
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

})(jQuery);

// Fallback initialization for when jQuery might not be available immediately
(function() {
    function tryInit() {
        if (typeof jQuery !== 'undefined' && typeof window.socialFeedInitCarousel === 'function') {
            window.socialFeedInitCarousel();
        } else {
            setTimeout(tryInit, 100);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryInit);
    } else {
        tryInit();
    }
})();