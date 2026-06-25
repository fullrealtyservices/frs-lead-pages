/**
 * Mortgage Calculator Wizard Modal Script
 *
 * Requires frsMortgageCalculatorWizard object from wp_localize_script().
 *
 * @package FRSLeadPages
 */

(function() {
    'use strict';

    var config = window.frsMortgageCalculatorWizard || {};
    var triggerClass = config.triggerClass || 'mc-wizard-trigger';
    var triggerHash = config.triggerHash || 'mortgage-calculator-wizard';
    var siteUrl = config.siteUrl || '';
    var ajaxUrl = config.ajaxUrl || '';
    var nonce = config.nonce || '';

    var modal = document.getElementById('mc-wizard-modal');
    var wizard = document.getElementById('mc-wizard');
    if (!modal || !wizard) return;

    var currentStep = 0;
    var totalSteps = 3;
    var selectedPartner = null;
    var selectedOutputType = 'landing_page';

    // Get user data and mode
    var userData = JSON.parse(wizard.dataset.user || "{}");
    var userMode = userData.mode || "realtor";
    var isLoanOfficer = userMode === "loan_officer";

    // Page type card selection (LO mode)
    var pageTypeCards = wizard.querySelectorAll('.mc-page-type-card');
    var pageTypeInput = document.getElementById('mc-page-type');
    var partnerSelectionDiv = document.getElementById('mc-partner-selection');
    var partnerInput = document.getElementById('mc-partner');

    if (pageTypeCards.length > 0 && isLoanOfficer) {
        pageTypeCards.forEach(function(card) {
            card.addEventListener('click', function() {
                // Deselect all cards
                pageTypeCards.forEach(function(c) { c.classList.remove('selected'); });
                // Select clicked card
                card.classList.add('selected');
                var pageType = card.dataset.type;
                if (pageTypeInput) pageTypeInput.value = pageType;

                // Show/hide partner selection
                if (partnerSelectionDiv) {
                    if (pageType === 'cobranded') {
                        partnerSelectionDiv.style.display = 'block';
                    } else {
                        partnerSelectionDiv.style.display = 'none';
                        // Clear partner selection for solo pages
                        if (partnerInput) partnerInput.value = '';
                        selectedPartner = null;
                        var dropdownValue = wizard.querySelector('#mc-partner-dropdown .mc-dropdown__value');
                        if (dropdownValue) dropdownValue.textContent = 'Choose a partner...';
                        wizard.querySelectorAll('#mc-partner-dropdown .mc-dropdown__item').forEach(function(i) { i.classList.remove('is-selected'); });
                    }
                }
            });
        });
    }

    // Open modal
    document.querySelectorAll('.' + triggerClass).forEach(function(btn) {
        btn.addEventListener('click', function() {
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        });
    });

    // Check URL hash
    if (window.location.hash === '#' + triggerHash) {
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    // Close modal
    modal.querySelector('.mc-modal__backdrop').addEventListener('click', closeModal);
    modal.querySelector('.mc-modal__close').addEventListener('click', closeModal);

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    // Skip partner button (LO mode only)
    var skipPartnerBtn = document.getElementById('mc-skip-partner');
    if (skipPartnerBtn) {
        skipPartnerBtn.addEventListener('click', function() {
            selectedPartner = null; // Clear partner
            goToStep(1);
        });
    }

    // Dropdown functionality
    var dropdown = document.getElementById('mc-partner-dropdown');
    if (dropdown) {
        var trigger = dropdown.querySelector('.mc-dropdown__trigger');
        var menu = dropdown.querySelector('.mc-dropdown__menu');
        var items = dropdown.querySelectorAll('.mc-dropdown__item');
        var input = document.getElementById('mc-partner');
        var valueDisplay = dropdown.querySelector('.mc-dropdown__value');
        var isRequired = dropdown.dataset.required === 'true';

        trigger.addEventListener('click', function() {
            dropdown.classList.toggle('is-open');
        });

        items.forEach(function(item) {
            item.addEventListener('click', function() {
                items.forEach(function(i) { i.classList.remove('is-selected'); });
                item.classList.add('is-selected');
                input.value = item.dataset.value;
                selectedPartner = {
                    id: item.dataset.value,
                    name: item.dataset.name,
                    nmls: item.dataset.nmls || '',
                    license: item.dataset.license || '',
                    company: item.dataset.company || '',
                    photo: item.dataset.photo || '',
                    email: item.dataset.email || '',
                    phone: item.dataset.phone || ''
                };
                valueDisplay.innerHTML = '<img src="' + (item.dataset.photo || '') + '" style="width:24px;height:24px;border-radius:50%;margin-right:8px;">' + item.dataset.name;
                dropdown.classList.remove('is-open');
            });
        });

        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('is-open');
            }
        });

        // Auto-select preferred partner if set
        var preferredId = dropdown.dataset.preferred;
        if (preferredId && preferredId !== '0') {
            var preferredItem = dropdown.querySelector('.mc-dropdown__item[data-value="' + preferredId + '"]');
            if (preferredItem) {
                preferredItem.click();
                console.log('MC Wizard: Auto-selected preferred partner ID:', preferredId);
            }
        }
    }

    // Color picker functionality
    var colorStartPicker = document.getElementById('mc-color-start');
    var colorStartHex = document.getElementById('mc-color-start-hex');
    var colorEndPicker = document.getElementById('mc-color-end');
    var colorEndHex = document.getElementById('mc-color-end-hex');
    var gradientPreview = document.getElementById('mc-gradient-preview');

    function updateGradientPreview() {
        var start = colorStartPicker.value;
        var end = colorEndPicker.value;
        gradientPreview.style.background = 'linear-gradient(135deg, ' + start + ' 0%, ' + end + ' 100%)';
    }

    // Sync color picker with hex input
    colorStartPicker.addEventListener('input', function() {
        colorStartHex.value = colorStartPicker.value;
        updateGradientPreview();
    });
    colorStartHex.addEventListener('input', function() {
        if (/^#[0-9A-Fa-f]{6}$/.test(colorStartHex.value)) {
            colorStartPicker.value = colorStartHex.value;
            updateGradientPreview();
        }
    });
    colorEndPicker.addEventListener('input', function() {
        colorEndHex.value = colorEndPicker.value;
        updateGradientPreview();
    });
    colorEndHex.addEventListener('input', function() {
        if (/^#[0-9A-Fa-f]{6}$/.test(colorEndHex.value)) {
            colorEndPicker.value = colorEndHex.value;
            updateGradientPreview();
        }
    });

    // Output type selection (landing page vs embed code)
    document.querySelectorAll('input[name="mc-output-type"]').forEach(function(input) {
        input.addEventListener('change', function(e) {
            selectedOutputType = e.target.value;
            var embedOutput = document.getElementById('mc-embed-output');

            if (selectedOutputType === 'embed_code') {
                // Generate and show embed code
                generateEmbedCode();
                embedOutput.style.display = 'block';
            } else {
                embedOutput.style.display = 'none';
            }

            // Update button text when output type changes
            if (currentStep === totalSteps - 1) {
                updateNextButtonText();
            }
        });
    });

    // Partner headshot + company logo uploads (manual co-brand entry)
    function setupMcPartnerUpload(suffix) {
        var uploadDiv  = document.getElementById('mc-partner-' + suffix + '-upload');
        var fileInput  = document.getElementById('mc-partner-' + suffix + '-file');
        var preview    = document.getElementById('mc-partner-' + suffix + '-preview');
        var previewImg = document.getElementById('mc-partner-' + suffix + '-preview-img');
        var removeBtn  = document.getElementById('mc-partner-' + suffix + '-remove');
        var urlInput   = document.getElementById('mc-partner-' + suffix + '-url');
        if (!uploadDiv || !fileInput) return;
        uploadDiv.addEventListener('click', function() { fileInput.click(); });
        uploadDiv.addEventListener('dragover', function(e) { e.preventDefault(); uploadDiv.style.borderColor = '#2563eb'; });
        uploadDiv.addEventListener('dragleave', function() { uploadDiv.style.borderColor = '#cbd5e1'; });
        uploadDiv.addEventListener('drop', function(e) {
            e.preventDefault();
            uploadDiv.style.borderColor = '#cbd5e1';
            if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileInput.dispatchEvent(new Event('change', { bubbles: true })); }
        });
        fileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) { alert('Please upload an image (PNG, JPG, GIF, or WebP)'); return; }
            if (file.size > 5242880) { alert('File size must be less than 5MB'); return; }
            var reader = new FileReader();
            reader.onload = function(ev) {
                previewImg.src = ev.target.result;
                preview.style.display = 'flex';
                preview.style.alignItems = 'center';
                uploadDiv.style.display = 'none';
            };
            reader.readAsDataURL(file);
            window.frsLpUploadPhoto(file, function(url) {
                urlInput.value = url;
                previewImg.src = url;
            }, function(msg) {
                alert(msg || 'Upload failed. Please try again.');
                urlInput.value = '';
                fileInput.value = '';
                preview.style.display = 'none';
                uploadDiv.style.display = 'block';
            });
        });
        if (removeBtn) removeBtn.addEventListener('click', function() {
            fileInput.value = '';
            urlInput.value = '';
            preview.style.display = 'none';
            uploadDiv.style.display = 'block';
        });
    }
    setupMcPartnerUpload('photo');
    setupMcPartnerUpload('logo');

    // LO Headshot: use profile photo or upload a new one
    (function setupMcLoHeadshot() {
        var hiddenUrl    = document.getElementById('mc-lo-photo-url');
        var uploadWrap   = document.getElementById('mc-lo-photo-upload-wrap');
        var uploadDiv    = document.getElementById('mc-lo-photo-upload');
        var fileInput    = document.getElementById('mc-lo-photo-file');
        var statusEl     = document.getElementById('mc-lo-photo-status');
        var previewPhoto = document.getElementById('mc-preview-photo');
        if (!hiddenUrl || !fileInput) return;
        var profilePhoto = hiddenUrl.dataset.profilePhoto || (userData.photo || '');
        var radios = wizard.querySelectorAll('input[name="mc-headshot-source"]');
        radios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                if (!radio.checked) return;
                if (radio.value === 'upload') {
                    uploadWrap.style.display = 'block';
                } else {
                    uploadWrap.style.display = 'none';
                    hiddenUrl.value = '';
                    fileInput.value = '';
                    if (statusEl) statusEl.style.display = 'none';
                    if (previewPhoto && profilePhoto) previewPhoto.src = profilePhoto;
                }
            });
        });
        if (uploadDiv) {
            uploadDiv.addEventListener('click', function() { fileInput.click(); });
            uploadDiv.addEventListener('dragover', function(e) { e.preventDefault(); uploadDiv.style.borderColor = '#2563eb'; });
            uploadDiv.addEventListener('dragleave', function() { uploadDiv.style.borderColor = '#cbd5e1'; });
            uploadDiv.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadDiv.style.borderColor = '#cbd5e1';
                if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileInput.dispatchEvent(new Event('change', { bubbles: true })); }
            });
        }
        fileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) { alert('Please upload an image (PNG, JPG, GIF, or WebP)'); return; }
            if (file.size > 5242880) { alert('File size must be less than 5MB'); return; }
            if (statusEl) { statusEl.style.display = 'block'; statusEl.style.color = '#64748b'; statusEl.textContent = 'Uploading…'; }
            var reader = new FileReader();
            reader.onload = function(ev) { if (previewPhoto) previewPhoto.src = ev.target.result; };
            reader.readAsDataURL(file);
            window.frsLpUploadPhoto(file, function(url) {
                hiddenUrl.value = url;
                if (previewPhoto) previewPhoto.src = url;
                if (statusEl) { statusEl.style.color = '#16a34a'; statusEl.textContent = 'New headshot ready.'; }
            }, function(msg) {
                alert(msg || 'Upload failed. Please try again.');
                hiddenUrl.value = '';
                fileInput.value = '';
                if (statusEl) statusEl.style.display = 'none';
                if (previewPhoto && profilePhoto) previewPhoto.src = profilePhoto;
            });
        });
    })();

    // Generate embed code
    function generateEmbedCode() {
        var gradientStart = document.getElementById('mc-color-start').value;
        var gradientEnd = document.getElementById('mc-color-end').value;

        var embedCode;
        if (isLoanOfficer) {
            // LO mode: Get LO data from current user, partner is optional realtor
            var loName = document.getElementById('mc-lo-name')?.value || userData.name;
            var loNmls = document.getElementById('mc-lo-nmls')?.value || userData.nmls || '';
            var loPhone = document.getElementById('mc-lo-phone')?.value || userData.phone;
            var loEmail = document.getElementById('mc-lo-email')?.value || userData.email;

            embedCode = '<!-- Mortgage Calculator Widget - Powered by 21st Century Lending -->\n' +
'<div id="frs-mortgage-calculator"\n' +
'     data-lo-id="' + userData.id + '"\n' +
'     data-lo-name="' + loName + '"\n' +
'     data-lo-nmls="' + loNmls + '"\n' +
'     data-lo-photo="' + (document.getElementById('mc-lo-photo-url')?.value || userData.photo || '') + '"\n' +
'     data-lo-email="' + loEmail + '"\n' +
'     data-lo-phone="' + loPhone + '"\n' +
(selectedPartner ? '     data-realtor-id="' + selectedPartner.id + '"\n' : '') +
(selectedPartner ? '     data-realtor-name="' + selectedPartner.name + '"\n' : '') +
'     data-gradient-start="' + gradientStart + '"\n' +
'     data-gradient-end="' + gradientEnd + '">\n' +
'</div>\n' +
'<script src="' + siteUrl + '/wp-content/plugins/frs-mortgage-calculator/assets/dist/assets/widget.js"><\/script>';
        } else {
            // Realtor mode: Partner is the LO
            if (!selectedPartner) return;

            var realtorName = document.getElementById('mc-realtor-name')?.value || userData.name;

            embedCode = '<!-- Mortgage Calculator Widget - Powered by 21st Century Lending -->\n' +
'<div id="frs-mortgage-calculator"\n' +
'     data-lo-id="' + selectedPartner.id + '"\n' +
'     data-lo-name="' + selectedPartner.name + '"\n' +
'     data-lo-nmls="' + selectedPartner.nmls + '"\n' +
'     data-lo-photo="' + selectedPartner.photo + '"\n' +
'     data-lo-email="' + selectedPartner.email + '"\n' +
'     data-lo-phone="' + selectedPartner.phone + '"\n' +
'     data-realtor-id="' + userData.id + '"\n' +
'     data-realtor-name="' + realtorName + '"\n' +
'     data-gradient-start="' + gradientStart + '"\n' +
'     data-gradient-end="' + gradientEnd + '">\n' +
'</div>\n' +
'<script src="' + siteUrl + '/wp-content/plugins/frs-mortgage-calculator/assets/dist/assets/widget.js"><\/script>';
        }

        var codeEl = document.getElementById('mc-embed-code');
        if (codeEl) {
            codeEl.textContent = embedCode;
        }
    }

    // Copy embed code button
    var copyEmbedBtn = document.getElementById('mc-copy-embed');
    if (copyEmbedBtn) {
        copyEmbedBtn.addEventListener('click', function() {
            var codeEl = document.getElementById('mc-embed-code');
            if (codeEl) {
                navigator.clipboard.writeText(codeEl.textContent).then(function() {
                    var originalText = copyEmbedBtn.innerHTML;
                    copyEmbedBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Copied!';
                    setTimeout(function() {
                        copyEmbedBtn.innerHTML = originalText;
                    }, 2000);
                });
            }
        });
    }

    // Copy URL button (success state)
    var copyUrlBtn = document.getElementById('mc-copy-url');
    if (copyUrlBtn) {
        copyUrlBtn.addEventListener('click', function() {
            var urlInput = document.getElementById('mc-success-url');
            if (urlInput) {
                navigator.clipboard.writeText(urlInput.value).then(function() {
                    var originalText = copyUrlBtn.textContent;
                    copyUrlBtn.textContent = 'Copied!';
                    setTimeout(function() {
                        copyUrlBtn.textContent = originalText;
                    }, 2000);
                });
            }
        });
    }

    // Create another button
    var createAnotherBtn = document.getElementById('mc-create-another');
    if (createAnotherBtn) {
        createAnotherBtn.addEventListener('click', function() {
            // Reset wizard state
            currentStep = 0;
            selectedPartner = null;
            selectedOutputType = 'landing_page';

            // Reset form
            var partnerInput = document.getElementById('mc-partner');
            if (partnerInput) partnerInput.value = '';
            var dropdownValue = document.querySelector('#mc-partner-dropdown .mc-dropdown__value');
            if (dropdownValue) dropdownValue.textContent = isLoanOfficer ? 'Select a realtor partner...' : 'Select a loan officer...';
            document.querySelectorAll('.mc-dropdown__item').forEach(function(i) { i.classList.remove('is-selected'); });
            document.querySelectorAll('input[name="mc-output-type"]').forEach(function(i) {
                i.checked = i.value === 'landing_page';
            });
            document.getElementById('mc-embed-output').style.display = 'none';

            // Go back to step 0
            goToStep(0);

            // Show footer again
            document.querySelector('.mc-wizard__footer').style.display = 'flex';
        });
    }

    // Navigation
    var prevBtn = document.getElementById('mc-prev-btn');
    var nextBtn = document.getElementById('mc-next-btn');
    var backBtnTop = document.getElementById('mc-back-top');
    var nextBtnTop = document.getElementById('mc-next-top');

    prevBtn.addEventListener('click', function() {
        if (currentStep > 0) {
            goToStep(currentStep - 1);
        }
    });

    nextBtn.addEventListener('click', function() {
        if (validateStep()) {
            if (currentStep < totalSteps - 1) {
                goToStep(currentStep + 1);
            } else {
                submitWizard();
            }
        }
    });

    // Top button listeners
    if (nextBtnTop) {
        nextBtnTop.addEventListener('click', function() {
            if (validateStep()) {
                if (currentStep < totalSteps - 1) {
                    goToStep(currentStep + 1);
                } else {
                    submitWizard();
                }
            }
        });
    }
    if (backBtnTop) {
        backBtnTop.addEventListener('click', function() {
            if (currentStep > 0) {
                goToStep(currentStep - 1);
            }
        });
    }

    function goToStep(step) {
        document.querySelectorAll('.mc-step').forEach(function(el) { el.style.display = 'none'; });
        var stepEl = document.querySelector('.mc-step[data-step="' + step + '"]');
        if (stepEl) stepEl.style.display = 'block';
        currentStep = step;

        // Update progress
        var progress = ((step + 1) / totalSteps) * 100;
        document.querySelector('.mc-wizard__progress-bar').style.width = progress + '%';
        document.getElementById('mc-step-num').textContent = step + 1;

        // Update buttons based on step and output type
        prevBtn.style.display = step === 0 ? 'none' : 'flex';
        if (backBtnTop) backBtnTop.style.display = step === 0 ? 'none' : 'inline-flex';
        if (nextBtnTop) nextBtnTop.style.display = step < totalSteps - 1 ? 'inline-flex' : 'none';

        if (step === totalSteps - 1) {
            // On last step, button text depends on output type
            updateNextButtonText();
        } else {
            nextBtn.innerHTML = 'Continue <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
        }
    }

    function updateNextButtonText() {
        if (selectedOutputType === 'embed_code') {
            nextBtn.innerHTML = 'Done <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
        } else {
            nextBtn.innerHTML = 'Create Page <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
        }
    }

    function validateStep() {
        if (currentStep === 0) {
            if (isLoanOfficer) {
                // LO Mode: Check page type is selected
                var pageType = document.getElementById('mc-page-type')?.value;
                if (!pageType) {
                    alert('Please select Solo Page or Co-branded');
                    return false;
                }

                // If co-branded, read the manually-entered partner details
                if (pageType === 'cobranded') {
                    var partnerName  = (document.getElementById('mc-partner-name-input')  || {}).value || '';
                    var partnerEmail = (document.getElementById('mc-partner-email-input') || {}).value || '';
                    var partnerPhone = (document.getElementById('mc-partner-phone-input') || {}).value || '';
                    var partnerPhoto = (document.getElementById('mc-partner-photo-url')   || {}).value || '';
                    var partnerLogo  = (document.getElementById('mc-partner-logo-url')    || {}).value || '';
                    partnerName  = partnerName.trim();
                    partnerEmail = partnerEmail.trim();
                    partnerPhone = partnerPhone.trim();
                    if (!partnerName)  { alert("Please enter the partner's name");         return false; }
                    if (!partnerEmail) { alert("Please enter the partner's email");        return false; }
                    if (!partnerPhone) { alert("Please enter the partner's phone number"); return false; }
                    selectedPartner = { id: 0, name: partnerName, email: partnerEmail, phone: partnerPhone, photo: partnerPhoto, logo: partnerLogo, company: '', license: '', nmls: '' };
                }
            } else {
                // Partner Mode: Require LO selection
                var partnerDropdown = document.getElementById('mc-partner-dropdown');
                var isRequired = partnerDropdown?.dataset.required === 'true';

                if (isRequired && !selectedPartner) {
                    alert('Please select a loan officer');
                    return false;
                }

                // Save preference if "Remember my choice" is checked
                if (selectedPartner) {
                    var rememberCheckbox = document.getElementById('mc-remember-partner');
                    if (rememberCheckbox && rememberCheckbox.checked) {
                        fetch(ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'frs_set_preferred_lo',
                                nonce: nonce,
                                lo_id: selectedPartner.id,
                                remember: 'true'
                            })
                        }).then(function(r) { return r.json(); }).then(function(res) {
                            console.log('MC Wizard: Saved preferred LO:', res);
                        }).catch(function(err) {
                            console.error('MC Wizard: Failed to save preference:', err);
                        });
                    }
                }
            }
        }
        return true;
    }

    function submitWizard() {
        // If embed code is selected, just close the modal (they already have the code)
        if (selectedOutputType === 'embed_code') {
            closeModal();
            return;
        }

        // Otherwise, create landing page
        nextBtn.classList.add('is-loading');
        nextBtn.disabled = true;

        var data = {
            action: 'frs_create_calculator',
            nonce: nonce,
            user_mode: userMode,
            gradient_start: document.getElementById('mc-color-start').value,
            gradient_end: document.getElementById('mc-color-end').value
        };

        // Add data based on user mode
        if (isLoanOfficer) {
            // LO mode: Current user is LO, optional realtor partner
            data.lo_name = document.getElementById('mc-lo-name')?.value || userData.name;
            data.lo_nmls = document.getElementById('mc-lo-nmls')?.value || '';
            data.lo_phone = document.getElementById('mc-lo-phone')?.value || '';
            data.lo_email = document.getElementById('mc-lo-email')?.value || '';

            if (selectedPartner) {
                data.partner_id = selectedPartner.id;
                data.partner_name = selectedPartner.name;
                data.partner_license = selectedPartner.license;
                data.partner_company = selectedPartner.company;
                data.partner_phone = selectedPartner.phone;
                data.partner_email = selectedPartner.email;
                data.partner_photo = selectedPartner.photo || '';
                data.partner_logo = selectedPartner.logo || '';
            }
        } else {
            // Realtor mode: Partner is LO
            data.realtor_name = document.getElementById('mc-realtor-name')?.value || userData.name;
            data.realtor_license = document.getElementById('mc-realtor-license')?.value || '';
            data.realtor_phone = document.getElementById('mc-realtor-phone')?.value || '';
            data.realtor_email = document.getElementById('mc-realtor-email')?.value || '';
            data.loan_officer_id = selectedPartner?.id || '';
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then(function(res) { return res.json(); })
        .then(function(response) {
            if (response.success) {
                // Show success state instead of redirecting
                showSuccessState(response.data.url);
            } else {
                alert(response.data.message || 'Error creating page');
                nextBtn.classList.remove('is-loading');
                nextBtn.disabled = false;
            }
        })
        .catch(function(err) {
            console.error(err);
            alert('Error creating page');
            nextBtn.classList.remove('is-loading');
            nextBtn.disabled = false;
        });
    }

    function showSuccessState(pageUrl) {
        // Hide all steps
        document.querySelectorAll('.mc-step').forEach(function(el) { el.style.display = 'none'; });

        // Show success state
        var successStep = document.querySelector('.mc-step[data-step="success"]');
        if (successStep) {
            successStep.style.display = 'block';
        }

        // Set the URL
        document.getElementById('mc-success-url').value = pageUrl;
        document.getElementById('mc-view-page').href = pageUrl;

        // Hide footer buttons
        document.querySelector('.mc-wizard__footer').style.display = 'none';

        // Reset next button state
        nextBtn.classList.remove('is-loading');
        nextBtn.disabled = false;
    }

    // Image upload handling
    var imageUpload = document.getElementById('mc-image-upload');
    if (imageUpload) {
        imageUpload.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('mc-hero-image').value = e.target.result;
                // Deselect stock images
                document.querySelectorAll('#mc-images-grid input').forEach(function(i) { i.checked = false; });
            };
            reader.readAsDataURL(file);
        });
    }

    // Update branding preview in real-time
    ['mc-realtor-name', 'mc-realtor-license'].forEach(function(id) {
        var input = document.getElementById(id);
        if (input) {
            input.addEventListener('input', function() {
                if (id === 'mc-realtor-name') {
                    document.getElementById('mc-preview-name').textContent = input.value;
                } else {
                    document.getElementById('mc-preview-license').textContent = input.value;
                }
            });
        }
    });
})();
