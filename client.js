/**
 * client.js — CV Maker frontend
 *
 * Implements the CVForm class which manages the multi-section CV form,
 * live preview, auto-save, validation, and delete flow.
 *
 * Requirements: 1.1–1.11, 2.1–2.5, 3.1–3.2, 3.6, 4.1, 4.3–4.4,
 *               5.1, 5.4–5.5, 10.1, 10.5
 */

'use strict';

class CVForm {
  /**
   * @param {HTMLFormElement} formEl     - The #cv-form element
   * @param {HTMLElement}     previewEl  - The #preview-pane element
   */
  constructor(formEl, previewEl) {
    this._form      = formEl;
    this._preview   = previewEl;
    this._cvId      = null;       // current cv_id (null until first save)
    this._photoData = null;       // base64 data URL of the selected photo
  }

  // -------------------------------------------------------------------------
  // Public API
  // -------------------------------------------------------------------------

  /**
   * Bind all event listeners and restore state from URL if cv_id is present.
   * Requirements: 1.1, 1.2, 4.1, 4.3
   */
  init() {
    // Summary character counter
    const summaryTextarea = document.getElementById('summary-text');
    const summaryCount    = document.getElementById('summary-count');
    if (summaryTextarea && summaryCount) {
      summaryTextarea.addEventListener('input', () => {
        summaryCount.textContent = summaryTextarea.value.length;
      });
    }

    // Photo upload
    const photoInput   = document.getElementById('photo-input');
    const photoRemove  = document.getElementById('photo-remove');
    if (photoInput) {
      photoInput.addEventListener('change', () => this._handlePhotoChange(photoInput));
    }
    if (photoRemove) {
      photoRemove.addEventListener('click', () => this._clearPhoto());
    }

    // Optional section toggles (Req 1.3, 1.4)
    this._form.querySelectorAll('.optional-toggle').forEach(toggle => {
      toggle.addEventListener('change', () => {
        this.toggleOptionalSection(toggle.dataset.section);
        this._scheduleAutoSave();
      });
    });

    // "Add Entry" buttons (Req 1.10)
    this._form.querySelectorAll('.btn-add-entry').forEach(btn => {
      btn.addEventListener('click', () => {
        this.addRepeatingEntry(btn.dataset.target);
      });
    });

    // "Remove" buttons on initial entries (Req 1.11)
    this._form.querySelectorAll('.btn-remove-entry').forEach(btn => {
      btn.addEventListener('click', () => {
        this.removeRepeatingEntry(btn.closest('.entry-row'));
      });
    });

    // Auto-save on any input change (Req 3.1) — removed in favour of manual save button

    // Save button — manual save
    const saveBtn = document.getElementById('btn-save-cv');
    if (saveBtn) {
      saveBtn.addEventListener('click', () => this.save());
    }

    // Delete button (Req 10.1)
    const deleteBtn = document.getElementById('btn-delete-cv');
    if (deleteBtn) {
      deleteBtn.addEventListener('click', () => this._handleDelete());
    }

    // Restore from URL cv_id if present (Req 4.1)
    const params = new URLSearchParams(window.location.search);
    const urlCvId = params.get('cv_id');
    if (urlCvId) {
      this._cvId = parseInt(urlCvId, 10) || null;
      if (this._cvId) {
        this._loadCv(this._cvId);
      }
    }

    // Initialise download buttons — disable them until a cv_id is available
    this._updateDownloadLinks(this._cvId);
  }

