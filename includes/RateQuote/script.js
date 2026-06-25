/**
 * Rate Quote Wizard Modal Script
 *
 * Requires frsRateQuoteWizard object from wp_localize_script().
 *
 * @package FRSLeadPages
 */

(function() {
    'use strict';

    var config = window.frsRateQuoteWizard || {};
    var triggerClass = config.triggerClass || 'rq-wizard-trigger';
    var triggerHash = config.triggerHash || 'rate-quote-wizard';
    var ajaxUrl = config.ajaxUrl || '';
    var nonce = config.nonce || '';

    var modal = document.getElementById('rq-wizard-modal');
    var wizard = document.getElementById('rq-wizard');
    if (!modal || !wizard) return;

    var currentStep = 0;
    var totalSteps = 4;
    var selectedPartner = null;
    var selectedScheduleType = 'form';

    // Get user data and mode
    var userData = JSON.parse(wizard.dataset.user || "{}");
    var userMode = userData.mode || "realtor";
    var isLoanOfficer = userMode === "loan_officer";

    // Page type card selection (LO mode)
    var pageTypeCards = wizard.querySelectorAll('.rq-page-type-card');
    var pageTypeInput = document.getElementById('rq-page-type');
    var partnerSelectionDiv = document.getElementById('rq-partner-selection');
    var partnerInput = document.getElementById('rq-partner');

    if (pageTypeCards.length > 0 && isLoanOfficer) {
        pageTypeCards.forEach(function(card) {
            card.addEventListener('click', function() {
                pageTypeCards.forEach(function(c) { c.classList.remove('selected'); });
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
                        var dropdownValue = wizard.querySelector('#rq-partner-dropdown .rq-dropdown__value');
                        if (dropdownValue) dropdownValue.textContent = 'Choose a partner...';
                        wizard.querySelectorAll('#rq-partner-dropdown .rq-dropdown__item').forEach(function(i) { i.classList.remove('is-selected'); });
                    }
                }
            });
        });
    }

    // Schedule type card selection
    var scheduleCards = wizard.querySelectorAll('.rq-schedule-card');
    var scheduleTypeInput = document.getElementById('rq-schedule-type');
    var formSelection = document.getElementById('rq-form-selection');
    var calendarSelection = document.getElementById('rq-calendar-selection');

    if (scheduleCards.length > 0) {
        // Default select first card
        scheduleCards[0].classList.add('selected');

        scheduleCards.forEach(function(card) {
            card.addEventListener('click', function() {
                scheduleCards.forEach(function(c) { c.classList.remove('selected'); });
                card.classList.add('selected');
                selectedScheduleType = card.dataset.type;
                if (scheduleTypeInput) scheduleTypeInput.value = selectedScheduleType;

                // Toggle form/calendar selection
                if (selectedScheduleType === 'form') {
                    formSelection.style.display = 'block';
                    calendarSelection.style.display = 'none';
                } else {
                    formSelection.style.display = 'none';
                    calendarSelection.style.display = 'block';
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
    modal.querySelector('.rq-modal__backdrop').addEventListener('click', closeModal);
    modal.querySelector('.rq-modal__close').addEventListener('click', closeModal);

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    // Dropdown functionality
    var dropdown = document.getElementById('rq-partner-dropdown');
    if (dropdown) {
        var trigger = dropdown.querySelector('.rq-dropdown__trigger');
        var items = dropdown.querySelectorAll('.rq-dropdown__item');
        var input = document.getElementById('rq-partner');
        var valueDisplay = dropdown.querySelector('.rq-dropdown__value');

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
            var preferredItem = dropdown.querySelector('.rq-dropdown__item[data-value="' + preferredId + '"]');
            if (preferredItem) {
                preferredItem.click();
            }
        }
    }

    // Copy URL button
    var copyUrlBtn = document.getElementById('rq-copy-url');
    if (copyUrlBtn) {
        copyUrlBtn.addEventListener('click', function() {
            var urlInput = document.getElementById('rq-success-url');
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
    var createAnotherBtn = document.getElementById('rq-create-another');
    if (createAnotherBtn) {
        createAnotherBtn.addEventListener('click', function() {
            currentStep = 0;
            selectedPartner = null;
            selectedScheduleType = 'form';
            goToStep(0);
            document.querySelector('.rq-wizard__footer').style.display = 'flex';
        });
    }

    // Navigation
    var prevBtn = document.getElementById('rq-prev-btn');
    var nextBtn = document.getElementById('rq-next-btn');
    var backBtnTop = document.getElementById('rq-back-top');
    var nextBtnTop = document.getElementById('rq-next-top');

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
        document.querySelectorAll('.rq-step').forEach(function(el) { el.style.display = 'none'; });
        var stepEl = document.querySelector('.rq-step[data-step="' + step + '"]');
        if (stepEl) stepEl.style.display = 'block';
        currentStep = step;

        // Update progress
        var progress = ((step + 1) / totalSteps) * 100;
        document.querySelector('.rq-wizard__progress-bar').style.width = progress + '%';
        document.getElementById('rq-step-num').textContent = step + 1;

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
                var pageType = document.getElementById('rq-page-type') ? document.getElementById('rq-page-type').value : null;
                if (!pageType) {
                    alert('Please select Solo Page or Co-branded');
                    return false;
                }
                if (pageType === 'cobranded' && !selectedPartner) {
                    alert('Please select a partner for co-branding');
                    return false;
                }
            } else {
                var partnerDropdown = document.getElementById('rq-partner-dropdown');
                var isRequired = partnerDropdown && partnerDropdown.dataset.required === 'true';
                if (isRequired && !selectedPartner) {
                    alert('Please select a loan officer');
                    return false;
                }

                // Save preference if checked
                if (selectedPartner) {
                    var rememberCheckbox = document.getElementById('rq-remember-partner');
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
                        });
                    }
                }
            }
        } else if (currentStep === 1) {
            var headline = document.getElementById('rq-headline');
            var headlineValue = headline ? headline.value.trim() : '';
            if (!headlineValue) {
                alert('Please enter a headline');
                return false;
            }
        } else if (currentStep === 2) {
            if (selectedScheduleType === 'form') {
                var formId = document.getElementById('rq-form-id') ? document.getElementById('rq-form-id').value : '';
                if (!formId) {
                    alert('Please select a form');
                    return false;
                }
            } else if (selectedScheduleType === 'booking') {
                var calendarId = document.getElementById('rq-calendar-id') ? document.getElementById('rq-calendar-id').value : '';
                if (!calendarId) {
                    alert('Please select a calendar');
                    return false;
                }
            }
        }
        return true;
    }

    // LO Headshot: use profile photo or upload a new one
    (function setupRqLoHeadshot() {
        var hiddenUrl    = document.getElementById('rq-lo-photo-url');
        var uploadWrap   = document.getElementById('rq-lo-photo-upload-wrap');
        var uploadDiv    = document.getElementById('rq-lo-photo-upload');
        var fileInput    = document.getElementById('rq-lo-photo-file');
        var statusEl     = document.getElementById('rq-lo-photo-status');
        var previewPhoto = document.getElementById('rq-preview-photo');
        if (!hiddenUrl || !fileInput) return;
        var profilePhoto = hiddenUrl.dataset.profilePhoto || (userData.photo || '');
        var radios = wizard.querySelectorAll('input[name="rq-headshot-source"]');
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
            uploadDiv.addEventListener('dragover', function(e) { e.preventDefault(); uploadDiv.style.borderColor = '#10b981'; });
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

    function submitWizard() {
        nextBtn.classList.add('is-loading');
        nextBtn.disabled = true;

        var data = {
            action: 'frs_create_rate_quote',
            nonce: nonce,
            user_mode: userMode,
            headline: document.getElementById('rq-headline') ? document.getElementById('rq-headline').value : '',
            subheadline: document.getElementById('rq-subheadline') ? document.getElementById('rq-subheadline').value : '',
            schedule_type: selectedScheduleType,
            calendar_id: document.getElementById('rq-calendar-id') ? document.getElementById('rq-calendar-id').value : ''
        };

        if (isLoanOfficer) {
            data.lo_name = document.getElementById('rq-lo-name') ? document.getElementById('rq-lo-name').value : userData.name;
            data.lo_nmls = document.getElementById('rq-lo-nmls') ? document.getElementById('rq-lo-nmls').value : '';
            data.lo_phone = document.getElementById('rq-lo-phone') ? document.getElementById('rq-lo-phone').value : '';
            data.lo_email = document.getElementById('rq-lo-email') ? document.getElementById('rq-lo-email').value : '';
            data.lo_photo = document.getElementById('rq-lo-photo-url') ? document.getElementById('rq-lo-photo-url').value : '';

            if (selectedPartner) {
                data.partner_id = selectedPartner.id;
                data.partner_name = selectedPartner.name;
                data.partner_license = selectedPartner.license;
                data.partner_company = selectedPartner.company;
                data.partner_phone = selectedPartner.phone;
                data.partner_email = selectedPartner.email;
            }
        } else {
            data.realtor_name = document.getElementById('rq-realtor-name') ? document.getElementById('rq-realtor-name').value : userData.name;
            data.realtor_license = document.getElementById('rq-realtor-license') ? document.getElementById('rq-realtor-license').value : '';
            data.realtor_phone = document.getElementById('rq-realtor-phone') ? document.getElementById('rq-realtor-phone').value : '';
            data.realtor_email = document.getElementById('rq-realtor-email') ? document.getElementById('rq-realtor-email').value : '';
            data.loan_officer_id = selectedPartner ? selectedPartner.id : '';
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then(function(res) { return res.json(); })
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
        document.querySelectorAll('.rq-step').forEach(function(el) { el.style.display = 'none'; });
        var successStep = document.querySelector('.rq-step[data-step="success"]');
        if (successStep) successStep.style.display = 'block';

        document.getElementById('rq-success-url').value = pageUrl;
        document.getElementById('rq-view-page').href = pageUrl;
        document.querySelector('.rq-wizard__footer').style.display = 'none';

        nextBtn.classList.remove('is-loading');
        nextBtn.disabled = false;
    }
})();
