/**
 * Wizard Bundle JS
 *
 * Common wizard functionality. Individual wizard scripts are loaded separately.
 * Receives frsWizardConfig from wp_localize_script().
 *
 * @package FRSLeadPages
 */

(function() {
    'use strict';

    // Store config globally for individual wizard scripts
    window.frsWizardConfig = window.frsWizardConfig || {};

    // Common wizard utilities
    window.frsWizardUtils = {
        /**
         * Show loading state on button
         */
        showLoading: function(btn, text) {
            btn.disabled = true;
            btn.dataset.originalText = btn.innerHTML;
            btn.innerHTML = '<span class="frs-spinner"></span> ' + (text || 'Loading...');
        },

        /**
         * Hide loading state on button
         */
        hideLoading: function(btn) {
            btn.disabled = false;
            if (btn.dataset.originalText) {
                btn.innerHTML = btn.dataset.originalText;
            }
        },

        /**
         * Make AJAX request
         */
        ajax: function(action, data) {
            var config = window.frsWizardConfig;
            var formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', config.nonce);

            for (var key in data) {
                if (data.hasOwnProperty(key)) {
                    formData.append(key, data[key]);
                }
            }

            return fetch(config.ajaxUrl, {
                method: 'POST',
                body: formData
            }).then(function(response) {
                return response.json();
            });
        }
    };

    /**
     * Shared photo uploader for all wizards.
     *
     * Uploads an image File to the WordPress media library via the
     * frs_lp_upload_photo endpoint and returns a real, persistent URL.
     * Replaces the old base64 data-URI approach used across the wizards.
     *
     * @param {File}     file      Image file from a file input.
     * @param {Function} onSuccess Called with the uploaded media URL.
     * @param {Function} onError   Called with an optional error message.
     */
    window.frsLpUploadPhoto = function(file, onSuccess, onError) {
        var config = window.frsWizardConfig || {};
        var fd = new FormData();
        fd.append('action', 'frs_lp_upload_photo');
        fd.append('nonce', config.nonce);
        fd.append('file', file);
        fetch(config.ajaxUrl, { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res && res.success && res.data && res.data.url) {
                    onSuccess(res.data.url);
                } else {
                    onError(res && res.data && res.data.message);
                }
            })
            .catch(function() { onError(); });
    };
})();