  /**
   * Read all active section fields and return a structured CVData object.
   * Deactivated optional sections are excluded.
   * Requirements: 1.1, 1.2, 1.4
   *
   * @returns {Object} CVData
   */
  collectData() {
    const data = {};

    // ── Profile Photo ──
    if (this._photoData) {
      data.photo = this._photoData;
    }

    // ── Contact Information ──
    data.contact = {
      name:     this._val('contact-name'),
      phone:    this._val('contact-phone'),
      email:    this._val('contact-email'),
      linkedin: this._val('contact-linkedin'),
      address:  this._val('contact-address'),
    };

    // ── Professional Summary ──
    data.summary = this._val('summary-text');

    // ── Work Experience (repeating) ──
    data.work_experience = this._collectRepeatingEntries(
      '#work-entries .entry-row',
      entry => ({
        job_title:        this._entryVal(entry, '[name*="job_title"]'),
        company:          this._entryVal(entry, '[name*="company"]'),
        start_date:       this._entryVal(entry, '[name*="start_date"]'),
        end_date:         this._entryVal(entry, '[name*="end_date"]'),
        present:          this._entryChecked(entry, '[name*="present"]'),
        responsibilities: this._entryVal(entry, '[name*="responsibilities"]'),
      })
    );

    // ── Skills ──
    data.skills = this._val('skills-input');

    // ── Education (repeating) ──
    data.education = this._collectRepeatingEntries(
      '#education-entries .entry-row',
      entry => ({
        degree:          this._entryVal(entry, '[name*="degree"]'),
        institution:     this._entryVal(entry, '[name*="institution"]'),
        graduation_date: this._entryVal(entry, '[name*="graduation_date"]'),
        honours:         this._entryVal(entry, '[name*="honours"]'),
      })
    );

    // ── Optional Sections ──
    data.optional_sections = {};
    const optionalNames = [
      'projects', 'certifications', 'awards',
      'languages', 'publications', 'memberships', 'references',
    ];

    optionalNames.forEach(name => {
      const toggle = this._form.querySelector(`.optional-toggle[data-section="${name}"]`);
      if (!toggle || !toggle.checked) return; // excluded when inactive

      const entriesContainer = document.getElementById(`${name}-entries`);
      if (!entriesContainer) return;

      const entries = this._collectRepeatingEntries(
        `#${name}-entries .entry-row`,
        entry => this._collectEntryFields(entry)
      );

      data.optional_sections[name] = { active: true, entries };
    });

    // Include cv_id if we have one
    if (this._cvId) {
      data.cv_id = this._cvId;
    }

    return data;
  }

  /**
   * Validate all required fields in active sections.
   * Requirements: 2.1–2.5
   *
   * @returns {{ valid: boolean, errors: string[] }}
   */
  validate() {
    const errors = [];
    this._clearAllErrors();

    // ── Contact ──
    if (!this._val('contact-name').trim()) {
      errors.push('Full Name is required');
      this._showError('contact-name', 'err-contact-name', 'Full Name is required');
    }
    if (!this._val('contact-phone').trim()) {
      errors.push('Phone Number is required');
      this._showError('contact-phone', 'err-contact-phone', 'Phone Number is required');
    }

    const email = this._val('contact-email').trim();
    if (!email) {
      errors.push('Email Address is required');
      this._showError('contact-email', 'err-contact-email', 'Email Address is required');
    } else if (!this._isValidEmail(email)) {
      errors.push('Email Address is not valid');
      this._showError('contact-email', 'err-contact-email', 'Enter a valid email address');
    }

    const linkedin = this._val('contact-linkedin').trim();
    if (linkedin && !linkedin.startsWith('https://')) {
      errors.push('LinkedIn URL must start with https://');
      this._showError('contact-linkedin', 'err-contact-linkedin', 'LinkedIn URL must start with https://');
    }

    // ── Summary ──
    if (!this._val('summary-text').trim()) {
      errors.push('Professional Summary is required');
      this._showError('summary-text', 'err-summary', 'Professional Summary is required');
    }

    // ── Work Experience ──
    this._form.querySelectorAll('#work-entries .entry-row').forEach((entry, i) => {
      const label = `Work Experience #${i + 1}`;
      this._requireEntryField(entry, '[name*="job_title"]', `${label}: Job Title is required`, errors);
      this._requireEntryField(entry, '[name*="company"]',   `${label}: Company is required`, errors);
      this._requireEntryField(entry, '[name*="start_date"]', `${label}: Start Date is required`, errors);

      const isPresent = this._entryChecked(entry, '[name*="present"]');
      if (!isPresent) {
        this._requireEntryField(entry, '[name*="end_date"]', `${label}: End Date is required (or check Present)`, errors);
      }

      this._requireEntryField(entry, '[name*="responsibilities"]', `${label}: Responsibilities are required`, errors);
    });

    // ── Skills ──
    if (!this._val('skills-input').trim()) {
      errors.push('Skills are required');
      this._showError('skills-input', 'err-skills', 'Skills are required');
    }

    // ── Education ──
    this._form.querySelectorAll('#education-entries .entry-row').forEach((entry, i) => {
      const label = `Education #${i + 1}`;
      this._requireEntryField(entry, '[name*="degree"]',          `${label}: Degree is required`, errors);
      this._requireEntryField(entry, '[name*="institution"]',     `${label}: Institution is required`, errors);
      this._requireEntryField(entry, '[name*="graduation_date"]', `${label}: Graduation Date is required`, errors);
    });

    return { valid: errors.length === 0, errors };
  }

