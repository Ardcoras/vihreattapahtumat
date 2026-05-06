/* global Drupal */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.locationWidget = {
    attach(context) {
      context.querySelectorAll('[data-location-widget]:not(.lw-init)').forEach((el) => {
        el.classList.add('lw-init');
        new LocationWidget(el);
      });
    },
  };

  class LocationWidget {
    constructor(wrapper) {
      this.wrapper = wrapper;
      this.autocompleteUrl  = wrapper.dataset.autocompleteUrl;
      this.municipalityUrl  = wrapper.dataset.municipalityUrl;
      this.quickCreateUrl   = wrapper.dataset.quickCreateUrl;
      this.previewUrl       = wrapper.dataset.previewUrl;
      this.csrfToken        = wrapper.dataset.csrfToken;
      this.valueInput       = wrapper.querySelector('.location-widget__value');
      this.debounceTimer    = null;
      this.muniDebounceTimer = null;
      this.selectedMuniId   = null;

      this._buildUI();

      if (wrapper.dataset.hasValue === '1') {
        const tid = parseInt(wrapper.dataset.initialTid, 10);
        this._loadAndShowPreview(tid);
      } else {
        this._showSearch();
      }
    }

    // ── Build DOM ──────────────────────────────────────────────────────────

    _buildUI() {
      // Search phase
      this.searchPhase = this._el('div', 'lw-search-phase');

      this.searchInput = this._el('input', 'lw-search form-text');
      this.searchInput.type = 'text';
      this.searchInput.placeholder = Drupal.t('Hae paikkaa tai kuntaa\u2026');
      this.searchInput.setAttribute('autocomplete', 'off');
      this.searchInput.setAttribute('aria-autocomplete', 'list');
      this.searchInput.setAttribute('aria-expanded', 'false');

      this.dropdown = this._el('ul', 'lw-dropdown');
      this.dropdown.setAttribute('role', 'listbox');
      this.dropdown.hidden = true;

      this.searchPhase.append(this.searchInput, this.dropdown);

      // Preview phase (replaces chip)
      this.previewPhase = this._el('div', 'lw-preview-phase');
      this.previewPhase.hidden = true;

      this.previewContainer = this._el('div', 'lw-preview-container');

      this.reselectBtn = this._el('button', 'lw-reselect');
      this.reselectBtn.type = 'button';
      this.reselectBtn.innerHTML =
        '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">'
        + '<path d="M10 2A5 5 0 1 0 11 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        + '<path d="M8 0l2 2-2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
        + '</svg> '
        + Drupal.t('Valitse sijainti uudelleen');

      this.previewPhase.append(this.previewContainer, this.reselectBtn);

      // Create phase
      this.createPhase = this._el('div', 'lw-create-phase');
      this.createPhase.hidden = true;

      this.createName    = this._makeField(Drupal.t('Nimi'), true);
      this.createAddress = this._makeField(Drupal.t('Katuosoite'), false);

      // Municipality sub-autocomplete
      const muniFieldEl = this._el('div', 'lw-create-field');
      const muniLabel = this._el('label', 'lw-create-label');
      muniLabel.innerHTML = Drupal.t('Kunta') + ' <span class="lw-required" aria-hidden="true">*</span>';

      this.muniSearch = this._el('input', 'lw-muni-search form-text');
      this.muniSearch.type = 'text';
      this.muniSearch.setAttribute('autocomplete', 'off');
      this.muniSearch.placeholder = Drupal.t('Hae kuntaa\u2026');

      this.muniHidden = this._el('input', 'lw-muni-value');
      this.muniHidden.type = 'hidden';

      this.muniDropdown = this._el('ul', 'lw-dropdown lw-muni-dropdown');
      this.muniDropdown.hidden = true;

      muniFieldEl.append(muniLabel, this.muniSearch, this.muniDropdown, this.muniHidden);

      const actions = this._el('div', 'lw-create-actions');
      this.cancelBtn = this._el('button', 'lw-cancel button');
      this.cancelBtn.type = 'button';
      this.cancelBtn.textContent = Drupal.t('Peruuta');
      this.saveBtn = this._el('button', 'lw-save button button--primary');
      this.saveBtn.type = 'button';
      this.saveBtn.textContent = Drupal.t('Tallenna paikka');
      actions.append(this.cancelBtn, this.saveBtn);

      this.createPhase.append(
        this.createName.wrapper,
        this.createAddress.wrapper,
        muniFieldEl,
        actions,
      );

      this.wrapper.append(this.searchPhase, this.previewPhase, this.createPhase);
      this._bindEvents();
    }

    _makeField(labelText, required) {
      const wrapper = this._el('div', 'lw-create-field');
      const label = this._el('label', 'lw-create-label');
      label.innerHTML = labelText + (required ? ' <span class="lw-required" aria-hidden="true">*</span>' : '');
      const input = this._el('input', 'form-text');
      input.type = 'text';
      wrapper.append(label, input);
      return { wrapper, input };
    }

    _el(tag, cls) {
      const el = document.createElement(tag);
      el.className = cls;
      return el;
    }

    // ── Events ─────────────────────────────────────────────────────────────

    _bindEvents() {
      this.searchInput.addEventListener('input', () => {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => this._fetchResults(), 200);
      });

      this.searchInput.addEventListener('keydown', (e) => this._handleKey(e, this.dropdown));

      document.addEventListener('click', (e) => {
        if (!this.wrapper.contains(e.target)) this._closeDropdown();
      });

      this.reselectBtn.addEventListener('click', () => {
        this.valueInput.value = '';
        this.searchInput.value = '';
        this._showSearch();
        this.searchInput.focus();
      });

      this.cancelBtn.addEventListener('click', () => {
        this.searchInput.value = '';
        this._showSearch();
        this.searchInput.focus();
      });

      this.saveBtn.addEventListener('click', () => this._submitCreate());

      this.muniSearch.addEventListener('input', () => {
        this.muniHidden.value = '';
        this.selectedMuniId = null;
        clearTimeout(this.muniDebounceTimer);
        this.muniDebounceTimer = setTimeout(() => this._fetchMunicipalities(), 200);
      });

      this.muniSearch.addEventListener('keydown', (e) => this._handleKey(e, this.muniDropdown));
    }

    // ── Main autocomplete ──────────────────────────────────────────────────

    async _fetchResults() {
      const q = this.searchInput.value.trim();
      if (q === '') { this._closeDropdown(); return; }

      try {
        const res = await fetch(`${this.autocompleteUrl}?q=${encodeURIComponent(q)}`);
        this._renderDropdown(await res.json());
      } catch (_) { this._closeDropdown(); }
    }

    _renderDropdown(items) {
      this.dropdown.innerHTML = '';
      this.dropdown.hidden = items.length === 0;
      this.searchInput.setAttribute('aria-expanded', items.length > 0 ? 'true' : 'false');

      items.forEach((item) => {
        const li = this._el('li', 'lw-option');
        li.setAttribute('role', 'option');

        if (item.bundle === 'create') {
          li.classList.add('lw-option--create');
          const q = this.searchInput.value.trim();
          li.innerHTML = `<span class="lw-option-icon">✨</span>`
            + `<span class="lw-option-label">${Drupal.t('Luo uusi paikka \u201c@name\u201d', { '@name': q })}</span>`;
        } else {
          const icon = item.bundle === 'place' ? '📍' : '🏙';
          li.innerHTML = `<span class="lw-option-icon">${icon}</span>`
            + `<span class="lw-option-label">${this._esc(item.label)}</span>`
            + (item.secondary ? `<span class="lw-option-secondary">${this._esc(item.secondary)}</span>` : '');
        }

        li.addEventListener('mousedown', (e) => { e.preventDefault(); this._selectOption(item); });
        this.dropdown.append(li);
      });
    }

    _selectOption(item) {
      this._closeDropdown();
      if (item.bundle === 'create') {
        this._openCreate(this.searchInput.value.trim());
      } else {
        this.valueInput.value = item.id;
        this._loadAndShowPreview(item.id);
      }
    }

    // ── Preview ────────────────────────────────────────────────────────────

    async _loadAndShowPreview(tid) {
      this.previewContainer.innerHTML = '<span class="lw-loading">\u2026</span>';
      this._showPreview();

      try {
        const res = await fetch(`${this.previewUrl}?tid=${encodeURIComponent(tid)}`);
        const data = await res.json();
        this.previewContainer.innerHTML = data.html;
      } catch (_) {
        this.previewContainer.innerHTML = '<em>' + Drupal.t('Esikatselu ei saatavilla') + '</em>';
      }
    }

    // ── Municipality autocomplete ──────────────────────────────────────────

    async _fetchMunicipalities() {
      const q = this.muniSearch.value.trim();
      if (q === '') { this.muniDropdown.hidden = true; return; }

      try {
        const res = await fetch(`${this.municipalityUrl}?q=${encodeURIComponent(q)}`);
        const items = await res.json();
        this.muniDropdown.innerHTML = '';
        this.muniDropdown.hidden = items.length === 0;
        items.forEach((item) => {
          const li = this._el('li', 'lw-option');
          li.textContent = item.label;
          li.addEventListener('mousedown', (e) => {
            e.preventDefault();
            this.muniSearch.value = item.label;
            this.muniHidden.value = item.id;
            this.selectedMuniId = item.id;
            this.muniDropdown.hidden = true;
          });
          this.muniDropdown.append(li);
        });
      } catch (_) { this.muniDropdown.hidden = true; }
    }

    // ── Quick create ───────────────────────────────────────────────────────

    _openCreate(name) {
      this.createName.input.value = name;
      this.createAddress.input.value = '';
      this.muniSearch.value = '';
      this.muniHidden.value = '';
      this.selectedMuniId = null;
      this.saveBtn.disabled = false;
      this.saveBtn.textContent = Drupal.t('Tallenna paikka');
      this._showCreate();
      this.createName.input.focus();
    }

    async _submitCreate() {
      const name   = this.createName.input.value.trim();
      const muniId = this.muniHidden.value;

      this.createName.input.classList.toggle('lw-error', !name);
      this.muniSearch.classList.toggle('lw-error', !muniId);
      if (!name) { this.createName.input.focus(); return; }
      if (!muniId) { this.muniSearch.focus(); return; }

      this.saveBtn.disabled = true;
      this.saveBtn.textContent = Drupal.t('Tallennetaan\u2026');

      try {
        const res = await fetch(this.quickCreateUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Location-Widget-Token': this.csrfToken },
          body: JSON.stringify({ name, municipality_id: parseInt(muniId, 10), street_address: this.createAddress.input.value.trim() }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Error');

        this.valueInput.value = data.id;
        this._loadAndShowPreview(data.id);
      } catch (err) {
        this.saveBtn.disabled = false;
        this.saveBtn.textContent = Drupal.t('Tallenna paikka');
        alert(Drupal.t('Virhe tallennuksessa: @msg', { '@msg': err.message }));
      }
    }

    // ── Keyboard navigation ────────────────────────────────────────────────

    _handleKey(e, dropdown) {
      const opts = [...dropdown.querySelectorAll('.lw-option')];
      const focused = dropdown.querySelector('.lw-option--focused');
      const idx = opts.indexOf(focused);

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        focused?.classList.remove('lw-option--focused');
        (opts[idx + 1] ?? opts[0])?.classList.add('lw-option--focused');
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focused?.classList.remove('lw-option--focused');
        (opts[idx - 1] ?? opts[opts.length - 1])?.classList.add('lw-option--focused');
      } else if (e.key === 'Enter' && focused) {
        e.preventDefault();
        focused.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
      } else if (e.key === 'Escape') {
        dropdown.hidden = true;
      }
    }

    // ── Phase transitions ──────────────────────────────────────────────────

    _showSearch()  { this.searchPhase.hidden = false; this.previewPhase.hidden = true;  this.createPhase.hidden = true; }
    _showPreview() { this.previewPhase.hidden = false; this.searchPhase.hidden = true;  this.createPhase.hidden = true; }
    _showCreate()  { this.createPhase.hidden = false;  this.searchPhase.hidden = true;  this.previewPhase.hidden = true; }
    _closeDropdown() { this.dropdown.hidden = true; this.searchInput.setAttribute('aria-expanded', 'false'); }
    _esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  }

}(Drupal));
