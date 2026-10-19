(function () {
  const diagnosticStorageKey = '1g1a-pwa-diagnostics-v1';
  const diagnosticLimit = 100;
  const isStandaloneApp = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

  function readDiagnostics() {
    try {
      const value = JSON.parse(localStorage.getItem(diagnosticStorageKey) || '[]');
      return Array.isArray(value) ? value.filter((item) => item && typeof item.id === 'string') : [];
    } catch (_) { return []; }
  }

  function cleanDiagnosticText(value) {
    return String(value || '')
      .replace(/https?:\/\/[^\s)'"<>]+/gi, '[url]')
      .replace(/\bBearer\s+\S+/gi, 'Bearer [redacted]')
      .replace(/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/g, '[token]')
      .replace(/\b(?:access[_-]?token|refresh[_-]?token|authorization|cookie)\s*[:=]\s*\S+/gi, '[credential]')
      .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[email]')
      .replace(/\b[a-f0-9]{32,}\b/gi, '[token]')
      .slice(0, 500);
  }

  function recordDiagnostic(kind, message) {
    if (!isStandaloneApp) return;
    const records = readDiagnostics();
    records.push({ id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`, at: new Date().toISOString(), kind: String(kind).slice(0, 40), message: cleanDiagnosticText(message) });
    const cutoff = Date.now() - 7 * 24 * 60 * 60 * 1000;
    try { localStorage.setItem(diagnosticStorageKey, JSON.stringify(records.filter((item) => Date.parse(item.at) >= cutoff).slice(-diagnosticLimit))); } catch (_) { /* best-effort */ }
  }

  window.addEventListener('error', (event) => {
    const source = event.filename ? cleanDiagnosticText(event.filename) : '';
    recordDiagnostic('javascript_error', `${event.error?.name || 'Error'}: ${event.message || 'unknown'}${source ? ` (${source})` : ''}`);
  });
  window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason;
    recordDiagnostic('unhandled_rejection', reason instanceof Error ? `${reason.name}: ${reason.message}` : String(reason || 'unknown rejection'));
  });
  window.addEventListener('online', () => recordDiagnostic('network', 'online'));
  window.addEventListener('offline', () => recordDiagnostic('network', 'offline'));

  function handleDiagnosticUpload() {
    const form = document.querySelector('[data-diagnostic-form]');
    const field = form?.querySelector('[name="diagnostics_json"]');
    if (!form || !field) return;
    const url = new URL(location.href);
    if (url.searchParams.get('diagnostics') === 'sent') {
      try {
        const pendingIds = JSON.parse(sessionStorage.getItem('1g1a-pending-diagnostic-ids') || '[]');
        const pending = new Set(Array.isArray(pendingIds) ? pendingIds : []);
        localStorage.setItem(diagnosticStorageKey, JSON.stringify(readDiagnostics().filter((item) => !pending.has(item.id))));
        sessionStorage.removeItem('1g1a-pending-diagnostic-ids');
        url.searchParams.delete('diagnostics');
        history.replaceState(null, '', url);
      } catch (_) { /* keep logs if storage is unavailable */ }
    }
    form.addEventListener('submit', (event) => {
      const records = readDiagnostics();
      if (records.length === 0) {
        event.preventDefault();
        window.alert('送信できるアプリログはありません。');
        return;
      }
      field.value = JSON.stringify(records.map(({ id, at, kind, message }) => ({ id, at, kind, message })));
      try { sessionStorage.setItem('1g1a-pending-diagnostic-ids', JSON.stringify(records.map((item) => item.id))); } catch (_) { /* send still proceeds */ }
    });
  }

  recordDiagnostic('app_start', `${document.querySelector('meta[name="1g1a-app-version"]')?.content || 'unknown'}; standalone=${isStandaloneApp}`);

  async function cacheVisibleQrPages() {
    if (!navigator.onLine || !('serviceWorker' in navigator)) return;
    try {
      await navigator.serviceWorker.ready;
      const links = Array.from(document.querySelectorAll('.event-list a.event-row')).slice(0, 5);
      await Promise.all(links.map(async (link) => {
        try {
          const response = await fetch(link.href, { credentials: 'same-origin' });
          if (!response.ok) return;
          const html = await response.text();
          const parsed = new DOMParser().parseFromString(html, 'text/html');
          const images = Array.from(parsed.images, (image) => image.src);
          await Promise.all(images.map((src) => {
            const crossOrigin = new URL(src).origin !== location.origin;
            return fetch(src, crossOrigin ? { mode: 'no-cors' } : { credentials: 'same-origin' }).catch(() => undefined);
          }));
        } catch (_) {
          // QR cache refresh is best-effort; the online page remains usable.
        }
      }));
    } catch (err) {
      console.warn('QR cache refresh failed', err);
    }
  }

  async function handleDashboard() {
    cacheVisibleQrPages();
    const form = document.getElementById('share-launcher');
    if (!form) {
      return;
    }

    const profileSelect = document.getElementById('profile-select');
    const status = document.getElementById('draft-status');
    const offlineGuidance = document.getElementById('offline-guidance');
    const photoInput = document.getElementById('share-photos');
    const photoSummary = document.getElementById('share-photo-summary');
    const attachLocationInput = document.getElementById('attach-location');
    const locationEnabledHint = document.getElementById('location-enabled-hint');
    const locationDisabledHint = document.getElementById('location-disabled-hint');
    const profilePreviews = Array.from(document.querySelectorAll('[data-profile-preview]'));

    function setStatus(message) {
      if (!status) return;
      status.hidden = false;
      status.textContent = message;
    }

    function updateOfflineGuidance() {
      if (offlineGuidance) offlineGuidance.hidden = navigator.onLine;
    }

    updateOfflineGuidance();
    window.addEventListener('online', updateOfflineGuidance);
    window.addEventListener('offline', updateOfflineGuidance);

    function setLocationAvailability(available) {
      if (!attachLocationInput) return;
      const wasDisabled = attachLocationInput.disabled;
      attachLocationInput.disabled = !available;
      if (!available) attachLocationInput.checked = false;
      else if (wasDisabled) attachLocationInput.checked = true;
      if (locationEnabledHint) locationEnabledHint.hidden = !available;
      if (locationDisabledHint) locationDisabledHint.hidden = available;
    }

    async function initializeLocationOption() {
      if (!attachLocationInput || !navigator.geolocation) {
        setLocationAvailability(false);
        return;
      }
      if (!navigator.permissions?.query) {
        setLocationAvailability(true);
        return;
      }
      try {
        const permission = await navigator.permissions.query({ name: 'geolocation' });
        const applyPermission = () => setLocationAvailability(permission.state !== 'denied');
        applyPermission();
        permission.addEventListener?.('change', applyPermission);
      } catch (_) {
        setLocationAvailability(true);
      }
    }

    function captureLocation() {
      return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
          (position) => resolve({
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            capturedAt: new Date().toISOString(),
          }),
          () => resolve(null),
          { enableHighAccuracy: true, maximumAge: 60000, timeout: 3500 }
        );
      });
    }

    function activateProfile(profileId, persist = false) {
      if (!profileId) return;
      const selectedOption = Array.from(profileSelect?.options || []).find((option) => option.value === profileId);
      if (!selectedOption) return;
      const skinId = selectedOption.dataset.guestSkin || 'light';
      profilePreviews.forEach((preview) => {
        preview.hidden = preview.dataset.profilePreview !== profileId;
      });
      document.body.classList.add('guest-skin-theme', 'guest-skin-dashboard', `guest-skin-theme-${skinId}`);
      Array.from(document.body.classList).forEach((className) => {
        if (className.startsWith('guest-skin-theme-') && className !== `guest-skin-theme-${skinId}`) {
          document.body.classList.remove(className);
        }
      });
      const skinWrapper = document.querySelector('.launch-section');
      if (skinWrapper) {
        Array.from(skinWrapper.classList).forEach((className) => {
          if (className.startsWith('guest-skin--') && className !== `guest-skin--${skinId}`) {
            skinWrapper.classList.remove(className);
          }
        });
        skinWrapper.classList.add('guest-skin', `guest-skin--${skinId}`);
      }
      const stylesheet = document.getElementById('dashboard-skin-stylesheet');
      const stylesheetUrl = selectedOption.dataset.guestSkinCss || '';
      if (stylesheet && stylesheetUrl && stylesheet.getAttribute('href') !== stylesheetUrl) {
        stylesheet.href = stylesheetUrl;
      }
      const themeColor = getComputedStyle(document.body).getPropertyValue('--bg').trim();
      const themeColorMeta = document.querySelector('meta[name="theme-color"]');
      if (themeColor && themeColorMeta) themeColorMeta.content = themeColor;
      if (persist) {
        const secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `1g1a_last_profile_id=${encodeURIComponent(profileId)}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
      }
    }

    profileSelect?.addEventListener('change', () => activateProfile(profileSelect.value, true));
    activateProfile(profileSelect?.value || '');

    photoInput?.addEventListener('change', () => {
      if (!photoSummary) return;
      const files = Array.from(photoInput.files || []);
      photoSummary.textContent = files.length === 0
        ? '写真は選択されていません'
        : files.length === 1 ? files[0].name : `${files.length}枚選択中`;
    });

    initializeLocationOption();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!navigator.onLine) {
        setStatus('QRの作成にはインターネット接続が必要です。通信を確認してからもう一度お試しください。');
        return;
      }

      const submitButton = event.submitter || form.querySelector('button[type="submit"]');
      const originalButtonLabel = submitButton?.textContent || '';
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'QRを作成しています…';
      }
      setStatus('QRを作成しています。完了まで画面を閉じずにお待ちください。');
      try {
        const formData = new FormData(form);
        const shouldAttachLocation = Boolean(attachLocationInput && !attachLocationInput.disabled && attachLocationInput.checked);
        if (shouldAttachLocation) {
          setStatus('位置情報を取得しています。許可しない場合も、数秒後にQR作成へ進みます。');
          const location = await captureLocation();
          if (location) {
            formData.set('latitude', String(location.latitude));
            formData.set('longitude', String(location.longitude));
            formData.set('location_accuracy_m', String(location.accuracy));
            formData.set('location_captured_at', location.capturedAt);
          }
        }

        setStatus('QRを作成しています。');
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: formData,
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.event_id) throw new Error(result.error || 'QRを作成できませんでした。');
        window.location.assign('/share-events/' + encodeURIComponent(result.event_id) + '/qr');
      } catch (error) {
        setStatus(error.message || 'QRを作成できませんでした。オンライン接続を確認して再度お試しください。');
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalButtonLabel;
        }
      }
    });
  }

  async function handleQrPage() {
    const expiresAt = Number(document.querySelector('meta[name="1g1a-qr-expires-at"]')?.content || 0);
    if (expiresAt > 0 && expiresAt * 1000 < Date.now()) {
      document.body.innerHTML = '<main style="font:16px/1.6 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 20px"><h1>QRの有効期限が切れています</h1><p>この共有イベントは表示できません。</p></main>';
    }
  }


  function handleContactEditor() {
    const editor = document.querySelector('[data-contact-editor]');
    if (!editor) return;
    const picker = editor.querySelector('[data-contact-picker]');
    const fields = editor.querySelector('[data-contact-fields]');
    const empty = editor.querySelector('[data-contact-empty]');
    const form = editor.closest('form');
    const setEmpty = () => { if (empty) empty.hidden = fields.querySelectorAll('[data-contact-field]').length > 0; };
    const setSelected = (code, selected) => {
      editor.querySelectorAll('[data-contact-service][data-code="' + CSS.escape(code) + '"]').forEach((button) => button.classList.toggle('selected', selected));
    };
    const addDeleteMarker = (code) => {
      if (!form || form.querySelector('input[type="hidden"][data-contact-deleted="' + CSS.escape(code) + '"]')) return;
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'contacts[' + code + ']';
      input.value = '';
      input.dataset.contactDeleted = code;
      form.appendChild(input);
    };
    const fieldExists = (code) => fields.querySelector('[data-contact-field][data-contact-code="' + CSS.escape(code) + '"]');
    const addField = (service) => {
      const code = service.dataset.code || '';
      if (!code || fieldExists(code)) return;
      form?.querySelector('input[type="hidden"][data-contact-deleted="' + CSS.escape(code) + '"]')?.remove();
      const wrap = document.createElement('div');
      wrap.className = 'contact-field';
      wrap.dataset.contactField = '';
      wrap.dataset.contactCode = code;
      const title = document.createElement('div');
      title.className = 'contact-field-title';
      const name = document.createElement('span');
      name.textContent = service.dataset.name || code;
      const remove = document.createElement('button');
      remove.className = 'button danger compact';
      remove.type = 'button';
      remove.dataset.contactRemove = '';
      remove.setAttribute('aria-label', '削除');
      remove.textContent = '削除';
      title.append(name, remove);
      const input = document.createElement('input');
      input.name = 'contacts[' + code + ']';
      input.placeholder = service.dataset.placeholder || '';
      wrap.append(title, input);
      fields.appendChild(wrap);
      setSelected(code, true);
      setEmpty();
    };
    editor.querySelector('[data-contact-open]')?.addEventListener('click', () => { picker.hidden = false; });
    editor.querySelector('[data-contact-close]')?.addEventListener('click', () => { picker.hidden = true; });
    editor.querySelector('[data-contact-add-selected]')?.addEventListener('click', () => {
      const selected = Array.from(editor.querySelectorAll('[data-contact-service].pending'));
      selected.forEach((service) => {
        addField(service);
        service.classList.remove('pending');
      });
      picker.hidden = true;
      const last = fields.querySelector('[data-contact-field]:last-child input');
      last?.focus();
    });
    editor.addEventListener('click', (event) => {
      const service = event.target.closest('[data-contact-service]');
      if (service) {
        const code = service.dataset.code || '';
        if (fieldExists(code) || service.classList.contains('selected')) {
          fieldExists(code)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
          picker.hidden = true;
        } else {
          service.classList.toggle('pending');
        }
      }
      const remove = event.target.closest('[data-contact-remove]');
      if (remove) {
        const field = remove.closest('[data-contact-field]');
        const code = field?.dataset.contactCode || '';
        if (code) addDeleteMarker(code);
        field?.remove();
        setSelected(code, false);
        setEmpty();
      }
    });
    setEmpty();
  }

  function handleGuestContactActions() {
    const buttons = document.querySelectorAll('[data-contact-action]');
    if (!buttons.length) return;
    const menu = document.createElement('div');
    menu.className = 'contact-action-overlay';
    menu.hidden = true;
    menu.innerHTML = '<div class="contact-action-panel"><h3></h3><button class="button secondary" type="button" data-copy>コピー</button><a class="button primary" target="_blank" rel="noreferrer" data-open>リンク先へ移動</a><button class="button secondary" type="button" data-close>閉じる</button></div>';
    document.body.appendChild(menu);
    const title = menu.querySelector('h3');
    const copy = menu.querySelector('[data-copy]');
    const open = menu.querySelector('[data-open]');
    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        title.textContent = button.dataset.service || 'リンク';
        copy.dataset.value = button.dataset.copy || '';
        open.href = button.dataset.url || '#';
        menu.hidden = false;
      });
    });
    copy.addEventListener('click', async () => {
      const value = copy.dataset.value || '';
      try {
        await navigator.clipboard.writeText(value);
        copy.textContent = 'コピーしました';
        setTimeout(() => { copy.textContent = 'コピー'; }, 1200);
      } catch (err) {
        window.prompt('コピーしてください', value);
      }
    });
    menu.querySelector('[data-close]').addEventListener('click', () => { menu.hidden = true; });
    menu.addEventListener('click', (event) => { if (event.target === menu) menu.hidden = true; });
  }

  document.addEventListener('DOMContentLoaded', () => {
    handleDiagnosticUpload();
    handleBrandMenus();
    handleDashboard();
    handleQrPage();
    handleContactEditor();
    handleGuestSkinPicker();
    handleGuestContactActions();
    handleGuestPhotoGallery();
    handlePwaInstall();
  });

  function handleGuestPhotoGallery() {
    const dialog = document.querySelector('[data-guest-photo-dialog]');
    const image = dialog?.querySelector('[data-guest-photo-dialog-image]');
    const caption = dialog?.querySelector('[data-guest-photo-dialog-caption]');
    if (!dialog || !image || typeof dialog.showModal !== 'function') return;

    document.querySelectorAll('[data-guest-photo-open]').forEach((button) => {
      button.addEventListener('click', () => {
        image.src = button.dataset.photoSrc || '';
        image.alt = button.dataset.photoAlt || '共有写真';
        if (caption) caption.textContent = image.alt;
        dialog.showModal();
      });
    });

    dialog.querySelector('[data-guest-photo-close]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => {
      image.removeAttribute('src');
      image.alt = '';
    });
  }

  function handleGuestSkinPicker() {
    const picker = document.querySelector('.guest-skin-picker');
    if (!picker) return;

    const browser = picker.querySelector('[data-skin-browser]');
    const toggle = picker.querySelector('[data-skin-toggle]');
    const search = picker.querySelector('[data-skin-search]');
    const count = picker.querySelector('[data-skin-count]');
    const empty = picker.querySelector('[data-skin-empty]');
    const options = Array.from(picker.querySelectorAll('[data-skin-option]'));
    const currentName = picker.querySelector('[data-skin-current-name]');
    const currentDescription = picker.querySelector('[data-skin-current-description]');
    const currentPreview = picker.querySelector('[data-skin-current-preview]');
    if (!browser || !toggle || options.length === 0) return;

    browser.hidden = true;
    toggle.hidden = false;

    function applyFilter() {
      const query = (search?.value || '').trim().toLocaleLowerCase();
      let visible = 0;
      options.forEach((option) => {
        const haystack = (option.dataset.searchText || '').toLocaleLowerCase();
        const matches = query === '' || haystack.includes(query);
        option.hidden = !matches;
        if (matches) visible += 1;
      });
      if (count) count.textContent = `${visible} / ${options.length} 種類`;
      if (empty) empty.hidden = visible > 0;
    }

    function updateCurrent(option) {
      if (currentName) currentName.textContent = option.dataset.skinName || '';
      if (currentDescription) currentDescription.textContent = option.dataset.skinDescription || '';
      if (currentPreview) {
        const previewUrl = option.dataset.skinPreviewUrl || '';
        currentPreview.hidden = previewUrl === '';
        if (previewUrl !== '') currentPreview.src = previewUrl;
        else currentPreview.removeAttribute('src');
      }
      options.forEach((item) => item.classList.toggle('is-selected', item === option));
    }

    toggle.addEventListener('click', () => {
      const opening = browser.hidden;
      browser.hidden = !opening;
      toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      toggle.textContent = opening ? '閉じる' : 'スキンを変更';
      if (opening) search?.focus();
    });
    search?.addEventListener('input', applyFilter);
    picker.addEventListener('change', (event) => {
      const input = event.target.closest('input[name="guest_skin_id"]');
      if (!input?.checked) return;
      const option = input.closest('[data-skin-option]');
      if (!option) return;
      updateCurrent(option);
      browser.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.textContent = 'スキンを変更';
      toggle.focus();
    });

    applyFilter();
    const selected = options.find((option) => option.querySelector('input')?.checked);
    if (selected) updateCurrent(selected);
  }

  function handleBrandMenus() {
    const menus = Array.from(document.querySelectorAll('.brand-menu'));
    if (menus.length === 0) return;

    menus.forEach((menu) => {
      menu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => { menu.open = false; });
      });
    });

    document.addEventListener('click', (event) => {
      menus.forEach((menu) => {
        if (menu.open && !menu.contains(event.target)) menu.open = false;
      });
    });

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      const openMenu = menus.find((menu) => menu.open);
      if (!openMenu) return;
      openMenu.open = false;
      openMenu.querySelector('summary')?.focus();
    });
  }

  let deferredInstallPrompt = null;

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
  });

  function handlePwaInstall() {
    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    if ('serviceWorker' in navigator) {
      const appVersion = document.querySelector('meta[name="1g1a-app-version"]')?.content || '0.0.1';
      const hadController = Boolean(navigator.serviceWorker.controller);
      let reloading = false;

      if (standalone) {
        navigator.serviceWorker.addEventListener('controllerchange', () => {
          if (hadController && !reloading) {
            reloading = true;
            window.location.reload();
          }
        }, { once: true });
      }

      const serviceWorkerUrl = `/sw.js?v=${encodeURIComponent(appVersion)}`;
      navigator.serviceWorker.register(serviceWorkerUrl, { updateViaCache: 'none' }).then(async (registration) => {
        if (!standalone) return;

        fetch('/pwa/session', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-1G1A-PWA': '1' }
        }).then(async (response) => {
          let details = {};
          try { details = await response.json(); } catch (_) { /* status is still useful */ }
          recordDiagnostic('pwa_session', `status=${response.status}; ok=${response.ok}; authenticated=${details.authenticated === true}`);
        }).catch((error) => {
          recordDiagnostic('pwa_session_error', `${error?.name || 'Error'}: ${error?.message || 'request failed'}`);
        });

        try {
          const response = await fetch('/version.json', { cache: 'no-store', credentials: 'same-origin' });
          if (response.ok) {
            const latest = await response.json();
            if (typeof latest.version === 'string' && latest.version !== appVersion) {
              window.location.reload();
              return;
            }
            localStorage.setItem('1g1a-last-checked-version', latest.version);
          }
        } catch (_) {
          // Version checks require a connection; cached QR display remains available offline.
        }

        try {
          await registration.update();
        } catch (_) {
          // The next online PWA launch retries the update check.
        }
      }).catch((err) => {
        console.warn('service worker registration failed', err);
      });
    }

    const installLink = document.querySelector('[data-pwa-install]');
    if (installLink && standalone) installLink.hidden = true;
    window.addEventListener('appinstalled', () => {
      if (installLink) installLink.hidden = true;
    });
    installLink?.addEventListener('click', async (event) => {
      if (!deferredInstallPrompt) return;
      event.preventDefault();
      const installPrompt = deferredInstallPrompt;
      deferredInstallPrompt = null;
      installPrompt.prompt();
      const choice = await installPrompt.userChoice.catch(() => null);
      if (choice?.outcome === 'accepted' && installLink) installLink.hidden = true;
    });
  }
})();