  /**
   * Show or hide an optional section's fields.
   * Requirements: 1.3, 1.4
   *
   * @param {string} name - section key (e.g. 'projects')
   */
  toggleOptionalSection(name) {
    const toggle = this._form.querySelector(`.optional-toggle[data-section="${name}"]`);
    if (!toggle) return;

    const container = toggle.closest('.optional-section');
    if (!container) return;

    const fields = container.querySelector('.optional-section-fields');
    if (!fields) return;

    if (toggle.checked) {
      fields.removeAttribute('hidden');
    } else {
      fields.setAttribute('hidden', '');
    }
  }

  /**
   * Clone the first entry-row in the container and append it.
   * Requirements: 1.10
   *
   * @param {string} containerId - id of the .repeating-entries container
   */
  addRepeatingEntry(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const template = container.querySelector('.entry-row');
    if (!template) return;

    const clone = template.cloneNode(true);

    // Clear all input/textarea/select values in the clone
    clone.querySelectorAll('input:not([type="checkbox"]), textarea').forEach(el => {
      el.value = '';
      el.classList.remove('is-invalid');
    });
    clone.querySelectorAll('input[type="checkbox"]').forEach(el => {
      el.checked = false;
    });
    clone.querySelectorAll('select').forEach(el => {
      el.selectedIndex = 0;
    });
    clone.querySelectorAll('.field-error').forEach(el => {
      el.textContent = '';
    });

    // Bind remove button on the clone
    const removeBtn = clone.querySelector('.btn-remove-entry');
    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        this.removeRepeatingEntry(clone);
      });
    }

    container.appendChild(clone);
  }

  /**
   * Remove a repeating entry row from the DOM.
   * Requirements: 1.11
   *
   * @param {HTMLElement} entryEl - the .entry-row element to remove
   */
  removeRepeatingEntry(entryEl) {
    if (!entryEl) return;

    const container = entryEl.closest('.repeating-entries');
    if (!container) return;

    // Keep at least one entry in core sections
    const isCoreSection = container.id === 'work-entries' || container.id === 'education-entries';
    if (isCoreSection && container.querySelectorAll('.entry-row').length <= 1) {
      return; // don't remove the last entry in a core section
    }

    entryEl.remove();
  }

  // -------------------------------------------------------------------------
  // Auto-save (Req 3.1, 3.2, 3.6, 5.1)
  // -------------------------------------------------------------------------

  /**
   * Validate and save the CV to the server.
   * Called by the Save CV button.
   */
  save() {
    const result = this.validate();
    if (!result.valid) {
      this._setStatus('Please fix the errors above before saving.', 'error');
      return;
    }

    const saveBtn = document.getElementById('btn-save-cv');
    if (saveBtn) saveBtn.disabled = true;

    const data = this.collectData();
    this._setStatus('Saving…', 'saving');

    fetch('/api/cv/save', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(data),
    })
      .then(res => res.json())
      .then(json => {
        if (json.success) {
          this._cvId = json.cv_id;
          this._updateUrlCvId(this._cvId);
          this._updateDownloadLinks(this._cvId);
          this._setStatus('Saved', 'saved');
          // Open the full-page preview
          window.location.href = `preview.php?cv_id=${this._cvId}`;
        } else {
          this._setStatus('Save failed', 'error');
          const saveBtn = document.getElementById('btn-save-cv');
          if (saveBtn) saveBtn.disabled = false;
        }
      })
      .catch(() => {
        this._setStatus('Save failed — check your connection', 'error');
        const saveBtn = document.getElementById('btn-save-cv');
        if (saveBtn) saveBtn.disabled = false;
      });
  }

  /**
   * @deprecated Use save() instead. Kept for backward compatibility.
   */
  autoSave() {
    this.save();
  }

  // -------------------------------------------------------------------------
  // Preview injection (Req 5.4, 5.5)
  // -------------------------------------------------------------------------

  /**
   * Fetch rendered HTML preview and inject into the preview pane.
   * @param {number} cvId
   */
  _fetchPreview(cvId) {
    fetch(`/api/cv/preview?cv_id=${cvId}`)
      .then(res => {
        if (!res.ok) return;
        return res.text();
      })
      .then(html => {
        if (html) {
          this._preview.innerHTML = html;
        }
      })
      .catch(() => {
        // Preview failure is non-blocking
      });
  }

  // -------------------------------------------------------------------------
  // Delete flow (Req 10.1, 10.5)
  // -------------------------------------------------------------------------

  _handleDelete() {
    if (!this._cvId) return;

    if (!window.confirm('Are you sure you want to delete your CV? This cannot be undone.')) {
      return;
    }

    fetch(`/api/cv/delete?cv_id=${this._cvId}`, { method: 'DELETE' })
      .then(res => res.json())
      .then(json => {
        if (json.success) {
          this._cvId = null;
          this._clearForm();
          this._clearPreview();
          this._clearPhoto();
          this._updateUrlCvId(null);
          this._updateDownloadLinks(null);
          this._setStatus('CV deleted', 'saved');
        } else {
          this._setStatus('Delete failed', 'error');
        }
      })
      .catch(() => {
        this._setStatus('Delete failed — check your connection', 'error');
      });
  }

  // -------------------------------------------------------------------------
  // Load existing CV (Req 4.1, 4.3, 4.4)
  // -------------------------------------------------------------------------

  _loadCv(cvId) {
    fetch(`/api/cv/load?cv_id=${cvId}`)
      .then(res => res.json())
      .then(json => {
        if (json.success && json.cv_data) {
          this._populateForm(json.cv_data);
          this._updateDownloadLinks(cvId);
          this._fetchPreview(cvId);
        }
      })
      .catch(() => {
        // Non-fatal — form stays empty
      });
  }

  /**
   * Populate all form fields from a CVData object.
   * @param {Object} cvData
   */
  _populateForm(cvData) {
    // Photo
    if (cvData.photo) {
      this._photoData = cvData.photo;
      this._showPhotoPreview(cvData.photo);
    }

    // Contact
    if (cvData.contact) {
      this._setVal('contact-name',     cvData.contact.name     || '');
      this._setVal('contact-phone',    cvData.contact.phone    || '');
      this._setVal('contact-email',    cvData.contact.email    || '');
      this._setVal('contact-linkedin', cvData.contact.linkedin || '');
      this._setVal('contact-address',  cvData.contact.address  || '');
    }

    // Summary
    if (cvData.summary !== undefined) {
      this._setVal('summary-text', cvData.summary || '');
      const counter = document.getElementById('summary-count');
      if (counter) counter.textContent = (cvData.summary || '').length;
    }

    // Work Experience
    if (Array.isArray(cvData.work_experience)) {
      this._populateRepeatingEntries(
        'work-entries', cvData.work_experience,
        (entry, item) => {
          this._setEntryVal(entry, '[name*="job_title"]',        item.job_title        || '');
          this._setEntryVal(entry, '[name*="company"]',          item.company          || '');
          this._setEntryVal(entry, '[name*="start_date"]',       item.start_date       || '');
          this._setEntryVal(entry, '[name*="end_date"]',         item.end_date         || '');
          this._setEntryChecked(entry, '[name*="present"]',      !!item.present);
          this._setEntryVal(entry, '[name*="responsibilities"]', item.responsibilities || '');
        }
      );
    }

    // Skills
    if (cvData.skills !== undefined) {
      this._setVal('skills-input', cvData.skills || '');
    }

    // Education
    if (Array.isArray(cvData.education)) {
      this._populateRepeatingEntries(
        'education-entries', cvData.education,
        (entry, item) => {
          this._setEntryVal(entry, '[name*="degree"]',          item.degree          || '');
          this._setEntryVal(entry, '[name*="institution"]',     item.institution     || '');
          this._setEntryVal(entry, '[name*="graduation_date"]', item.graduation_date || '');
          this._setEntryVal(entry, '[name*="honours"]',         item.honours         || '');
        }
      );
    }

    // Optional sections (Req 4.4)
    if (cvData.optional_sections) {
      Object.entries(cvData.optional_sections).forEach(([name, section]) => {
        if (!section || !section.active) return;

        const toggle = this._form.querySelector(`.optional-toggle[data-section="${name}"]`);
        if (toggle) {
          toggle.checked = true;
          this.toggleOptionalSection(name);
        }

        if (Array.isArray(section.entries)) {
          this._populateRepeatingEntries(
            `${name}-entries`, section.entries,
            (entry, item) => this._populateEntryFields(entry, item)
          );
        }
      });
    }
  }

  // -------------------------------------------------------------------------
  // Private helpers
  // -------------------------------------------------------------------------

  /** Get trimmed value of an element by id. */
  _val(id) {
    const el = document.getElementById(id);
    return el ? el.value : '';
  }

  /** Set value of an element by id. */
  _setVal(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value;
  }

  /** Get value of a field inside an entry row using a CSS selector. */
  _entryVal(entryEl, selector) {
    const el = entryEl.querySelector(selector);
    return el ? el.value : '';
  }

  /** Get checked state of a checkbox inside an entry row. */
  _entryChecked(entryEl, selector) {
    const el = entryEl.querySelector(selector);
    return el ? el.checked : false;
  }

  /** Set value of a field inside an entry row. */
  _setEntryVal(entryEl, selector, value) {
    const el = entryEl.querySelector(selector);
    if (el) el.value = value;
  }

  /** Set checked state of a checkbox inside an entry row. */
  _setEntryChecked(entryEl, selector, checked) {
    const el = entryEl.querySelector(selector);
    if (el) el.checked = checked;
  }

  /**
   * Collect all entry rows matching a selector and map them via a callback.
   * @param {string}   selector
   * @param {Function} mapper
   * @returns {Array}
   */
  _collectRepeatingEntries(selector, mapper) {
    return Array.from(this._form.querySelectorAll(selector)).map(mapper);
  }

  /**
   * Collect all named fields from an entry row as a plain object.
   * Used for optional sections with arbitrary field names.
   * @param {HTMLElement} entry
   * @returns {Object}
   */
  _collectEntryFields(entry) {
    const obj = {};
    entry.querySelectorAll('input, textarea, select').forEach(el => {
      if (!el.name) return;
      // Extract the last key from names like optional_sections[projects][entries][][name]
      const match = el.name.match(/\[([^\[\]]+)\]$/);
      if (!match) return;
      const key = match[1];
      if (el.type === 'checkbox') {
        obj[key] = el.checked;
      } else {
        obj[key] = el.value;
      }
    });
    return obj;
  }

  /**
   * Populate a repeating entries container from an array of data items.
   * Ensures the right number of entry rows exist, cloning as needed.
   *
   * @param {string}   containerId
   * @param {Array}    items
   * @param {Function} populateFn  - (entryEl, item) => void
   */
  _populateRepeatingEntries(containerId, items, populateFn) {
    const container = document.getElementById(containerId);
    if (!container || !items.length) return;

    const existing = Array.from(container.querySelectorAll('.entry-row'));

    // Add extra rows if needed
    while (container.querySelectorAll('.entry-row').length < items.length) {
      this.addRepeatingEntry(containerId);
    }

    const rows = Array.from(container.querySelectorAll('.entry-row'));
    items.forEach((item, i) => {
      if (rows[i]) populateFn(rows[i], item);
    });
  }

  /**
   * Populate arbitrary fields in an optional section entry row.
   * @param {HTMLElement} entry
   * @param {Object}      item
   */
  _populateEntryFields(entry, item) {
    entry.querySelectorAll('input, textarea, select').forEach(el => {
      if (!el.name) return;
      const match = el.name.match(/\[([^\[\]]+)\]$/);
      if (!match) return;
      const key = match[1];
      if (!(key in item)) return;
      if (el.type === 'checkbox') {
        el.checked = !!item[key];
      } else {
        el.value = item[key] || '';
      }
    });
  }

  /** Show an inline error for a field. */
  _showError(fieldId, errorId, message) {
    const field = document.getElementById(fieldId);
    if (field) field.classList.add('is-invalid');

    const errEl = document.getElementById(errorId);
    if (errEl) errEl.textContent = message;
  }

  /** Show an inline error on a field inside an entry row. */
  _requireEntryField(entryEl, selector, message, errors) {
    const el = entryEl.querySelector(selector);
    if (!el) return;
    if (!el.value.trim()) {
      errors.push(message);
      el.classList.add('is-invalid');
      const errEl = el.closest('.field-group')?.querySelector('.field-error');
      if (errEl) errEl.textContent = message.split(': ')[1] || message;
    }
  }

  /** Clear all validation error states from the form. */
  _clearAllErrors() {
    this._form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    this._form.querySelectorAll('.field-error').forEach(el => { el.textContent = ''; });
  }

  /** Validate email format. */
  _isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /** Update the save-status element. */
  _setStatus(message, type = '') {
    const el = document.getElementById('save-status');
    if (!el) return;
    el.textContent = message;
    el.className = 'save-status' + (type ? ` ${type}` : '');
  }

  /** Update the cv_id query param in the URL without reloading. */
  _updateUrlCvId(cvId) {
    const url = new URL(window.location.href);
    if (cvId) {
      url.searchParams.set('cv_id', cvId);
    } else {
      url.searchParams.delete('cv_id');
    }
    window.history.replaceState({}, '', url.toString());
  }

  /** Update download button hrefs with the current cv_id. */
  _updateDownloadLinks(cvId) {
    const pdfBtn  = document.getElementById('btn-download-pdf');
    const docxBtn = document.getElementById('btn-download-docx');

    if (cvId) {
      if (pdfBtn) {
        pdfBtn.href = `download.php?cv_id=${cvId}&format=pdf`;
        pdfBtn.removeAttribute('aria-disabled');
        pdfBtn.classList.remove('btn-disabled');
      }
      if (docxBtn) {
        docxBtn.href = `download.php?cv_id=${cvId}&format=docx`;
        docxBtn.removeAttribute('aria-disabled');
        docxBtn.classList.remove('btn-disabled');
      }
    } else {
      if (pdfBtn) {
        pdfBtn.href = '#';
        pdfBtn.setAttribute('aria-disabled', 'true');
        pdfBtn.classList.add('btn-disabled');
      }
      if (docxBtn) {
        docxBtn.href = '#';
        docxBtn.setAttribute('aria-disabled', 'true');
        docxBtn.classList.add('btn-disabled');
      }
    }
  }

  /** Clear all form fields. */
  _clearForm() {
    this._form.querySelectorAll('input:not([type="checkbox"]), textarea').forEach(el => {
      el.value = '';
    });
    this._form.querySelectorAll('input[type="checkbox"]').forEach(el => {
      el.checked = false;
    });
    this._form.querySelectorAll('select').forEach(el => {
      el.selectedIndex = 0;
    });
    this._clearAllErrors();

    // Hide all optional sections
    this._form.querySelectorAll('.optional-toggle').forEach(toggle => {
      toggle.checked = false;
      this.toggleOptionalSection(toggle.dataset.section);
    });

    // Reset summary counter
    const counter = document.getElementById('summary-count');
    if (counter) counter.textContent = '0';
  }

  /** Clear the preview pane. */
  _clearPreview() {
    this._preview.innerHTML = '<div class="preview-placeholder"><p>Your CV preview will appear here as you fill in the form.</p></div>';
  }

  // -------------------------------------------------------------------------
  // Photo helpers
  // -------------------------------------------------------------------------

  _handlePhotoChange(input) {
    const file = input.files && input.files[0];
    const errEl = document.getElementById('err-photo');

    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
      if (errEl) errEl.textContent = 'Image must be under 2 MB';
      input.value = '';
      return;
    }

    if (errEl) errEl.textContent = '';

    const reader = new FileReader();
    reader.onload = (e) => {
      this._photoData = e.target.result;
      this._showPhotoPreview(this._photoData);
    };
    reader.readAsDataURL(file);
  }

  _showPhotoPreview(dataUrl) {
    const preview     = document.getElementById('photo-preview');
    const placeholder = document.getElementById('photo-placeholder');
    const removeBtn   = document.getElementById('photo-remove');

    if (preview)     { preview.src = dataUrl; preview.removeAttribute('hidden'); }
    if (placeholder) placeholder.setAttribute('hidden', '');
    if (removeBtn)   removeBtn.removeAttribute('hidden');
  }

  _clearPhoto() {
    this._photoData = null;

    const preview     = document.getElementById('photo-preview');
    const placeholder = document.getElementById('photo-placeholder');
    const removeBtn   = document.getElementById('photo-remove');
    const input       = document.getElementById('photo-input');

    if (preview)     { preview.src = ''; preview.setAttribute('hidden', ''); }
    if (placeholder) placeholder.removeAttribute('hidden');
    if (removeBtn)   removeBtn.setAttribute('hidden', '');
    if (input)       input.value = '';
  }
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
  const formEl    = document.getElementById('cv-form');
  const previewEl = document.getElementById('preview-pane');

  if (formEl && previewEl) {
    const cvForm = new CVForm(formEl, previewEl);
    cvForm.init();
  }
});
