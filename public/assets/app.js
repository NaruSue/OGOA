(function () {
  async function cacheVisibleQrPages() {
    if (!navigator.onLine || !('serviceWorker' in navigator)) return;
    try {
      await navigator.serviceWorker.ready;
      const links = Array.from(document.querySelectorAll('.event-list a.event-row'));
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
    const attachLocationInput = document.getElementById('attach-location');
    const locationEnabledHint = document.getElementById('location-enabled-hint');
    const locationDisabledHint = document.getElementById('location-disabled-hint');

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
          { enableHighAccuracy: true, maximumAge: 60000, timeout: 8000 }
        );
      });
    }

    document.querySelectorAll('[data-profile-tab]').forEach((tab) => {
      tab.addEventListener('click', () => {
        if (!profileSelect) return;
        profileSelect.value = tab.dataset.profileTab || '';
        profileSelect.dispatchEvent(new Event('change', { bubbles: true }));
        document.querySelectorAll('[data-profile-tab]').forEach((item) => {
          const active = item === tab;
          item.classList.toggle('is-active', active);
          item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
      });
    });

    initializeLocationOption();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!navigator.onLine) {
        if (status) status.textContent = 'QRの作成にはオンライン接続が必要です。通信を確認してからもう一度お試しください。';
        return;
      }

      const submitButton = event.submitter;
      if (submitButton) submitButton.disabled = true;
      try {
        const formData = new FormData(form);
        const shouldAttachLocation = Boolean(attachLocationInput && !attachLocationInput.disabled && attachLocationInput.checked);
        if (shouldAttachLocation) {
          if (status) status.textContent = '位置情報を取得しています。';
          const location = await captureLocation();
          if (location) {
            formData.set('latitude', String(location.latitude));
            formData.set('longitude', String(location.longitude));
            formData.set('location_accuracy_m', String(location.accuracy));
            formData.set('location_captured_at', location.capturedAt);
          }
        }

        if (status) status.textContent = 'QRを作成しています。';
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: formData,
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.event_id) throw new Error(result.error || 'QRを作成できませんでした。');
        window.location.href = '/share-events/' + encodeURIComponent(result.event_id) + '/qr';
      } catch (error) {
        if (status) status.textContent = error.message || 'QRを作成できませんでした。オンライン接続を確認して再度お試しください。';
        if (submitButton) submitButton.disabled = false;
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
      remove.className = 'icon-button danger';
      remove.type = 'button';
      remove.dataset.contactRemove = '';
      remove.setAttribute('aria-label', '削除');
      remove.textContent = '🗑';
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
    handleDashboard();
    handleQrPage();
    handleContactEditor();
    handleGuestContactActions();
    handlePwaInstall();
  });

  let deferredInstallPrompt = null;

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    document.querySelectorAll('[data-pwa-install]').forEach((button) => {
      button.hidden = false;
    });
  });

  function handlePwaInstall() {
    if ('serviceWorker' in navigator) {
      const appVersion = document.querySelector('meta[name="1g1a-app-version"]')?.content || '0.0.1';
      const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
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
        }).catch(() => undefined);

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

    document.querySelectorAll('[data-pwa-install]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!deferredInstallPrompt) {
          return;
        }
        deferredInstallPrompt.prompt();
        await deferredInstallPrompt.userChoice.catch(() => undefined);
        deferredInstallPrompt = null;
        button.hidden = true;
      });
    });
  }
})();
