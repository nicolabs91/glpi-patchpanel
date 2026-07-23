(function () {
  function hideManagedShadowPortLinks(root = document) {
    const panelPortLinks = [];
    if (root instanceof Element && root.matches('a[href*="/plugins/patchpanel/front/panelport.form.php"]')) {
      panelPortLinks.push(root);
    }
    panelPortLinks.push(
      ...root.querySelectorAll('a[href*="/plugins/patchpanel/front/panelport.form.php"]')
    );

    panelPortLinks.forEach((panelPortLink) => {
      const container = panelPortLink.closest('td, dd, li') || panelPortLink.parentElement;

      const shadowPortLink = Array.from(container?.querySelectorAll('a[href*="/front/networkport.form.php"]') || [])
        .find((link) => panelPortLink.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING);
      if (!shadowPortLink) {
        return;
      }

      // GLPI renders an owned network port as "owner > network port". The
      // owner is PatchPanel's real port; the managed shadow port after it is
      // only an implementation detail and must not look like a second port.
      const range = document.createRange();
      range.setStartAfter(panelPortLink);
      range.setEndAfter(shadowPortLink);
      range.deleteContents();
    });
  }

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

  function getSelectedNumber(field) {
    return Number.parseInt(field?.value || '0', 10) || 0;
  }

  async function keepPatchPanelInsideRack(form) {
    const itemType = form.querySelector('select[name="itemtype"]');
    const item = form.querySelector('select[name="items_id"]');
    const rack = form.querySelector('select[name="racks_id"]');
    const position = form.querySelector('select[name="position"]');
    if (itemType?.value !== 'PluginPatchpanelPanel' || !item || !rack || !position) {
      return;
    }

    const panelId = getSelectedNumber(item);
    const rackId = getSelectedNumber(rack);
    const currentPosition = getSelectedNumber(position);
    if (panelId <= 0 || rackId <= 0 || currentPosition <= 0) {
      return;
    }

    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 3000);
    let response;
    try {
      response = await fetch(
        `${CFG_GLPI.root_doc}/plugins/patchpanel/front/panellayout.php?id=${panelId}&rack_id=${rackId}`,
        {
          credentials: 'same-origin',
          signal: controller.signal,
        }
      );
    } catch (_error) {
      // Rack placement is a convenience check. A failed or slow helper
      // request must never prevent GLPI from submitting the rack item.
      return;
    } finally {
      window.clearTimeout(timeout);
    }
    if (!response.ok) {
      return;
    }

    const layout = await response.json();
    const requiredUnits = Math.max(1, Number.parseInt(layout.required_units || '1', 10));
    const rackUnits = Math.max(0, Number.parseInt(layout.rack_units || '0', 10));
    if (rackUnits <= 0) {
      return;
    }
    const highestStart = rackUnits - requiredUnits + 1;
    if (currentPosition > highestStart) {
      if (!Array.from(position.options).some(option => option.value === String(highestStart))) {
        position.add(new Option(String(highestStart), String(highestStart), true, true));
      }
      setSelectValue(form, 'position', String(highestStart));
    }
  }

  function bindRackPlacementForms(root = document) {
    const forms = Array.from(root.querySelectorAll?.('form') || []);
    if (root instanceof HTMLFormElement) {
      forms.push(root);
    }

    forms.forEach((form) => {
      if (!form.querySelector('select[name="itemtype"]')
        || !form.querySelector('select[name="position"]')) {
        return;
      }

      const item = form.querySelector('select[name="items_id"]');
      if (item && item.dataset.patchpanelRackBoundsBound !== '1') {
        item.dataset.patchpanelRackBoundsBound = '1';
        item.addEventListener('change', () => keepPatchPanelInsideRack(form));
        if (window.jQuery) {
          window.jQuery(item).on('change.patchpanelRackBounds', () => keepPatchPanelInsideRack(form));
        }
      }

      // Never intercept GLPI's rack form submission here. The layout lookup
      // is only a progressive enhancement after item selection; GLPI remains
      // responsible for submitting and validating the rack relation.
    });
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
    hideManagedShadowPortLinks();
    bindRackPlacementForms();
    let rescanScheduled = false;
    new MutationObserver(() => {
      if (rescanScheduled) {
        return;
      }
      rescanScheduled = true;
      window.requestAnimationFrame(() => {
        rescanScheduled = false;
        // GLPI builds AJAX tab content in multiple mutations. Rescan the
        // completed document so the owner and its managed network port are
        // handled even when they arrived in separate DOM updates.
        hideManagedShadowPortLinks(document);
        bindRackPlacementForms(document);
      });
    }).observe(document.body, { childList: true, subtree: true });

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
