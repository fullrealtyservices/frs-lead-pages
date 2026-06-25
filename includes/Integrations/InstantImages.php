<?php
/**
 * Instant Images Integration
 *
 * Provides stock photo search functionality via Instant Images plugin.
 * Allows wizards to search and select stock photos from Unsplash, Pexels, Pixabay, etc.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\Integrations;

class InstantImages {

    /**
     * Check if Instant Images plugin is active
     *
     * @return bool
     */
    public static function is_active(): bool {
        return class_exists( 'InstantImages' );
    }

    /**
     * Get localized data for Instant Images
     *
     * @return array
     */
    public static function get_localized_data(): array {
        if ( ! self::is_active() ) {
            return [];
        }

        $settings = \InstantImages::instant_img_get_settings();

        // Get API keys
        $unsplash_api = defined( 'INSTANT_IMAGES_UNSPLASH_KEY' ) ? INSTANT_IMAGES_UNSPLASH_KEY : $settings->unsplash_api;
        $pixabay_api = defined( 'INSTANT_IMAGES_PIXABAY_KEY' ) ? INSTANT_IMAGES_PIXABAY_KEY : $settings->pixabay_api;
        $pexels_api = defined( 'INSTANT_IMAGES_PEXELS_KEY' ) ? INSTANT_IMAGES_PEXELS_KEY : $settings->pexels_api;

        return [
            'active'            => true,
            'root'              => esc_url_raw( rest_url() ),
            'nonce'             => wp_create_nonce( 'wp_rest' ),
            'proxy'             => 'https://proxy.getinstantimages.com/api/',
            'version'           => defined( 'INSTANT_IMAGES_VERSION' ) ? INSTANT_IMAGES_VERSION : '7.2.0',
            'download_width'    => esc_html( $settings->max_width ),
            'download_height'   => esc_html( $settings->max_height ),
            'default_provider'  => esc_html( $settings->default_provider ),
            'unsplash_app_id'   => $unsplash_api,
            'pixabay_app_id'    => $pixabay_api,
            'pexels_app_id'     => $pexels_api,
            'providers'         => self::get_available_providers( $unsplash_api, $pixabay_api, $pexels_api ),
        ];
    }

    /**
     * Get available providers based on API keys
     *
     * @param string $unsplash Unsplash API key
     * @param string $pixabay Pixabay API key
     * @param string $pexels Pexels API key
     * @return array
     */
    private static function get_available_providers( string $unsplash, string $pixabay, string $pexels ): array {
        $providers = [];

        // Unsplash only
        $providers['unsplash'] = [
            'name'      => 'Unsplash',
            'slug'      => 'unsplash',
            'available' => true,
            'api_url'   => 'https://api.unsplash.com/search/photos',
        ];

        return $providers;
    }

    /**
     * Render the stock image search modal HTML
     *
     * @param string $prefix CSS prefix for this wizard
     * @param string $target_input_id ID of the hidden input to update with selected image URL
     * @return string
     */
    public static function render_search_modal( string $prefix, string $target_input_id ): string {
        if ( ! self::is_active() ) {
            return '';
        }

        $data = self::get_localized_data();

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $prefix ); ?>-instant-images-modal" class="<?php echo esc_attr( $prefix ); ?>-ii-modal">
            <div class="<?php echo esc_attr( $prefix ); ?>-ii-modal__backdrop"></div>
            <div class="<?php echo esc_attr( $prefix ); ?>-ii-modal__container">
                <div class="<?php echo esc_attr( $prefix ); ?>-ii-modal__header">
                    <h3>Search Stock Photos</h3>
                    <button type="button" class="<?php echo esc_attr( $prefix ); ?>-ii-modal__close" aria-label="Close">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
                <div class="<?php echo esc_attr( $prefix ); ?>-ii-modal__body">
                    <div class="<?php echo esc_attr( $prefix ); ?>-ii-search">
                        <div class="<?php echo esc_attr( $prefix ); ?>-ii-search__input-wrap">
                            <input type="text"
                                   id="<?php echo esc_attr( $prefix ); ?>-ii-search-input"
                                   placeholder="Search for images (e.g., 'family home', 'new house')"
                                   class="<?php echo esc_attr( $prefix ); ?>-ii-search__input">
                            <button type="button" class="<?php echo esc_attr( $prefix ); ?>-ii-search__btn">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <path d="m21 21-4.35-4.35"></path>
                                </svg>
                                Search
                            </button>
                        </div>
                        <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>-ii-provider" value="unsplash">
                    </div>
                    <div id="<?php echo esc_attr( $prefix ); ?>-ii-results" class="<?php echo esc_attr( $prefix ); ?>-ii-results">
                        <div class="<?php echo esc_attr( $prefix ); ?>-ii-empty">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                            <p>Search for stock photos above</p>
                        </div>
                    </div>
                    <div id="<?php echo esc_attr( $prefix ); ?>-ii-loading" class="<?php echo esc_attr( $prefix ); ?>-ii-loading" style="display:none;">
                        <div class="<?php echo esc_attr( $prefix ); ?>-ii-spinner"></div>
                        <p>Searching...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the CSS styles for the search modal
     *
     * @param string $prefix CSS prefix for this wizard
     * @param string $accent_color Primary accent color
     * @return string
     */
    public static function render_search_styles( string $prefix, string $accent_color = '#10b981' ): string {
        return '
        <style>
            .' . $prefix . '-ii-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 999999;
                display: none;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .' . $prefix . '-ii-modal--open {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .' . $prefix . '-ii-modal__backdrop {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
            }
            .' . $prefix . '-ii-modal__container {
                position: relative;
                width: 90vw;
                max-width: 1000px;
                max-height: 85vh;
                background: #fff;
                border-radius: 16px;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            }
            .' . $prefix . '-ii-modal__header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 20px 24px;
                border-bottom: 1px solid #e5e7eb;
            }
            .' . $prefix . '-ii-modal__header h3 {
                font-size: 20px;
                font-weight: 700;
                color: #0f172a;
                margin: 0;
            }
            .' . $prefix . '-ii-modal__close {
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f1f5f9;
                border: none;
                border-radius: 10px;
                cursor: pointer;
                color: #64748b;
                transition: all 0.2s;
            }
            .' . $prefix . '-ii-modal__close:hover {
                background: #e2e8f0;
                color: #0f172a;
            }
            .' . $prefix . '-ii-modal__body {
                flex: 1;
                min-height: 0;
                display: flex;
                flex-direction: column;
                padding: 24px;
                overflow: hidden;
            }
            .' . $prefix . '-ii-search {
                margin-bottom: 20px;
            }
            .' . $prefix . '-ii-search__input-wrap {
                display: flex;
                gap: 12px;
                margin-bottom: 12px;
            }
            .' . $prefix . '-ii-search__input {
                flex: 1;
                padding: 14px 18px;
                font-size: 16px;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                outline: none;
            }
            .' . $prefix . '-ii-search__input:focus {
                border-color: ' . $accent_color . ';
            }
            .' . $prefix . '-ii-search__btn {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 14px 24px;
                font-size: 16px;
                font-weight: 600;
                color: #fff;
                background: ' . $accent_color . ';
                border: none;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .' . $prefix . '-ii-search__btn:hover {
                filter: brightness(0.9);
            }
            .' . $prefix . '-ii-providers {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .' . $prefix . '-ii-provider {
                cursor: pointer;
            }
            .' . $prefix . '-ii-provider input {
                position: absolute;
                opacity: 0;
            }
            .' . $prefix . '-ii-provider span {
                display: inline-block;
                padding: 8px 16px;
                font-size: 14px;
                font-weight: 500;
                color: #64748b;
                background: #f1f5f9;
                border: 2px solid transparent;
                border-radius: 8px;
                transition: all 0.2s;
            }
            .' . $prefix . '-ii-provider input:checked + span {
                color: ' . $accent_color . ';
                background: #ecfdf5;
                border-color: ' . $accent_color . ';
            }
            .' . $prefix . '-ii-results {
                flex: 1;
                min-height: 0;
                overflow-y: auto;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 16px;
                padding: 4px;
            }
            .' . $prefix . '-ii-empty {
                grid-column: 1 / -1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 60px 24px;
                color: #94a3b8;
            }
            .' . $prefix . '-ii-empty p {
                margin: 16px 0 0;
                font-size: 16px;
            }
            .' . $prefix . '-ii-loading {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 60px 24px;
            }
            .' . $prefix . '-ii-spinner {
                width: 40px;
                height: 40px;
                border: 3px solid #e5e7eb;
                border-top-color: ' . $accent_color . ';
                border-radius: 50%;
                animation: ' . $prefix . '-spin 0.8s linear infinite;
            }
            @keyframes ' . $prefix . '-spin {
                to { transform: rotate(360deg); }
            }
            .' . $prefix . '-ii-loading p {
                margin: 16px 0 0;
                color: #64748b;
            }
            .' . $prefix . '-ii-image {
                aspect-ratio: 4/3;
                border-radius: 10px;
                overflow: hidden;
                cursor: pointer;
                position: relative;
                background: #f1f5f9;
            }
            .' . $prefix . '-ii-image img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                transition: transform 0.3s;
            }
            .' . $prefix . '-ii-image:hover img {
                transform: scale(1.05);
            }
            .' . $prefix . '-ii-image__overlay {
                position: absolute;
                inset: 0;
                background: linear-gradient(to top, rgba(0,0,0,0.6) 0%, transparent 50%);
                display: flex;
                align-items: flex-end;
                padding: 12px;
                opacity: 0;
                transition: opacity 0.2s;
            }
            .' . $prefix . '-ii-image:hover .' . $prefix . '-ii-image__overlay {
                opacity: 1;
            }
            .' . $prefix . '-ii-image__select {
                width: 100%;
                padding: 10px;
                font-size: 14px;
                font-weight: 600;
                color: #fff;
                background: ' . $accent_color . ';
                border: none;
                border-radius: 6px;
                cursor: pointer;
            }
            .' . $prefix . '-ii-image--loading .' . $prefix . '-ii-image__select {
                opacity: 0.7;
                cursor: wait;
            }
            .' . $prefix . '-ii-no-results {
                grid-column: 1 / -1;
                text-align: center;
                padding: 40px;
                color: #64748b;
            }
        </style>';
    }

    /**
     * Render the JavaScript for the search modal
     *
     * @param string $prefix CSS prefix for this wizard
     * @param string $target_input_id ID of the hidden input to update with selected image URL
     * @param string $grid_id ID of the image grid container
     * @return string
     */
    public static function render_search_scripts( string $prefix, string $target_input_id, string $grid_id ): string {
        if ( ! self::is_active() ) {
            return '';
        }

        $data = self::get_localized_data();

        return '
        <script>
        (function() {
            const iiModal = document.getElementById("' . $prefix . '-instant-images-modal");
            if (!iiModal) return;

            const iiConfig = ' . wp_json_encode( $data ) . ';
            const searchInput = document.getElementById("' . $prefix . '-ii-search-input");
            const searchBtn = iiModal.querySelector(".' . $prefix . '-ii-search__btn");
            const resultsContainer = document.getElementById("' . $prefix . '-ii-results");
            const loadingEl = document.getElementById("' . $prefix . '-ii-loading");

            // Open/close modal
            const openBtn = document.getElementById("' . $prefix . '-ii-open-btn");
            const closeBtn = iiModal.querySelector(".' . $prefix . '-ii-modal__close");
            const backdrop = iiModal.querySelector(".' . $prefix . '-ii-modal__backdrop");

            if (openBtn) {
                openBtn.addEventListener("click", () => {
                    iiModal.classList.add("' . $prefix . '-ii-modal--open");
                });
            }

            closeBtn.addEventListener("click", closeModal);
            backdrop.addEventListener("click", closeModal);

            function closeModal() {
                iiModal.classList.remove("' . $prefix . '-ii-modal--open");
            }

            // Search functionality
            async function searchImages(query) {
                const provider = iiModal.querySelector("input[name=\'' . $prefix . '-ii-provider\']").value;

                resultsContainer.innerHTML = "";
                resultsContainer.style.display = "none";
                loadingEl.style.display = "flex";

                try {
                    // Route every provider through the Instant Images proxy
                    // (proxy.getinstantimages.com) so no per-site API keys are needed
                    // and there are no CORS issues. The proxy normalizes the response.
                    const proxy = iiConfig.proxy || "https://proxy.getinstantimages.com/api/";
                    const url = proxy + provider
                        + "?type=photos&term=" + encodeURIComponent(query)
                        + "&page=1&order=latest&version=" + encodeURIComponent(iiConfig.version || "7.2.0");

                    const response = await fetch(url);
                    const data = await response.json();

                    const results = (data.results || []).map(function(img) {
                        const urls = img.urls || {};
                        return {
                            id: img.id,
                            thumb: urls.thumb || urls.img || urls.full || "",
                            full: urls.full || urls.img || urls.thumb || "",
                            download: urls.download_url || urls.full || urls.img || "",
                            alt: img.alt || img.title || query,
                            author: (img.user && img.user.name) || img.attribution || "Unknown",
                            provider: provider
                        };
                    });

                    displayResults(results, provider);
                } catch (error) {
                    console.error("Search error:", error);
                    resultsContainer.innerHTML = "<div class=\"' . $prefix . '-ii-no-results\">Error searching images. Please try again.</div>";
                }

                loadingEl.style.display = "none";
                resultsContainer.style.display = "grid";
            }

            async function searchUnsplash(query) {
                // If no API key, use curated Unsplash images based on search term
                if (!iiConfig.unsplash_app_id) {
                    // Generate curated results using Unsplash Source (no API required)
                    const searchTerms = query.toLowerCase().split(" ");
                    const baseUrls = [];

                    // Real estate and home related curated collections
                    const collections = {
                        "home": "1065976",
                        "house": "1065976",
                        "family": "3330445",
                        "couple": "3330445",
                        "keys": "1065976",
                        "moving": "1065976",
                        "veteran": "3694365",
                        "military": "3694365",
                        "first": "1065976",
                        "buyer": "1065976",
                        "real": "1065976",
                        "estate": "1065976",
                        "default": "1065976"
                    };

                    let collectionId = collections.default;
                    for (const term of searchTerms) {
                        if (collections[term]) {
                            collectionId = collections[term];
                            break;
                        }
                    }

                    // Generate 12 unique image URLs from the collection
                    const results = [];
                    for (let i = 0; i < 12; i++) {
                        const seed = query + i;
                        const url = `https://source.unsplash.com/collection/${collectionId}/800x600?sig=${encodeURIComponent(seed)}`;
                        results.push({
                            id: `unsplash-${i}-${Date.now()}`,
                            thumb: url,
                            full: url.replace("800x600", "1600x1200"),
                            download: url.replace("800x600", "1920x1280"),
                            alt: query,
                            author: "Unsplash",
                            provider: "unsplash-direct"
                        });
                    }
                    return results;
                }

                const response = await fetch(
                    `https://api.unsplash.com/search/photos?query=${encodeURIComponent(query)}&per_page=20&client_id=${iiConfig.unsplash_app_id}`
                );
                const data = await response.json();

                return (data.results || []).map(img => ({
                    id: img.id,
                    thumb: img.urls.small,
                    full: img.urls.regular,
                    download: img.urls.full,
                    alt: img.alt_description || query,
                    author: img.user.name,
                    provider: "unsplash"
                }));
            }

            async function searchPixabay(query) {
                if (!iiConfig.pixabay_app_id) return [];

                const response = await fetch(
                    `https://pixabay.com/api/?key=${iiConfig.pixabay_app_id}&q=${encodeURIComponent(query)}&per_page=20&image_type=photo`
                );
                const data = await response.json();

                return (data.hits || []).map(img => ({
                    id: img.id,
                    thumb: img.webformatURL,
                    full: img.webformatURL,
                    download: img.largeImageURL,
                    alt: img.tags || query,
                    author: img.user,
                    provider: "pixabay"
                }));
            }

            async function searchPexels(query) {
                if (!iiConfig.pexels_app_id) return [];

                const response = await fetch(
                    `https://api.pexels.com/v1/search?query=${encodeURIComponent(query)}&per_page=20`,
                    { headers: { Authorization: iiConfig.pexels_app_id } }
                );
                const data = await response.json();

                return (data.photos || []).map(img => ({
                    id: img.id,
                    thumb: img.src.medium,
                    full: img.src.large,
                    download: img.src.original,
                    alt: img.alt || query,
                    author: img.photographer,
                    provider: "pexels"
                }));
            }

            async function searchOpenverse(query) {
                const response = await fetch(
                    `https://api.openverse.org/v1/images/?q=${encodeURIComponent(query)}&page_size=20`
                );
                const data = await response.json();

                return (data.results || []).map(img => ({
                    id: img.id,
                    thumb: img.thumbnail || img.url,
                    full: img.url,
                    download: img.url,
                    alt: img.title || query,
                    author: img.creator || "Unknown",
                    provider: "openverse"
                }));
            }

            function displayResults(results, provider) {
                if (results.length === 0) {
                    resultsContainer.innerHTML = "<div class=\"' . $prefix . '-ii-no-results\">No images found. Try a different search term.</div>";
                    return;
                }

                resultsContainer.innerHTML = results.map(img => `
                    <div class="' . $prefix . '-ii-image" data-img=\'${JSON.stringify(img)}\'>
                        <img src="${img.thumb}" alt="${img.alt}" loading="lazy">
                        <div class="' . $prefix . '-ii-image__overlay">
                            <button type="button" class="' . $prefix . '-ii-image__select">Select Image</button>
                        </div>
                    </div>
                `).join("");

                // Add click handlers
                resultsContainer.querySelectorAll(".' . $prefix . '-ii-image").forEach(el => {
                    el.addEventListener("click", () => selectImage(el));
                });
            }

            async function selectImage(el) {
                const imgData = JSON.parse(el.dataset.img);
                const selectBtn = el.querySelector(".' . $prefix . '-ii-image__select");

                el.classList.add("' . $prefix . '-ii-image--loading");
                selectBtn.textContent = "Downloading...";

                try {
                    let imageUrl;

                    // For direct URLs (no API key), use the URL directly
                    if (imgData.provider === "unsplash-direct") {
                        imageUrl = imgData.download;

                        // Update the hidden input and image grid directly
                        const targetInput = document.getElementById("' . $target_input_id . '");
                        const imageGrid = document.getElementById("' . $grid_id . '");

                        if (targetInput) {
                            targetInput.value = imageUrl;
                        }

                        if (imageGrid) {
                            // Deselect all other images
                            imageGrid.querySelectorAll(".cs-image-option, .oh-image-option, .se-image-option, .mc-image-option, .' . $prefix . '-image-option").forEach(opt => {
                                opt.classList.remove("cs-image-option--selected", "oh-image-option--selected", "se-image-option--selected", "mc-image-option--selected", "' . $prefix . '-image-option--selected");
                            });
                            // Also handle radio inputs in grid
                            imageGrid.querySelectorAll("input[type=radio]").forEach(r => r.checked = false);

                            // Add the new image to the grid
                            const newImageDiv = document.createElement("div");
                            newImageDiv.className = "' . $prefix . '-image-option ' . $prefix . '-image-option--selected";
                            newImageDiv.dataset.url = imageUrl;
                            newImageDiv.innerHTML = `<img src="${imageUrl}" alt="Selected stock image">`;
                            imageGrid.insertBefore(newImageDiv, imageGrid.firstChild);

                            // Make it clickable
                            newImageDiv.addEventListener("click", () => {
                                imageGrid.querySelectorAll(".' . $prefix . '-image-option").forEach(o => o.classList.remove("' . $prefix . '-image-option--selected"));
                                newImageDiv.classList.add("' . $prefix . '-image-option--selected");
                                targetInput.value = newImageDiv.dataset.url;
                            });
                        }

                        closeModal();
                    } else {
                        // Download image via Instant Images REST API
                        const response = await fetch(iiConfig.root + "instant-images/download", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                                "X-WP-Nonce": iiConfig.nonce
                            },
                            body: JSON.stringify({
                                provider: imgData.provider,
                                id: imgData.id,
                                image_url: imgData.download,
                                filename: `stock-${imgData.provider}-${imgData.id}`,
                                extension: "jpg",
                                title: imgData.alt,
                                alt: imgData.alt,
                                caption: `Photo by ${imgData.author} via ${imgData.provider}`,
                                custom_filename: "",
                                lang: "",
                                parent_id: 0
                            })
                        });

                        const result = await response.json();

                        if (result.success && result.attachment) {
                            imageUrl = result.attachment.url;

                            // Update the hidden input and image grid
                            const targetInput = document.getElementById("' . $target_input_id . '");
                            const imageGrid = document.getElementById("' . $grid_id . '");

                            if (targetInput) {
                                targetInput.value = imageUrl;
                            }

                            if (imageGrid) {
                                // Deselect all other images
                                imageGrid.querySelectorAll(".cs-image-option, .oh-image-option, .se-image-option, .mc-image-option, .' . $prefix . '-image-option").forEach(opt => {
                                    opt.classList.remove("cs-image-option--selected", "oh-image-option--selected", "se-image-option--selected", "mc-image-option--selected", "' . $prefix . '-image-option--selected");
                                });

                                // Add the new image to the grid
                                const newImageDiv = document.createElement("div");
                                newImageDiv.className = "' . $prefix . '-image-option ' . $prefix . '-image-option--selected";
                                newImageDiv.dataset.url = imageUrl;
                                newImageDiv.innerHTML = `<img src="${imageUrl}" alt="Selected stock image">`;
                                imageGrid.insertBefore(newImageDiv, imageGrid.firstChild);

                                // Make it clickable
                                newImageDiv.addEventListener("click", () => {
                                    imageGrid.querySelectorAll(".' . $prefix . '-image-option").forEach(o => o.classList.remove("' . $prefix . '-image-option--selected"));
                                    newImageDiv.classList.add("' . $prefix . '-image-option--selected");
                                    targetInput.value = newImageDiv.dataset.url;
                                });
                            }

                            closeModal();
                        } else {
                            throw new Error(result.msg || "Download failed");
                        }
                    }
                } catch (error) {
                    console.error("Download error:", error);
                    alert("Failed to download image. Please try again.");
                }

                el.classList.remove("' . $prefix . '-ii-image--loading");
                selectBtn.textContent = "Select Image";
            }

            // Event listeners
            searchBtn.addEventListener("click", () => {
                const query = searchInput.value.trim();
                if (query) searchImages(query);
            });

            searchInput.addEventListener("keypress", (e) => {
                if (e.key === "Enter") {
                    e.preventDefault();
                    const query = searchInput.value.trim();
                    if (query) searchImages(query);
                }
            });

            // Expose open function globally
            window["' . $prefix . 'OpenInstantImages"] = function() {
                iiModal.classList.add("' . $prefix . '-ii-modal--open");
            };
        })();
        </script>';
    }

    /**
     * Render the "Search Stock Photos" button
     *
     * @param string $prefix CSS prefix for this wizard
     * @param string $accent_color Primary accent color
     * @return string
     */
    public static function render_search_button( string $prefix, string $accent_color = '#10b981' ): string {
        if ( ! self::is_active() ) {
            return '';
        }

        return '
        <button type="button" id="' . $prefix . '-ii-open-btn" class="' . $prefix . '-ii-btn" style="
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 600;
            color: ' . $accent_color . ';
            background: #fff;
            border: 2px solid ' . $accent_color . ';
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
        ">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.35-4.35"></path>
            </svg>
            Search Stock Photos
        </button>';
    }
}
