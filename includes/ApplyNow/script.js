/**
 * Apply Now Wizard Script
 *
 * Multi-step wizard for creating Apply Now landing pages.
 * Requires frsApplyNowWizard object from wp_localize_script().
 *
 * @package FRSLeadPages
 */

(function() {
    'use strict';

    // Get config from localized script
    var config = window.frsApplyNowWizard || {};

    var modal = document.getElementById('an-wizard-modal');
    var wizard = document.getElementById('an-wizard');
    if (!modal || !wizard) return;

    var currentStep = 0;
    var totalSteps = 5;
    var selectedPartner = null;
    var selectedScheduleType = 'form';

    // Get user data and mode
    var userData = JSON.parse(wizard.dataset.user || "{}");
    var userMode = userData.mode || "realtor";
    var isLoanOfficer = userMode === "loan_officer";

    // ===== Page Type Card Selection (LO mode) =====
    var pageTypeCards = wizard.querySelectorAll('.an-page-type-card');
    var pageTypeInput = document.getElementById('an-page-type');
    var partnerSelectionDiv = document.getElementById('an-partner-selection');
    var partnerInput = document.getElementById('an-partner');

    if (pageTypeCards.length > 0 && isLoanOfficer) {
        pageTypeCards.forEach(function(card) {
            card.addEventListener('click', function() {
                pageTypeCards.forEach(function(c) {
                    c.classList.remove('selected');
                });
                card.classList.add('selected');
                var pageType = card.dataset.type;
                if (pageTypeInput) pageTypeInput.value = pageType;

                if (partnerSelectionDiv) {
                    if (pageType === 'cobranded') {
                        partnerSelectionDiv.style.display = 'block';
                    } else {
                        partnerSelectionDiv.style.display = 'none';
                        if (partnerInput) partnerInput.value = '';
                        selectedPartner = null;
                        var dropdownValue = wizard.querySelector('#an-partner-dropdown .an-dropdown__value');
                        if (dropdownValue) dropdownValue.textContent = 'Choose a partner...';
                        wizard.querySelectorAll('#an-partner-dropdown .an-dropdown__item').forEach(function(i) {
                            i.classList.remove('is-selected');
                        });
                    }
                }
            });
        });
    }

    // ===== Stock Copy Selection =====
    var headlineRadios = wizard.querySelectorAll('input[name="an-headline-choice"]');
    var headlineCustomInput = document.getElementById('an-headline-custom');
    var headlineHiddenInput = document.getElementById('an-headline');

    headlineRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (radio.value === 'custom') {
                headlineCustomInput.style.display = 'block';
                headlineCustomInput.focus();
            } else {
                headlineCustomInput.style.display = 'none';
                headlineHiddenInput.value = radio.value;
            }
        });
    });

    if (headlineCustomInput) {
        headlineCustomInput.addEventListener('input', function() {
            headlineHiddenInput.value = headlineCustomInput.value;
        });
    }

    var descRadios = wizard.querySelectorAll('input[name="an-description-choice"]');
    var descCustomInput = document.getElementById('an-description-custom');
    var subheadlineInput = document.getElementById('an-subheadline');

    descRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (radio.value === 'custom') {
                descCustomInput.style.display = 'block';
                descCustomInput.focus();
            } else {
                descCustomInput.style.display = 'none';
                subheadlineInput.value = radio.value;
            }
        });
    });

    if (descCustomInput) {
        descCustomInput.addEventListener('input', function() {
            subheadlineInput.value = descCustomInput.value;
        });
    }

    // ===== Hero Image Selection =====
    var imagesGrid = document.getElementById('an-images-grid');
    var heroImageInput = document.getElementById('an-hero-image');

    if (imagesGrid) {
        imagesGrid.addEventListener('click', function(e) {
            var option = e.target.closest('.an-image-option');
            if (option) {
                imagesGrid.querySelectorAll('.an-image-option').forEach(function(o) {
                    o.classList.remove('an-image-option--selected');
                });
                option.classList.add('an-image-option--selected');
                heroImageInput.value = option.dataset.url;
            }
        });
    }

    // Image upload
    var imageUpload = document.getElementById('an-image-upload');
    if (imageUpload) {
        imageUpload.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (file) {
                var reader = new FileReader();
                reader.onload = function(ev) {
                    // Create new image option
                    var newOption = document.createElement('div');
                    newOption.className = 'an-image-option an-image-option--selected';
                    newOption.dataset.url = ev.target.result;
                    newOption.innerHTML = '<img src="' + ev.target.result + '" alt="Uploaded image">';

                    // Deselect others
                    imagesGrid.querySelectorAll('.an-image-option').forEach(function(o) {
                        o.classList.remove('an-image-option--selected');
                    });

                    // Add to grid
                    imagesGrid.insertBefore(newOption, imagesGrid.firstChild);
                    heroImageInput.value = ev.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Upload an image file to the WordPress media library; returns a real URL.
    function uploadPhotoToMedia(file, onSuccess, onError) {
        var fd = new FormData();
        fd.append('action', 'frs_lp_upload_photo');
        fd.append('nonce', config.nonce);
        fd.append('file', file);
        fetch(config.ajaxUrl, { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(response) {
                if (response && response.success && response.data && response.data.url) {
                    onSuccess(response.data.url);
                } else {
                    onError(response && response.data && response.data.message);
                }
            })
            .catch(function() { onError(); });
    }

    // Partner headshot + company logo uploads (separate fields)
    function setupAnPartnerUpload(suffix) {
        var uploadDiv  = document.getElementById('an-partner-' + suffix + '-upload');
        var fileInput  = document.getElementById('an-partner-' + suffix + '-file');
        var preview    = document.getElementById('an-partner-' + suffix + '-preview');
        var previewImg = document.getElementById('an-partner-' + suffix + '-preview-img');
        var removeBtn  = document.getElementById('an-partner-' + suffix + '-remove');
        var urlInput   = document.getElementById('an-partner-' + suffix + '-url');
        if (!uploadDiv || !fileInput) return;

        uploadDiv.addEventListener('click', function() { fileInput.click(); });
        uploadDiv.addEventListener('dragover', function(e) { e.preventDefault(); uploadDiv.style.borderColor = '#6366f1'; });
        uploadDiv.addEventListener('dragleave', function() { uploadDiv.style.borderColor = '#cbd5e1'; });
        uploadDiv.addEventListener('drop', function(e) {
            e.preventDefault();
            uploadDiv.style.borderColor = '#cbd5e1';
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        fileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) { alert('Please upload an image (PNG, JPG, GIF, or WebP)'); return; }
            if (file.size > 5242880) { alert('File size must be less than 5MB'); return; }
            // Show an instant local preview, then upload to the media library.
            var reader = new FileReader();
            reader.onload = function(ev) {
                previewImg.src = ev.target.result;
                preview.style.display = 'flex';
                preview.style.alignItems = 'center';
                uploadDiv.style.display = 'none';
            };
            reader.readAsDataURL(file);
            uploadPhotoToMedia(file, function(url) {
                urlInput.value = url;
                previewImg.src = url;
            }, function(msg) {
                alert(msg || 'Upload failed. Please try again.');
                urlInput.value = '';
                preview.style.display = 'none';
                uploadDiv.style.display = 'block';
                fileInput.value = '';
            });
        });
        if (removeBtn) removeBtn.addEventListener('click', function() {
            fileInput.value = '';
            urlInput.value = '';
            preview.style.display = 'none';
            uploadDiv.style.display = 'block';
        });
    }
    setupAnPartnerUpload('photo');
    setupAnPartnerUpload('logo');

    // ===== LO Headshot: use profile photo or upload a new one =====
    (function setupLoHeadshot() {
        var hiddenUrl    = document.getElementById('an-lo-photo-url');
        var uploadWrap   = document.getElementById('an-lo-photo-upload-wrap');
        var uploadDiv    = document.getElementById('an-lo-photo-upload');
        var fileInput    = document.getElementById('an-lo-photo-file');
        var statusEl     = document.getElementById('an-lo-photo-status');
        var previewPhoto = document.getElementById('an-preview-photo');
        if (!hiddenUrl || !fileInput) return;

        var profilePhoto = hiddenUrl.dataset.profilePhoto || (userData.photo || '');
        var radios = wizard.querySelectorAll('input[name="an-headshot-source"]');

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
            uploadDiv.addEventListener('dragover', function(e) { e.preventDefault(); uploadDiv.style.borderColor = '#6366f1'; });
            uploadDiv.addEventListener('dragleave', function() { uploadDiv.style.borderColor = '#cbd5e1'; });
            uploadDiv.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadDiv.style.borderColor = '#cbd5e1';
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }

        fileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) { alert('Please upload an image (PNG, JPG, GIF, or WebP)'); return; }
            if (file.size > 5242880) { alert('File size must be less than 5MB'); return; }
            if (statusEl) { statusEl.style.display = 'block'; statusEl.style.color = '#64748b'; statusEl.textContent = 'Uploading…'; }
            // Instant local preview while the upload runs.
            var reader = new FileReader();
            reader.onload = function(ev) { if (previewPhoto) previewPhoto.src = ev.target.result; };
            reader.readAsDataURL(file);
            uploadPhotoToMedia(file, function(url) {
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

    // ===== Schedule Type Cards =====
    var scheduleCards = wizard.querySelectorAll('.an-schedule-card');
    var scheduleTypeInput = document.getElementById('an-schedule-type');
    var formSelection = document.getElementById('an-form-selection');
    var calendarSelection = document.getElementById('an-calendar-selection');

    if (scheduleCards.length > 0) {
        scheduleCards.forEach(function(card) {
            card.addEventListener('click', function() {
                scheduleCards.forEach(function(c) {
                    c.classList.remove('selected');
                });
                card.classList.add('selected');
                selectedScheduleType = card.dataset.type;
                if (scheduleTypeInput) scheduleTypeInput.value = selectedScheduleType;

                // Toggle form/calendar selection
                if (selectedScheduleType === 'form') {
                    if (formSelection) formSelection.style.display = 'block';
                    if (calendarSelection) calendarSelection.style.display = 'none';
                } else {
                    if (formSelection) formSelection.style.display = 'none';
                    if (calendarSelection) calendarSelection.style.display = 'block';
                }
            });
        });
    }

    // ===== Custom Dropdown Functionality =====
    function initDropdown(dropdownId, inputId, placeholder) {
        var dropdown = document.getElementById(dropdownId);
        if (!dropdown) return;

        var trigger = dropdown.querySelector('.an-dropdown__trigger');
        var items = dropdown.querySelectorAll('.an-dropdown__item');
        var input = document.getElementById(inputId);
        var valueDisplay = dropdown.querySelector('.an-dropdown__value');

        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            // Close other dropdowns
            document.querySelectorAll('.an-dropdown.is-open').forEach(function(d) {
                if (d !== dropdown) d.classList.remove('is-open');
            });

            dropdown.classList.toggle('is-open');

            // Position the menu
            var menu = dropdown.querySelector('.an-dropdown__menu');
            var rect = trigger.getBoundingClientRect();
            menu.style.top = (rect.bottom + window.scrollY) + 'px';
            menu.style.left = rect.left + 'px';
            menu.style.width = rect.width + 'px';
        });

        items.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                items.forEach(function(i) {
                    i.classList.remove('is-selected');
                });
                item.classList.add('is-selected');

                if (input) input.value = item.dataset.value;

                // Update display
                var displayName = item.dataset.name || item.querySelector('.an-dropdown__name').textContent;
                var photo = item.dataset.photo;

                if (photo) {
                    valueDisplay.innerHTML = '<img src="' + photo + '" style="width:24px;height:24px;border-radius:50%;margin-right:8px;">' + displayName;
                } else {
                    valueDisplay.textContent = displayName;
                }

                // Store partner data if applicable
                if (item.dataset.nmls !== undefined || item.dataset.arrive !== undefined) {
                    selectedPartner = {
                        id: item.dataset.value,
                        name: item.dataset.name || displayName,
                        nmls: item.dataset.nmls || '',
                        arrive: item.dataset.arrive || '',
                        license: item.dataset.license || '',
                        company: item.dataset.company || '',
                        photo: item.dataset.photo || '',
                        email: item.dataset.email || '',
                        phone: item.dataset.phone || ''
                    };

                    // Update partner preview if exists
                    updatePartnerPreview();
                }

                dropdown.classList.remove('is-open');
            });
        });

        // Auto-select preferred partner if set
        var preferredId = dropdown.dataset.preferred;
        if (preferredId && preferredId !== '0') {
            var preferredItem = dropdown.querySelector('.an-dropdown__item[data-value="' + preferredId + '"]');
            if (preferredItem) {
                preferredItem.click();
            }
        }
    }

    // Initialize all dropdowns
    initDropdown('an-partner-dropdown', 'an-partner', 'Choose a partner...');
    initDropdown('an-form-dropdown', 'an-form-id', 'Choose a form...');
    initDropdown('an-calendar-dropdown', 'an-calendar-id', 'Choose a calendar...');

    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.an-dropdown')) {
            document.querySelectorAll('.an-dropdown.is-open').forEach(function(d) {
                d.classList.remove('is-open');
            });
        }
    });

    // ===== Partner Preview Update =====
    function updatePartnerPreview() {
        var preview = document.getElementById('an-partner-preview');
        if (!preview || !selectedPartner) return;

        var nmlsText = selectedPartner.nmls ? 'NMLS# ' + selectedPartner.nmls : '';
        var arriveText = selectedPartner.arrive ? '<a href="' + selectedPartner.arrive + '" target="_blank" class="an-arrive-link-small">Apply Now Link</a>' : '';

        preview.innerHTML =
            '<img src="' + (selectedPartner.photo || '') + '" alt="">' +
            '<div class="an-lo-preview__info">' +
                '<h4>' + selectedPartner.name + '</h4>' +
                '<p>' + nmlsText + '</p>' +
                (arriveText ? '<p>' + arriveText + '</p>' : '') +
            '</div>';
    }

    // ===== Modal Open/Close =====
    document.querySelectorAll('.' + (config.triggerClass || 'an-wizard-trigger')).forEach(function(btn) {
        btn.addEventListener('click', function() {
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        });
    });

    // Check URL hash
    if (window.location.hash === '#' + (config.triggerHash || 'apply-now-wizard')) {
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    // Close modal
    modal.querySelector('.an-modal__backdrop').addEventListener('click', closeModal);
    modal.querySelector('.an-modal__close').addEventListener('click', closeModal);

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    // ===== Copy URL Button =====
    var copyUrlBtn = document.getElementById('an-copy-url');
    if (copyUrlBtn) {
        copyUrlBtn.addEventListener('click', function() {
            var urlInput = document.getElementById('an-success-url');
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

    // ===== Create Another Button =====
    var createAnotherBtn = document.getElementById('an-create-another');
    if (createAnotherBtn) {
        createAnotherBtn.addEventListener('click', function() {
            currentStep = 0;
            selectedPartner = null;
            selectedScheduleType = 'form';
            goToStep(0);
            document.querySelector('.an-wizard__footer').style.display = 'flex';
        });
    }

    // ===== Navigation =====
    var prevBtn = document.getElementById('an-prev-btn');
    var nextBtn = document.getElementById('an-next-btn');
    var backBtnTop = document.getElementById('an-back-top');
    var nextBtnTop = document.getElementById('an-next-top');

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
        document.querySelectorAll('.an-step').forEach(function(el) {
            el.style.display = 'none';
        });
        var stepEl = document.querySelector('.an-step[data-step="' + step + '"]');
        if (stepEl) stepEl.style.display = 'block';
        currentStep = step;

        // Update progress
        var progress = ((step + 1) / totalSteps) * 100;
        document.querySelector('.an-wizard__progress-bar').style.width = progress + '%';
        document.getElementById('an-step-num').textContent = step + 1;

        // Update buttons
        prevBtn.style.display = step === 0 ? 'none' : 'flex';
        if (backBtnTop) backBtnTop.style.display = step === 0 ? 'none' : 'inline-flex';
        if (nextBtnTop) nextBtnTop.style.display = step < totalSteps - 1 ? 'inline-flex' : 'none';

        if (step === totalSteps - 1) {
            nextBtn.innerHTML = 'Create Page <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
        } else {
            nextBtn.innerHTML = 'Continue <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
        }
    }

    function validateStep() {
        if (currentStep === 0) {
            if (isLoanOfficer) {
                var pageType = document.getElementById('an-page-type');
                if (pageType && !pageType.value) {
                    alert('Please select Solo Page or Co-branded');
                    return false;
                }
                if (pageType && pageType.value === 'cobranded') {
                    var partnerName     = (document.getElementById('an-partner-name-input')  || {}).value || '';
                    var partnerEmail    = (document.getElementById('an-partner-email-input') || {}).value || '';
                    var partnerPhone    = (document.getElementById('an-partner-phone-input') || {}).value || '';
                    var partnerHeadshot = (document.getElementById('an-partner-photo-url')   || {}).value || '';
                    var partnerLogo     = (document.getElementById('an-partner-logo-url')    || {}).value || '';
                    partnerName  = partnerName.trim();
                    partnerEmail = partnerEmail.trim();
                    partnerPhone = partnerPhone.trim();

                    if (!partnerName)  { alert("Please enter the partner's name");         return false; }
                    if (!partnerEmail) { alert("Please enter the partner's email");        return false; }
                    if (!partnerPhone) { alert("Please enter the partner's phone number"); return false; }

                    selectedPartner = {
                        id: 0,
                        name: partnerName,
                        email: partnerEmail,
                        phone: partnerPhone,
                        photo: partnerHeadshot,
                        logo: partnerLogo,
                        company: '',
                        license: '',
                        nmls: '',
                        arrive: ''
                    };
                }
            } else {
                var partnerDropdown = document.getElementById('an-partner-dropdown');
                var isRequired = partnerDropdown && partnerDropdown.dataset.required === 'true';
                if (isRequired && !selectedPartner) {
                    alert('Please select a loan officer');
                    return false;
                }

                // Save preference if checked
                if (selectedPartner) {
                    var rememberCheckbox = document.getElementById('an-remember-partner');
                    if (rememberCheckbox && rememberCheckbox.checked) {
                        fetch(config.ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'frs_set_preferred_lo',
                                nonce: config.nonce,
                                lo_id: selectedPartner.id,
                                remember: 'true'
                            })
                        });
                    }
                }
            }
        } else if (currentStep === 1) {
            // Validate headline
            var headlineVal = document.getElementById('an-headline').value;
            if (!headlineVal) {
                alert('Please select or enter a headline');
                return false;
            }
        } else if (currentStep === 2) {
            // Hero image - optional, default is already selected
        } else if (currentStep === 3) {
            if (selectedScheduleType === 'form') {
                var formId = document.getElementById('an-form-id');
                if (formId && !formId.value) {
                    alert('Please select a form');
                    return false;
                }
            } else if (selectedScheduleType === 'booking') {
                var calendarId = document.getElementById('an-calendar-id');
                if (calendarId && !calendarId.value) {
                    alert('Please select a calendar');
                    return false;
                }
            }
        }
        return true;
    }

    function submitWizard() {
        nextBtn.classList.add('is-loading');
        nextBtn.disabled = true;

        var data = {
            action: 'frs_create_apply_now',
            nonce: config.nonce,
            user_mode: userMode,
            headline: (document.getElementById('an-headline') || {}).value || '',
            subheadline: (document.getElementById('an-subheadline') || {}).value || '',
            hero_image: (document.getElementById('an-hero-image') || {}).value || '',
            schedule_type: selectedScheduleType,
            calendar_id: (document.getElementById('an-calendar-id') || {}).value || '',
            arrive_link: (document.getElementById('an-arrive-link') || {}).value || ''
        };

        if (isLoanOfficer) {
            data.lo_name = (document.getElementById('an-lo-name') || {}).value || userData.name;
            data.lo_nmls = (document.getElementById('an-lo-nmls') || {}).value || '';
            data.lo_phone = (document.getElementById('an-lo-phone') || {}).value || '';
            data.lo_email = (document.getElementById('an-lo-email') || {}).value || '';
            data.lo_photo = (document.getElementById('an-lo-photo-url') || {}).value || '';
            data.arrive_link = userData.arrive || '';

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
            data.realtor_name = (document.getElementById('an-realtor-name') || {}).value || userData.name;
            data.realtor_license = (document.getElementById('an-realtor-license') || {}).value || '';
            data.realtor_phone = (document.getElementById('an-realtor-phone') || {}).value || '';
            data.realtor_email = (document.getElementById('an-realtor-email') || {}).value || '';
            data.loan_officer_id = selectedPartner ? selectedPartner.id : '';

            // Use the LO's arrive link for Apply Now pages
            if (selectedPartner && selectedPartner.arrive) {
                data.arrive_link = selectedPartner.arrive;
            }
        }

        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(response) {
            if (response.success) {
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
        document.querySelectorAll('.an-step').forEach(function(el) {
            el.style.display = 'none';
        });
        var successStep = document.querySelector('.an-step[data-step="success"]');
        if (successStep) successStep.style.display = 'block';

        document.getElementById('an-success-url').value = pageUrl;
        document.getElementById('an-view-page').href = pageUrl;
        document.querySelector('.an-wizard__footer').style.display = 'none';

        nextBtn.classList.remove('is-loading');
        nextBtn.disabled = false;
    }
})();
