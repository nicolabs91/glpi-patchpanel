(function () {
  function getSocketId() {
    if (!location.pathname.endsWith('/front/socket.form.php')) {
      return 0;
    }
    const id = new URLSearchParams(location.search).get('id');
    return Number.parseInt(id || '0', 10) || 0;
  }

  function setSelectValue(form, name, value) {
    const field = form.querySelector(`[name="${name}"]`);
    if (!field) {
      return;
    }
    field.value = value;
    if (window.jQuery) {
      window.jQuery(field).val(value).trigger('change');
    } else {
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  async function cleanupSocketDevice(socketId, form) {
    const token = form.querySelector('input[name="_glpi_csrf_token"]')?.value || '';
    const body = new URLSearchParams();
    body.set('id', String(socketId));
    body.set('_glpi_csrf_token', token);

    const response = await fetch(`${CFG_GLPI.root_doc}/plugins/patchpanel/front/socketdevice.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body,
      credentials: 'same-origin',
    });
    if (!response.ok) {
      throw new Error(`PatchPanel cleanup failed with HTTP ${response.status}`);
    }
  }

  async function boot() {
    const socketId = getSocketId();
    if (socketId <= 0 || !window.CFG_GLPI?.root_doc) {
      return;
    }

    const statusResponse = await fetch(
      `${CFG_GLPI.root_doc}/plugins/patchpanel/front/socketdevice.php?id=${socketId}`,
      { credentials: 'same-origin' }
    );
    if (!statusResponse.ok) {
      return;
    }
    const status = await statusResponse.json();
    if (!status.stale) {
      return;
    }

    const form = document.querySelector('button[name="update"]')?.closest('form');
    if (!form) {
      return;
    }

    form.addEventListener('submit', async (event) => {
      const submitter = event.submitter;
      if (!submitter || submitter.name !== 'update') {
        return;
      }
      event.preventDefault();

      await cleanupSocketDevice(socketId, form);
      setSelectValue(form, 'networkports_id', '0');
      setSelectValue(form, 'items_id', '0');
      setSelectValue(form, 'itemtype', '');

      if (!form.querySelector('input[name="update"]')) {
        const update = document.createElement('input');
        update.type = 'hidden';
        update.name = 'update';
        update.value = '1';
        form.appendChild(update);
      }
      form.submit();
    }, { once: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
