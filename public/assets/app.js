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
    const status = document.getElementById('draft-status');
    const csrf = form.querySelector('input[name="_csrf"]')?.value || '';

    async function topUp() {
      const profileId = profileSelect ? profileSelect.value : '';
      if (profileId) {
        await reserveTokens(profileId, csrf);
      }
    }

    await topUp();

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
            ? '/profiles/' + profileId + '/qr?event=' + encodeURIComponent(eventId)
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
      window.location.href = '/profiles/' + profileId + '/qr?token=' + encodeURIComponent(publicToken) + '&draft=1';
    });
  }

  async function handleQrPage() {
    if (!window.__QR_LOCATION_CAPTURE) {
      return;
    }

    const eventId = window.__QR_EVENT_ID;
    if (!eventId || !navigator.geolocation) {
      return;
    }

    navigator.geolocation.getCurrentPosition(async (position) => {
      try {
        const body = new URLSearchParams({
          _csrf: document.querySelector('input[name="_csrf"]')?.value || '',
          public_token: window.__QR_EVENT_TOKEN || '',
          latitude: String(position.coords.latitude),
          longitude: String(position.coords.longitude),
          accuracy: String(position.coords.accuracy),
        });
        await fetch('/share-events/' + eventId + '/location', {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body,
        });
      } catch (err) {
        console.warn('location save failed', err);
      }
    }, () => {}, { enableHighAccuracy: true, maximumAge: 60000, timeout: 8000 });
  }

  document.addEventListener('DOMContentLoaded', () => {
    handleDashboard();
    handleQrPage();
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
