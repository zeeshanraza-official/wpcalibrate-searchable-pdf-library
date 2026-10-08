/**
 * WPCalibrate Searchable PDF Library - Frontend Script
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const containers = document.querySelectorAll('.wpcal-spl-container');
        if (!containers.length) {
            return;
        }

        containers.forEach(function (container) {
            initLibraryContainer(container);
        });
    });

    function initLibraryContainer(container) {
        const searchInput = container.querySelector('#wpcal-search-input');
        const catSelect   = container.querySelector('#wpcal-filter-cat');
        const tagSelect   = container.querySelector('#wpcal-filter-tag');
        const sortSelect  = container.querySelector('#wpcal-sort-select');
        const resetBtn    = container.querySelector('#wpcal-reset-filters');
        const layoutBtns  = container.querySelectorAll('.wpcal-layout-btn');
        const resultsWrap = container.querySelector('#wpcal-results-grid');
        const statusBar   = container.querySelector('.wpcal-spl-status-bar');
        const countSpan   = container.querySelector('.wpcal-spl-count');
        const spinner     = container.querySelector('.wpcal-spl-spinner');

        // Modal elements
        const modal        = container.querySelector('#wpcal-pdf-modal');
        const modalTitle   = container.querySelector('#wpcal-modal-title');
        const modalIframe  = container.querySelector('#wpcal-modal-iframe');
        const modalDownload= container.querySelector('#wpcal-modal-download');

        let debounceTimer = null;
        let currentPage   = 1;
        let isFetching    = false;

        // Debounced search input
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    currentPage = 1;
                    fetchResults();
                }, 350);
            });

            // Prevent enter key from full form submit during live search
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(debounceTimer);
                    currentPage = 1;
                    fetchResults();
                }
            });
        }

        // Filters change
        if (catSelect) {
            catSelect.addEventListener('change', function () {
                currentPage = 1;
                fetchResults();
            });
        }

        if (tagSelect) {
            tagSelect.addEventListener('change', function () {
                currentPage = 1;
                fetchResults();
            });
        }

        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                currentPage = 1;
                fetchResults();
            });
        }

        // Reset button
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (searchInput) searchInput.value = '';
                if (catSelect) catSelect.value = '';
                if (tagSelect) tagSelect.value = '';
                if (sortSelect) sortSelect.value = 'relevance';
                resetBtn.style.display = 'none';
                currentPage = 1;
                fetchResults();
            });
        }

        // Layout toggle
        layoutBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const layout = btn.getAttribute('data-layout');
                layoutBtns.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');

                container.setAttribute('data-layout', layout);
                resultsWrap.classList.remove('wpcal-layout-grid', 'wpcal-layout-list');
                resultsWrap.classList.add('wpcal-layout-' + layout);
            });
        });

        // Event delegation for View trigger / modal
        container.addEventListener('click', function (e) {
            const trigger = e.target.closest('.wpcal-view-trigger');
            if (trigger) {
                const behavior = trigger.getAttribute('data-behavior') || 'modal';
                if (behavior === 'modal') {
                    e.preventDefault();
                    openViewerModal(trigger);
                }
            }

            // Pagination links
            const pageLink = e.target.closest('.wpcal-page-link');
            if (pageLink && !pageLink.closest('.no-ajax')) {
                e.preventDefault();
                const targetPage = parseInt(pageLink.getAttribute('data-page'), 10);
                if (targetPage > 0) {
                    currentPage = targetPage;
                    fetchResults();
                    container.scrollIntoView({ behavior: 'smooth' });
                }
            }

            // Close modal triggers
            if (e.target.matches('[data-close="modal"]') || e.target.closest('[data-close="modal"]')) {
                closeViewerModal();
            }
        });

        // Close modal on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.style.display !== 'none') {
                closeViewerModal();
            }
        });

        function openViewerModal(trigger) {
            if (!modal) return;
            const url   = trigger.getAttribute('href');
            const title = trigger.getAttribute('data-title') || 'PDF Preview';

            if (modalTitle) modalTitle.textContent = title;
            if (modalIframe) modalIframe.setAttribute('src', url);
            if (modalDownload) modalDownload.setAttribute('href', url.replace('#page=', '&dummy='));

            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Focus on close button for accessibility
            const closeBtn = modal.querySelector('.wpcal-modal-close');
            if (closeBtn) closeBtn.focus();
        }

        function closeViewerModal() {
            if (!modal) return;
            modal.style.display = 'none';
            if (modalIframe) modalIframe.setAttribute('src', '');
            document.body.style.overflow = '';
        }

        // Fetch results via REST API
        function fetchResults() {
            if (typeof wpcalSplData === 'undefined') {
                return;
            }

            const queryVal = searchInput ? searchInput.value.trim() : '';
            const catVal   = catSelect ? catSelect.value : '';
            const tagVal   = tagSelect ? tagSelect.value : '';
            const sortVal  = sortSelect ? sortSelect.value : 'relevance';
            const perPage  = parseInt(container.getAttribute('data-per-page') || '12', 10);

            // Toggle reset button visibility
            if (resetBtn) {
                if (queryVal || catVal || tagVal) {
                    resetBtn.style.display = 'inline-block';
                } else {
                    resetBtn.style.display = 'none';
                }
            }

            if (spinner) spinner.style.display = 'inline-block';
            isFetching = true;

            const url = new URL(wpcalSplData.restUrl + 'search');
            url.searchParams.set('query', queryVal);
            url.searchParams.set('category', catVal);
            url.searchParams.set('tag', tagVal);
            url.searchParams.set('sort', sortVal);
            url.searchParams.set('page', currentPage);
            url.searchParams.set('per_page', perPage);

            fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': wpcalSplData.nonce
                }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                renderResults(data);
            })
            .catch(function (err) {
                console.error('[WPCalibrate PDF Library] Search error:', err);
            })
            .finally(function () {
                if (spinner) spinner.style.display = 'none';
                isFetching = false;
            });
        }

        function renderResults(data) {
            // Update announcer
            if (countSpan) {
                countSpan.textContent = (data.total || 0) + ' ' + wpcalSplData.i18n.resultsMsg;
            }

            if (!resultsWrap) return;

            if (!data.items || !data.items.length) {
                resultsWrap.innerHTML = '<div class="wpcal-spl-empty-state"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg><p>' + escapeHtml(wpcalSplData.i18n.noResults) + '</p></div>';
                updatePagination(0, 1, 1);
                return;
            }

            let html = '';
            data.items.forEach(function (item) {
                html += renderItemCardHtml(item);
            });
            resultsWrap.innerHTML = html;

            updatePagination(data.total, data.page, data.total_pages);
        }

        function updatePagination(total, page, totalPages) {
            let pagNav = container.querySelector('.wpcal-spl-pagination');
            if (totalPages <= 1) {
                if (pagNav) pagNav.remove();
                return;
            }

            if (!pagNav) {
                pagNav = document.createElement('nav');
                pagNav.className = 'wpcal-spl-pagination';
                pagNav.setAttribute('aria-label', 'Document library pages');
                resultsWrap.parentNode.insertBefore(pagNav, modal);
            }

            let navHtml = '';
            if (page > 1) {
                navHtml += '<a href="#" class="wpcal-page-link wpcal-page-prev" data-page="' + (page - 1) + '">&laquo; Previous</a> ';
            }
            navHtml += '<span class="wpcal-page-current">Page ' + page + ' of ' + totalPages + '</span> ';
            if (page < totalPages) {
                navHtml += '<a href="#" class="wpcal-page-link wpcal-page-next" data-page="' + (page + 1) + '">Next &raquo;</a>';
            }

            pagNav.innerHTML = navHtml;
        }

        function renderItemCardHtml(item) {
            let termsHtml = '';
            if (item.categories && item.categories.length) {
                item.categories.forEach(function (c) {
                    termsHtml += '<span class="wpcal-term-pill wpcal-pill-category">' + escapeHtml(c.name) + '</span> ';
                });
            }
            if (item.tags && item.tags.length) {
                item.tags.forEach(function (t) {
                    termsHtml += '<span class="wpcal-term-pill wpcal-pill-tag">#' + escapeHtml(t.name) + '</span> ';
                });
            }

            let snippetHtml = '';
            if (item.highlighted_snippet) {
                let pageBadge = item.matched_page > 0 ? '<span class="wpcal-page-badge">Page ' + item.matched_page + '</span>' : '';
                snippetHtml = '<div class="wpcal-doc-snippet"><blockquote class="wpcal-snippet-text">&ldquo;' + item.highlighted_snippet + '&rdquo;</blockquote>' + pageBadge + '</div>';
            } else if (item.description) {
                snippetHtml = '<p class="wpcal-doc-description">' + escapeHtml(item.description) + '</p>';
            }

            let viewBtn = item.view_url ? '<a href="' + escapeHtml(item.view_url) + '" class="wpcal-btn wpcal-btn-primary wpcal-view-trigger" data-title="' + escapeHtml(item.title) + '" data-behavior="modal"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> View</a>' : '';
            let downloadBtn = item.download_url ? '<a href="' + escapeHtml(item.download_url) + '" class="wpcal-btn wpcal-btn-secondary" download><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Download</a>' : '';

            return '<article class="wpcal-doc-card" data-doc-id="' + item.document_id + '">' +
                '<div class="wpcal-card-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg><span class="wpcal-card-badge">PDF</span></div>' +
                '<div class="wpcal-card-content">' +
                '<h4 class="wpcal-doc-title"><a href="' + escapeHtml(item.view_url || '#') + '" class="wpcal-view-trigger" data-title="' + escapeHtml(item.title) + '" data-behavior="modal">' + escapeHtml(item.title) + '</a></h4>' +
                '<div class="wpcal-doc-meta">' +
                (item.author ? '<span class="wpcal-meta-item"><span class="wpcal-meta-label">Author:</span> ' + escapeHtml(item.author) + '</span>' : '') +
                (item.publication_year ? '<span class="wpcal-meta-item"><span class="wpcal-meta-label">Published:</span> ' + item.publication_year + '</span>' : '') +
                '</div>' +
                (termsHtml ? '<div class="wpcal-doc-terms">' + termsHtml + '</div>' : '') +
                snippetHtml +
                '</div>' +
                '<div class="wpcal-card-actions">' + viewBtn + downloadBtn + '</div>' +
                '</article>';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    }
})();
