(function () {
  const DB_NAME = '1g1a-offline';
  const DB_VERSION = 1;
  const RESERVED_STORE = 'reservedTokens';
  const PENDING_STORE = 'pendingShareEvents';

  function openDb() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(RESERVED_STORE)) {
          db.createObjectStore(RESERVED_STORE, { keyPath: 'public_token' });
        }
        if (!db.objectStoreNames.contains(PENDING_STORE)) {
          db.createObjectStore(PENDING_STORE, { keyPath: 'public_token' });
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  async function withStore(name, mode, callback) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(name, mode);
      const store = tx.objectStore(name);
      const result = callback(store);
      tx.oncomplete = () => resolve(result);
      tx.onerror = () => reject(tx.error);
    });
  }

  function randomToken(prefix = 'draft-') {
    if (window.crypto && crypto.randomUUID) {
      return prefix + crypto.randomUUID().replace(/-/g, '');
    }
    return prefix + Math.random().toString(16).slice(2) + Date.now().toString(16);
  }

  async function reserveTokens(profileId, csrf) {
    if (!profileId || !navigator.onLine) {
      return;
    }

    try {
      const res = await fetch('/share-tokens/reserve', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'fetch',
        },
        body: new URLSearchParams({
          _csrf: csrf,
          profile_id: String(profileId),
          count: '3',
        }),
      });

      if (!res.ok) {
        return;
      }

      const data = await res.json();
      const tokens = Array.isArray(data.reserved_tokens) ? data.reserved_tokens : [];
      for (const token of tokens) {
        await withStore(RESERVED_STORE, 'readwrite', (store) => {
          store.put({
            public_token: token,
            profile_id: Number(profileId),
            reserved_at: Date.now(),
          });
        });
      }
    } catch (err) {
      console.warn('reserve failed', err);
    }
  }

  async function pickToken(profileId) {
    const db = await openDb();
    const tokens = await new Promise((resolve, reject) => {
      const tx = db.transaction(RESERVED_STORE, 'readonly');
      const store = tx.objectStore(RESERVED_STORE);
      const request = store.getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
    });

    const match = (tokens || []).find((item) => Number(item.profile_id) === Number(profileId));
    if (!match) {
      return randomToken();
    }

    await withStore(RESERVED_STORE, 'readwrite', (store) => store.delete(match.public_token));
    return match.public_token;
  }

  async function storeDraft(draft) {
    await withStore(PENDING_STORE, 'readwrite', (store) => store.put(draft));
  }

  async function deleteDraft(token) {
    await withStore(PENDING_STORE, 'readwrite', (store) => store.delete(token));
  }

  async function loadDrafts() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(PENDING_STORE, 'readonly');
      const store = tx.objectStore(PENDING_STORE);
      const request = store.getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
    });
  }

  async function syncDraft(draft) {
    const form = new FormData();
    form.append('_csrf', draft.csrf || '');
    form.append('profile_id', String(draft.profile_id));
    form.append('body', draft.body || '');
    form.append('expires_in', draft.expires_in || '24h');
    form.append('public_token', draft.public_token);
    form.append('status', 'ready');
    form.append('attach_location', draft.attach_location === false ? '0' : '1');
    if (draft.reserve_token) {
      form.append('reserve_token', draft.reserve_token);
    }
    if (draft.latitude !== null && draft.latitude !== undefined) {
      form.append('latitude', String(draft.latitude));
    }
    if (draft.longitude !== null && draft.longitude !== undefined) {
      form.append('longitude', String(draft.longitude));
    }
    if (draft.accuracy !== null && draft.accuracy !== undefined) {
      form.append('location_accuracy_m', String(draft.accuracy));
    }
    if (draft.location_captured_at) {
      form.append('location_captured_at', draft.location_captured_at);
    }
    for (const photo of draft.photos || []) {
      form.append('photos[]', photo.blob, photo.name || 'photo.jpg');
    }

    const res = await fetch('/share-events/create', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'fetch',
      },
      body: form,
    });

    if (!res.ok) {
      throw new Error('sync failed');
    }

    const data = await res.json();
    await deleteDraft(draft.public_token);
    return data;
  }

  async function syncPending() {
    const drafts = await loadDrafts();
    for (const draft of drafts) {
      if (!draft.publish) {
        continue;
      }
      if (draft.synced_at) {
        continue;
      }
      try {
        await syncDraft(draft);
      } catch (err) {
        console.warn('sync pending draft failed', err);
      }
    }
  }

  async function handleDashboard() {
    const form = document.getElementById('share-launcher');
    if (!form) {
      return;
    }

    const profileSelect = document.getElementById('profile-select');
    const bodyInput = document.getElementById('share-body');
    const photosInput = document.getElementById('share-photos');
    const expiresInput = document.getElementById('share-expires');
    const attachLocationInput = document.getElementById('attach-location');
    const locationEnabledHint = document.getElementById('location-enabled-hint');
    const locationDisabledHint = document.getElementById('location-disabled-hint');
    const status = document.getElementById('draft-status');
    const csrf = form.querySelector('input[name="_csrf"]')?.value || '';
    function setLocationAvailability(granted) {
      if (!attachLocationInput) return;
      const wasDisabled = attachLocationInput.disabled;
      attachLocationInput.disabled = !granted;
      if (!granted) {
        attachLocationInput.checked = false;
      } else if (wasDisabled) {
        attachLocationInput.checked = true;
      }
      if (locationEnabledHint) locationEnabledHint.hidden = !granted;
      if (locationDisabledHint) locationDisabledHint.hidden = granted;
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
      } catch (err) {
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
            captured_at: new Date().toISOString(),
          }),
          () => resolve(null),
          { enableHighAccuracy: true, maximumAge: 60000, timeout: 8000 }
        );
      });
    }

    async function topUp() {
      const profileId = profileSelect ? profileSelect.value : '';
      if (profileId) {
        await reserveTokens(profileId, csrf);
      }
    }

    await topUp();
    await initializeLocationOption();

    if (profileSelect) {
      profileSelect.addEventListener('change', topUp);
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submitter = event.submitter || document.activeElement;
      const isPublish = Boolean(submitter && submitter.dataset && submitter.dataset.publish === '1');
      const profileId = profileSelect ? profileSelect.value : '';
      if (!profileId) {
        return;
      }

      const shouldAttachLocation = Boolean(attachLocationInput && !attachLocationInput.disabled && attachLocationInput.checked);
      let location = null;
      if (shouldAttachLocation) {
        if (status) status.textContent = '位置情報を取得しています。';
        location = await captureLocation();
      }
      const publicToken = await pickToken(profileId);
      const files = Array.from(photosInput?.files || []).map((file) => ({
        name: file.name,
        blob: file,
      }));
      const reserveToken = publicToken.startsWith('draft-') ? '' : publicToken;
      const draft = {
        public_token: publicToken,
        profile_id: Number(profileId),
        body: bodyInput ? bodyInput.value : '',
        expires_in: expiresInput ? expiresInput.value : '24h',
        attach_location: shouldAttachLocation,
        latitude: location?.latitude ?? null,
        longitude: location?.longitude ?? null,
        accuracy: location?.accuracy ?? null,
        location_captured_at: location?.captured_at ?? null,
        reserve_token: reserveToken,
        csrf,
        photos: files,
        publish: isPublish,
        synced_at: null,
      };

      await storeDraft(draft);

      if (!isPublish) {
        if (status) {
          status.textContent = '一時保存しました。QR作成ボタンでQRを作成できます。';
        }
        return;
      }

      if (navigator.onLine) {
        try {
          const result = await syncDraft(draft);
          const eventId = result && result.event_id ? String(result.event_id) : '';
          const nextUrl = eventId
            ? '/share-events/' + encodeURIComponent(eventId) + '/qr'
            : (result.public_url || ('/s/' + publicToken));
          window.location.href = nextUrl;
          return;
        } catch (err) {
          console.warn('online create failed', err);
        }
      }

      if (status) {
        status.textContent = '通信しにくいため一時保存しました。復帰後に同期します。';
      }
      window.location.href = '/share-events/qr?profile=' + encodeURIComponent(profileId) + '&token=' + encodeURIComponent(publicToken) + '&draft=1';
    });
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
    handleContactEditor();
    handleGuestContactActions();
    syncPending();
    handlePwaInstall();
  });

  window.addEventListener('online', () => {
    syncPending();
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
      navigator.serviceWorker.register('/sw.js').catch((err) => {
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
