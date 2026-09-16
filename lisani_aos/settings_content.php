<?php
// This file is included by index.php, REPLACING the static "Settings" placeholder
// <div class="menu-section" data-section="settings" ...> entirely - same pattern as
// transaction_content.php. It wraps its own <div class="menu-section" data-section="settings">.
// Do not wrap it again from index.php, and keep the modals as siblings of that wrapper
// (outside it) so they still render while the section is display:none.
?>
<div class="menu-section" data-section="settings" style="display:none;">
<div class="settings-stack">

  <!-- Company Documents -->
  <div class="card settings-collapsible" id="cardDocuments">
    <button type="button" class="settings-collapsible-header" id="cardDocumentsHeader">
      <div>
        <div class="empty-title" style="text-align:left;">Company Documents</div>
        <div class="header-sub">Upload, download, and manage legal/company documents</div>
      </div>
      <i class="ti ti-chevron-down settings-collapsible-chevron"></i>
    </button>

    <div class="settings-collapsible-body" id="cardDocumentsBody">
      <div class="settings-collapsible-body-inner">

        <form id="docUploadForm" class="settings-form">
          <div class="settings-form-grid">
            <div class="form-group">
              <label class="label">Document Name</label>
              <input type="text" class="input" id="docNameInput" placeholder="e.g. Business License" required>
            </div>
            <div class="form-group">
              <label class="label">Document Date</label>
              <input type="date" class="input" id="docDateInput" required>
            </div>
            <div class="form-group">
              <label class="label">File</label>
              <input type="file" class="input" id="docFileInput" required>
            </div>
          </div>

          <div class="form-group">
            <label class="label">Final File Name (edit before saving)</label>
            <input type="text" class="input" id="docFinalNameInput" placeholder="Will be generated automatically">
          </div>

          <div id="docUploadMessage" class="settings-message"></div>

          <div class="settings-form-actions">
            <button type="button" class="btn btn-secondary" id="docCancelEditBtn" style="display:none;">Cancel Edit</button>
            <button type="submit" class="btn btn-primary" id="docSubmitBtn">Upload Document</button>
          </div>
        </form>

        <div class="settings-list-toolbar">
          <div class="header-sub" id="docSelectedCount">0 selected</div>
          <button type="button" class="btn btn-secondary" id="docShareBtn" disabled>
            <i class="ti ti-brand-whatsapp"></i> Share via WhatsApp
          </button>
        </div>

        <div class="doc-accordion-toolbar-select">
          <label class="doc-select-all-label">
            <input type="checkbox" id="docSelectAll"> Select All
          </label>
        </div>

        <div class="doc-accordion" id="docAccordionList">
          <div class="empty-sub">Loading...</div>
        </div>

      </div>
    </div>
  </div>

  <!-- Company Bank Accounts -->
  <div class="card settings-collapsible" id="cardBankAccounts">
    <button type="button" class="settings-collapsible-header" id="cardBankAccountsHeader">
      <div>
        <div class="empty-title" style="text-align:left;">Company Bank Accounts</div>
        <div class="header-sub">Manage company bank accounts, grouped by currency</div>
      </div>
      <i class="ti ti-chevron-down settings-collapsible-chevron"></i>
    </button>

    <div class="settings-collapsible-body" id="cardBankAccountsBody">
      <div class="settings-collapsible-body-inner">

        <form id="bankForm" class="settings-form">
          <input type="hidden" id="bankEditingId" value="">
          <div class="settings-form-grid">
            <div class="form-group">
              <label class="label">Account Number</label>
              <input type="text" class="input" id="bankAccountNumber" required>
            </div>
            <div class="form-group">
              <label class="label">Account Name</label>
              <input type="text" class="input" id="bankAccountName" required>
            </div>
            <div class="form-group">
              <label class="label">Currency</label>
              <div style="display:flex; gap:8px;">
                <select class="select" id="bankCurrencySelect" style="flex:1;"></select>
                <button type="button" class="btn btn-secondary" id="bankAddCurrencyBtn" title="Add currency">
                  <i class="ti ti-plus"></i>
                </button>
              </div>
            </div>
          </div>

          <div class="settings-form-grid" id="bankIntlFields" style="display:none;">
            <div class="form-group">
              <label class="label">SWIFT Code</label>
              <input type="text" class="input" id="bankSwiftCode">
            </div>
            <div class="form-group">
              <label class="label">Bank Address</label>
              <input type="text" class="input" id="bankAddress">
            </div>
          </div>

          <div id="bankFormMessage" class="settings-message"></div>

          <div class="settings-form-actions">
            <button type="button" class="btn btn-secondary" id="bankCancelEditBtn" style="display:none;">Cancel Edit</button>
            <button type="submit" class="btn btn-primary" id="bankSubmitBtn">Save Account</button>
          </div>
        </form>

        <div class="settings-list-toolbar">
          <div class="header-sub" id="bankSelectedCount">0 selected</div>
          <button type="button" class="btn btn-secondary" id="bankShareBtn" disabled>
            <i class="ti ti-brand-whatsapp"></i> Share via WhatsApp
          </button>
        </div>

        <div id="bankGroupsWrapper">
          <div class="empty-sub">Loading...</div>
        </div>

      </div>
    </div>
  </div>

</div>
</div>

<!-- Generic warning + password confirmation modal, reused for Edit / Delete / Share -->
<div class="modal-overlay" id="settingsConfirmOverlay" style="display:none;">
  <div class="modal">
    <div class="panel-header">
      <div class="panel-title" id="settingsConfirmTitle">Confirm Action</div>
    </div>
    <p class="settings-confirm-message" id="settingsConfirmMessage"></p>
    <div class="form-group">
      <label class="label">Password</label>
      <input type="password" class="input" id="settingsConfirmPassword" placeholder="Enter your password">
    </div>
    <div id="settingsConfirmError" class="settings-message settings-message-error" style="display:none;"></div>
    <div class="settings-form-actions">
      <button type="button" class="btn btn-secondary" id="settingsConfirmCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="settingsConfirmSubmit">Confirm</button>
    </div>
  </div>
</div>

<!-- Add currency modal -->
<div class="modal-overlay" id="settingsAddCurrencyOverlay" style="display:none;">
  <div class="modal">
    <div class="panel-header">
      <div class="panel-title">Add Currency</div>
    </div>
    <div class="form-group">
      <label class="label">Currency Code</label>
      <input type="text" class="input" id="settingsNewCurrencyInput" placeholder="e.g. SGD" maxlength="6" style="text-transform:uppercase;">
    </div>
    <div id="settingsAddCurrencyError" class="settings-message settings-message-error" style="display:none;"></div>
    <div class="settings-form-actions">
      <button type="button" class="btn btn-secondary" id="settingsAddCurrencyCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="settingsAddCurrencySubmit">Add</button>
    </div>
  </div>
</div>

<style>
  /* Explicit vertical stack for the two Settings cards - don't rely on
     .content's own layout (elsewhere in the app .card children get an
     explicit width:100% to avoid sitting side-by-side). */
  .settings-stack { display: flex; flex-direction: column; width: 100%; }

  /* Collapsible cards: default collapsed, only one open at a time. */
  /* .content lays out .card children in a row/grid elsewhere in the app
     (see the width:100% cards in index.php's Dashboard/Report placeholders) -
     force these two full width so they stack vertically instead of sitting
     side-by-side. */
  .settings-collapsible { padding: 0; overflow: hidden; margin-bottom: 20px; width: 100%; }
  .settings-collapsible-header {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 20px;
    text-align: left;
    font: inherit;
    color: inherit;
  }
  .settings-collapsible-chevron { transition: transform 0.2s ease; font-size: 20px; }
  .settings-collapsible.open .settings-collapsible-chevron { transform: rotate(180deg); }
  .settings-collapsible-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.25s ease;
  }
  .settings-collapsible-body-inner { padding: 0 20px 20px 20px; }

  .settings-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
  }
  .settings-form { margin-bottom: 20px; }
  .settings-form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; }
  .settings-message { margin-top: 8px; font-size: 13px; }
  .settings-message-error { color: var(--danger, #e5484d); }
  .settings-message-success { color: var(--success, #3dd68c); }
  .settings-list-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 16px 0 10px 0;
  }
  .settings-confirm-message { margin: 4px 0 14px 0; font-size: 14px; line-height: 1.5; }
  .settings-bank-group { margin-bottom: 18px; }
  .settings-bank-group-title { font-weight: 600; margin-bottom: 8px; }

  /* Uploaded documents: accordion card list (default collapsed, one open
     at a time). Header (checkbox + name) stays visible in both collapsed
     and expanded state - checkbox always sits to the left of the name. */
  .doc-accordion-toolbar-select { margin-bottom: 10px; }
  .doc-select-all-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted, #9a9a9a);
    cursor: pointer;
  }
  .doc-accordion { display: flex; flex-direction: column; gap: 10px; }
  .doc-accordion-item {
    border-radius: var(--radius-md, 10px);
    background: var(--bg-surface-alt, rgba(255, 255, 255, 0.03));
    overflow: hidden;
  }
  .doc-accordion-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    cursor: pointer;
  }
  .doc-accordion-name { flex: 1; font-weight: 600; }
  .doc-accordion-chevron { font-size: 18px; transition: transform 0.2s ease; flex-shrink: 0; }
  .doc-accordion-item.open .doc-accordion-chevron { transform: rotate(180deg); }
  .doc-accordion-body { max-height: 0; overflow: hidden; transition: max-height 0.2s ease; }
  .doc-accordion-body-inner { padding: 0 16px 16px 16px; }
  .doc-meta-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 14px;
  }
  .doc-meta-row .doc-meta-label { color: var(--text-muted, #9a9a9a); }
  /* Action buttons side-by-side (not stacked), with visible text labels. */
  .doc-accordion-actions {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
  }
  .doc-accordion-actions .btn { display: inline-flex; align-items: center; gap: 6px; width: auto; }

  /* Mobile card view (responsive.css collapses <table> below 1024px and puts
     label+value side-by-side via td[data-label]). For Settings specifically
     we want label ABOVE value (vertical) instead - scoped here so it doesn't
     affect tables in other menus (Transactions/Report) that rely on the
     default horizontal layout from responsive.css. */
  @media (max-width: 1023px) {
    .menu-section[data-section="settings"] td[data-label] {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 2px;
    }
  }
</style>

<script>
(function () {
  var CURRENT_YEAR_FALLBACK = new Date().getFullYear();

  /* ---------- Collapsible cards (accordion behaviour, one open at a time) ---------- */
  var collapsibles = document.querySelectorAll('.settings-collapsible');

  function closeCollapsible(el) {
    var body = el.querySelector('.settings-collapsible-body');
    // Height may currently be unbounded ('none', see openCollapsible/
    // refreshOpenHeight below) - 'none' can't be transitioned directly to
    // '0', it just jumps. Pin it to the actual current pixel height first
    // so the collapse animation still runs, then collapse on the next frame.
    body.style.maxHeight = body.scrollHeight + 'px';
    void body.offsetHeight; // force reflow so the browser registers that height
    requestAnimationFrame(function () {
      el.classList.remove('open');
      body.style.maxHeight = '0px';
    });
  }

  function openCollapsible(el) {
    collapsibles.forEach(function (other) {
      if (other !== el) closeCollapsible(other);
    });
    el.classList.add('open');
    var body = el.querySelector('.settings-collapsible-body');
    body.style.maxHeight = body.scrollHeight + 'px';
  }

  collapsibles.forEach(function (el) {
    var header = el.querySelector('.settings-collapsible-header');
    header.addEventListener('click', function () {
      if (el.classList.contains('open')) {
        closeCollapsible(el);
      } else {
        openCollapsible(el);
        if (el.id === 'cardDocuments') loadDocuments();
        if (el.id === 'cardBankAccounts') loadBankAccounts();
      }
    });
  });

  // Recalculate open card height whenever its content changes size (data
  // loaded, a nested item like a document card expands/collapses, etc).
  // Rather than recomputing an exact scrollHeight px every time (which can
  // race with a nested accordion's own max-height transition and clip its
  // content), just remove the cap entirely while the card is open - it gets
  // re-pinned to an exact px only when actually closing (see
  // closeCollapsible), so the close animation still works.
  function refreshOpenHeight(cardEl) {
    if (!cardEl.classList.contains('open')) return;
    var body = cardEl.querySelector('.settings-collapsible-body');
    body.style.maxHeight = 'none';
  }

  /* ---------- Shared confirm (warning + password) modal ---------- */
  var confirmOverlay = document.getElementById('settingsConfirmOverlay');
  var confirmTitle = document.getElementById('settingsConfirmTitle');
  var confirmMessage = document.getElementById('settingsConfirmMessage');
  var confirmPassword = document.getElementById('settingsConfirmPassword');
  var confirmError = document.getElementById('settingsConfirmError');
  var confirmSubmitBtn = document.getElementById('settingsConfirmSubmit');
  var pendingConfirmAction = null; // function(password) -> void

  function showModal(overlay) { overlay.style.display = 'flex'; }
  function hideModal(overlay) { overlay.style.display = 'none'; }

  function openConfirmModal(title, message, onConfirm) {
    confirmTitle.textContent = title;
    confirmMessage.textContent = message;
    confirmPassword.value = '';
    confirmError.style.display = 'none';
    pendingConfirmAction = onConfirm; // function() -> void, called only after password re-verified
    showModal(confirmOverlay);
    confirmPassword.focus();
  }

  document.getElementById('settingsConfirmCancel').addEventListener('click', function () {
    hideModal(confirmOverlay);
    pendingConfirmAction = null;
  });

  // Password check itself always goes through the existing shared endpoint
  // ajax/verify_password.php (single source of truth for password_verify()).
  // Once it confirms {ok:true}, it also sets $_SESSION['aos_reverify_at'],
  // which the actual edit/delete/share endpoints check via
  // ajax/_require_reverify.php - so the user only types their password once
  // per action, here, and the follow-up request doesn't resend it.
  confirmSubmitBtn.addEventListener('click', function () {
    var password = confirmPassword.value;
    if (!password) {
      confirmError.textContent = 'Password is required.';
      confirmError.style.display = 'block';
      return;
    }

    confirmError.style.display = 'none';
    confirmSubmitBtn.disabled = true;

    var fd = new FormData();
    fd.append('password', password);

    fetch('ajax/verify_password.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        confirmSubmitBtn.disabled = false;
        if (!data.ok) {
          confirmModalError(data.message || 'Incorrect password.');
          return;
        }
        if (typeof pendingConfirmAction === 'function') {
          pendingConfirmAction();
        }
      })
      .catch(function () {
        confirmSubmitBtn.disabled = false;
        confirmModalError('Network error.');
      });
  });

  function confirmModalError(message) {
    confirmError.textContent = message;
    confirmError.style.display = 'block';
  }

  /* =====================================================================
     COMPANY DOCUMENTS
     ===================================================================== */
  var docNameInput = document.getElementById('docNameInput');
  var docDateInput = document.getElementById('docDateInput');
  var docFileInput = document.getElementById('docFileInput');
  var docFinalNameInput = document.getElementById('docFinalNameInput');
  var docUploadForm = document.getElementById('docUploadForm');
  var docUploadMessage = document.getElementById('docUploadMessage');
  var docSubmitBtn = document.getElementById('docSubmitBtn');
  var docCancelEditBtn = document.getElementById('docCancelEditBtn');
  var docAccordionList = document.getElementById('docAccordionList');
  var docSelectAll = document.getElementById('docSelectAll');
  var docSelectedCount = document.getElementById('docSelectedCount');
  var docShareBtn = document.getElementById('docShareBtn');

  var editingDocumentId = null;
  var documentsCache = [];
  var finalNameManuallyEdited = false;

  function yearFromDateInput(value) {
    if (!value) return CURRENT_YEAR_FALLBACK;
    var y = parseInt(value.split('-')[0], 10);
    return isNaN(y) ? CURRENT_YEAR_FALLBACK : y;
  }

  function updateFinalNamePreview() {
    if (finalNameManuallyEdited) return;
    var name = docNameInput.value.trim();
    var year = yearFromDateInput(docDateInput.value);
    docFinalNameInput.value = name ? (name + '_' + year) : '';
  }

  docNameInput.addEventListener('input', updateFinalNamePreview);
  docDateInput.addEventListener('input', updateFinalNamePreview);
  docFinalNameInput.addEventListener('input', function () {
    finalNameManuallyEdited = true;
  });

  function resetDocumentForm() {
    docUploadForm.reset();
    finalNameManuallyEdited = false;
    docFinalNameInput.value = '';
    editingDocumentId = null;
    docFileInput.required = true;
    docFileInput.closest('.form-group').style.display = '';
    docSubmitBtn.textContent = 'Upload Document';
    docCancelEditBtn.style.display = 'none';
    docUploadMessage.textContent = '';
    docUploadMessage.className = 'settings-message';
  }

  docCancelEditBtn.addEventListener('click', resetDocumentForm);

  function loadDocuments() {
    docAccordionList.innerHTML = '<div class="empty-sub">Loading...</div>';
    fetch('ajax/list_documents.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          docAccordionList.innerHTML = '<div class="empty-sub">Failed to load documents.</div>';
          return;
        }
        documentsCache = data.documents;
        renderDocumentList();
        refreshOpenHeight(document.getElementById('cardDocuments'));
      })
      .catch(function () {
        docAccordionList.innerHTML = '<div class="empty-sub">Failed to load documents.</div>';
      });
  }

  // Closes every other accordion item in the document list, so only one is
  // ever expanded at a time (same rule as the two top-level Settings cards).
  function closeDocItem(item) {
    item.classList.remove('open');
    var body = item.querySelector('.doc-accordion-body');
    body.style.maxHeight = null;
  }

  function openDocItem(item) {
    docAccordionList.querySelectorAll('.doc-accordion-item').forEach(function (other) {
      if (other !== item) closeDocItem(other);
    });
    item.classList.add('open');
    var body = item.querySelector('.doc-accordion-body');
    body.style.maxHeight = body.scrollHeight + 'px';
  }

  function renderDocumentList() {
    docAccordionList.innerHTML = '';
    if (documentsCache.length === 0) {
      docAccordionList.innerHTML = '<div class="empty-sub">No documents yet.</div>';
      updateDocSelectionUI();
      return;
    }

    documentsCache.forEach(function (doc) {
      var item = document.createElement('div');
      item.className = 'doc-accordion-item';

      // Header: always visible in both collapsed and expanded state, with
      // the select checkbox to the LEFT of the document name.
      var header = document.createElement('div');
      header.className = 'doc-accordion-header';

      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'doc-row-checkbox';
      cb.value = doc.id;
      cb.addEventListener('click', function (e) { e.stopPropagation(); });
      cb.addEventListener('change', updateDocSelectionUI);

      var name = document.createElement('div');
      name.className = 'doc-accordion-name';
      name.textContent = doc.document_name;

      var chevron = document.createElement('i');
      chevron.className = 'ti ti-chevron-down doc-accordion-chevron';

      header.appendChild(cb);
      header.appendChild(name);
      header.appendChild(chevron);
      header.addEventListener('click', function () {
        if (item.classList.contains('open')) {
          closeDocItem(item);
        } else {
          openDocItem(item);
        }
        refreshOpenHeight(document.getElementById('cardDocuments'));
      });

      // Body: shown only when expanded - date/file details + actions.
      var body = document.createElement('div');
      body.className = 'doc-accordion-body';

      var bodyInner = document.createElement('div');
      bodyInner.className = 'doc-accordion-body-inner';

      var rowDate = document.createElement('div');
      rowDate.className = 'doc-meta-row';
      rowDate.innerHTML = '<span class="doc-meta-label">Date</span><span>' +
        escapeHtml(doc.document_date) + '</span>';

      var rowFile = document.createElement('div');
      rowFile.className = 'doc-meta-row';
      rowFile.innerHTML = '<span class="doc-meta-label">Original File</span><span>' +
        escapeHtml(doc.original_filename) + '</span>';

      var actions = document.createElement('div');
      actions.className = 'doc-accordion-actions';

      var btnDownload = document.createElement('a');
      btnDownload.href = 'ajax/download_document.php?id=' + doc.id;
      btnDownload.className = 'btn btn-secondary';
      btnDownload.innerHTML = '<i class="ti ti-download"></i> Download';

      var btnEdit = document.createElement('button');
      btnEdit.type = 'button';
      btnEdit.className = 'btn btn-secondary';
      btnEdit.innerHTML = '<i class="ti ti-pencil"></i> Edit';
      btnEdit.addEventListener('click', function (e) {
        e.stopPropagation();
        openEditDocumentForm(doc);
      });

      var btnDelete = document.createElement('button');
      btnDelete.type = 'button';
      btnDelete.className = 'btn btn-danger';
      btnDelete.innerHTML = '<i class="ti ti-trash"></i> Delete';
      btnDelete.addEventListener('click', function (e) {
        e.stopPropagation();
        confirmDeleteDocument(doc);
      });

      actions.appendChild(btnDownload);
      actions.appendChild(btnEdit);
      actions.appendChild(btnDelete);

      bodyInner.appendChild(rowDate);
      bodyInner.appendChild(rowFile);
      bodyInner.appendChild(actions);
      body.appendChild(bodyInner);

      item.appendChild(header);
      item.appendChild(body);
      docAccordionList.appendChild(item);
    });

    updateDocSelectionUI();
  }

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  function getSelectedDocIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.doc-row-checkbox:checked')).map(function (cb) {
      return cb.value;
    });
  }

  function updateDocSelectionUI() {
    var selected = getSelectedDocIds();
    docSelectedCount.textContent = selected.length + ' selected';
    docShareBtn.disabled = selected.length === 0;
  }

  docSelectAll.addEventListener('change', function () {
    document.querySelectorAll('.doc-row-checkbox').forEach(function (cb) {
      cb.checked = docSelectAll.checked;
    });
    updateDocSelectionUI();
  });

  function openEditDocumentForm(doc) {
    editingDocumentId = doc.id;
    docNameInput.value = doc.document_name;
    docDateInput.value = doc.document_date;
    finalNameManuallyEdited = true;
    docFinalNameInput.value = doc.document_name;
    docFileInput.required = false;
    docFileInput.closest('.form-group').style.display = 'none';
    docSubmitBtn.textContent = 'Update Document';
    docCancelEditBtn.style.display = 'inline-flex';
    docUploadMessage.textContent = '';
    docNameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  docUploadForm.addEventListener('submit', function (e) {
    e.preventDefault();
    docUploadMessage.textContent = '';
    docUploadMessage.className = 'settings-message';

    if (editingDocumentId) {
      var docId = editingDocumentId;
      openConfirmModal(
        'Confirm Edit',
        'You are about to update this document\'s name/date. Enter your password to continue.',
        function () {
          var fd = new FormData();
          fd.append('id', docId);
          fd.append('document_name', docFinalNameInput.value.trim() || docNameInput.value.trim());
          fd.append('document_date', docDateInput.value);

          fetch('ajax/update_document.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (!data.success) {
                confirmModalError(data.message || 'Failed to update document.');
                return;
              }
              hideModal(confirmOverlay);
              resetDocumentForm();
              loadDocuments();
            })
            .catch(function () { confirmModalError('Network error.'); });
        }
      );
      return;
    }

    // Create (no password needed).
    if (!docFileInput.files || docFileInput.files.length === 0) {
      docUploadMessage.textContent = 'Please choose a file.';
      docUploadMessage.className = 'settings-message settings-message-error';
      return;
    }

    var fd = new FormData();
    fd.append('document_name', docFinalNameInput.value.trim() || docNameInput.value.trim());
    fd.append('document_date', docDateInput.value);
    fd.append('file', docFileInput.files[0]);

    docSubmitBtn.disabled = true;
    fetch('ajax/upload_document.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        docSubmitBtn.disabled = false;
        if (!data.success) {
          docUploadMessage.textContent = data.message || 'Failed to upload document.';
          docUploadMessage.className = 'settings-message settings-message-error';
          return;
        }
        resetDocumentForm();
        loadDocuments();
      })
      .catch(function () {
        docSubmitBtn.disabled = false;
        docUploadMessage.textContent = 'Network error.';
        docUploadMessage.className = 'settings-message settings-message-error';
      });
  });

  function confirmDeleteDocument(doc) {
    openConfirmModal(
      'Delete Document',
      'This will permanently delete "' + doc.document_name + '" and its file. This action cannot be undone. Enter your password to confirm.',
      function () {
        var fd = new FormData();
        fd.append('id', doc.id);
        fetch('ajax/delete_document.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success) {
              confirmModalError(data.message || 'Failed to delete document.');
              return;
            }
            hideModal(confirmOverlay);
            loadDocuments();
          })
          .catch(function () { confirmModalError('Network error.'); });
      }
    );
  }

  docShareBtn.addEventListener('click', function () {
    var ids = getSelectedDocIds();
    if (ids.length === 0) return;
    openConfirmModal(
      'Share via WhatsApp',
      'This will download ' + ids.length + ' selected document(s) to your device, then open WhatsApp with a text list so you can attach the files yourself. Enter your password to continue.',
      function () {
        var fd = new FormData();
        ids.forEach(function (id) { fd.append('ids[]', id); });

        fetch('ajax/share_documents.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success) {
              confirmModalError(data.message || 'Failed to prepare share.');
              return;
            }
            hideModal(confirmOverlay);

            // Trigger a download for each selected document.
            data.documents.forEach(function (doc) {
              var a = document.createElement('a');
              a.href = doc.download_url;
              a.download = '';
              document.body.appendChild(a);
              a.click();
              document.body.removeChild(a);
            });

            var lines = ['Company Documents:'];
            data.documents.forEach(function (doc) {
              lines.push('- ' + doc.document_name + ' (' + doc.document_date + ')');
            });
            var waText = encodeURIComponent(lines.join('\n'));
            window.open('https://wa.me/?text=' + waText, '_blank');
          })
          .catch(function () { confirmModalError('Network error.'); });
      }
    );
  });

  /* =====================================================================
     COMPANY BANK ACCOUNTS
     ===================================================================== */
  var bankForm = document.getElementById('bankForm');
  var bankEditingId = document.getElementById('bankEditingId');
  var bankAccountNumber = document.getElementById('bankAccountNumber');
  var bankAccountName = document.getElementById('bankAccountName');
  var bankCurrencySelect = document.getElementById('bankCurrencySelect');
  var bankIntlFields = document.getElementById('bankIntlFields');
  var bankSwiftCode = document.getElementById('bankSwiftCode');
  var bankAddress = document.getElementById('bankAddress');
  var bankFormMessage = document.getElementById('bankFormMessage');
  var bankSubmitBtn = document.getElementById('bankSubmitBtn');
  var bankCancelEditBtn = document.getElementById('bankCancelEditBtn');
  var bankGroupsWrapper = document.getElementById('bankGroupsWrapper');
  var bankSelectedCount = document.getElementById('bankSelectedCount');
  var bankShareBtn = document.getElementById('bankShareBtn');
  var bankAddCurrencyBtn = document.getElementById('bankAddCurrencyBtn');

  var bankAccountsCache = [];

  function toggleBankIntlFields() {
    var isIdr = bankCurrencySelect.value === 'IDR';
    bankIntlFields.style.display = isIdr ? 'none' : 'grid';
    bankSwiftCode.required = !isIdr;
    bankAddress.required = !isIdr;
  }
  bankCurrencySelect.addEventListener('change', toggleBankIntlFields);

  function loadCurrencies(selectValue) {
    return fetch('ajax/manage_currencies.php?action=list')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) return;
        bankCurrencySelect.innerHTML = '';
        data.currencies.forEach(function (code) {
          var opt = document.createElement('option');
          opt.value = code;
          opt.textContent = code;
          bankCurrencySelect.appendChild(opt);
        });
        if (selectValue) bankCurrencySelect.value = selectValue;
        toggleBankIntlFields();
      });
  }

  var addCurrencyOverlay = document.getElementById('settingsAddCurrencyOverlay');
  var newCurrencyInput = document.getElementById('settingsNewCurrencyInput');
  var addCurrencyError = document.getElementById('settingsAddCurrencyError');

  bankAddCurrencyBtn.addEventListener('click', function () {
    newCurrencyInput.value = '';
    addCurrencyError.style.display = 'none';
    showModal(addCurrencyOverlay);
    newCurrencyInput.focus();
  });
  document.getElementById('settingsAddCurrencyCancel').addEventListener('click', function () {
    hideModal(addCurrencyOverlay);
  });
  document.getElementById('settingsAddCurrencySubmit').addEventListener('click', function () {
    var code = newCurrencyInput.value.trim().toUpperCase();
    if (!code) {
      addCurrencyError.textContent = 'Enter a currency code.';
      addCurrencyError.style.display = 'block';
      return;
    }
    var fd = new FormData();
    fd.append('action', 'add');
    fd.append('code', code);
    fetch('ajax/manage_currencies.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          addCurrencyError.textContent = data.message || 'Failed to add currency.';
          addCurrencyError.style.display = 'block';
          return;
        }
        hideModal(addCurrencyOverlay);
        loadCurrencies(code);
      })
      .catch(function () {
        addCurrencyError.textContent = 'Network error.';
        addCurrencyError.style.display = 'block';
      });
  });

  function resetBankForm() {
    bankForm.reset();
    bankEditingId.value = '';
    bankSubmitBtn.textContent = 'Save Account';
    bankCancelEditBtn.style.display = 'none';
    bankFormMessage.textContent = '';
    bankFormMessage.className = 'settings-message';
    toggleBankIntlFields();
  }
  bankCancelEditBtn.addEventListener('click', resetBankForm);

  function loadBankAccounts() {
    bankGroupsWrapper.innerHTML = '<div class="empty-sub">Loading...</div>';
    fetch('ajax/list_bank_accounts.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          bankGroupsWrapper.innerHTML = '<div class="empty-sub">Failed to load bank accounts.</div>';
          return;
        }
        bankAccountsCache = data.accounts;
        renderBankGroups(data.grouped);
        refreshOpenHeight(document.getElementById('cardBankAccounts'));
      })
      .catch(function () {
        bankGroupsWrapper.innerHTML = '<div class="empty-sub">Failed to load bank accounts.</div>';
      });
  }

  function renderBankGroups(grouped) {
    bankGroupsWrapper.innerHTML = '';
    var currencies = Object.keys(grouped);
    if (currencies.length === 0) {
      bankGroupsWrapper.innerHTML = '<div class="empty-sub">No bank accounts yet.</div>';
      updateBankSelectionUI();
      return;
    }

    currencies.forEach(function (currency) {
      var groupDiv = document.createElement('div');
      groupDiv.className = 'settings-bank-group';

      var title = document.createElement('div');
      title.className = 'settings-bank-group-title';
      title.textContent = currency;
      groupDiv.appendChild(title);

      var wrapper = document.createElement('div');
      wrapper.className = 'table-wrapper';

      var table = document.createElement('table');
      var isIdr = currency === 'IDR';
      table.innerHTML =
        '<thead><tr>' +
        '<th style="width:36px;"><input type="checkbox" class="bank-select-all-group"></th>' +
        '<th>Account Number</th><th>Account Name</th>' +
        (isIdr ? '' : '<th>SWIFT</th><th>Address</th>') +
        '<th style="width:140px;">Actions</th>' +
        '</tr></thead>';
      var tbody = document.createElement('tbody');

      grouped[currency].forEach(function (acc) {
        var tr = document.createElement('tr');

        var tdCheck = document.createElement('td');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'bank-row-checkbox';
        cb.value = acc.id;
        cb.addEventListener('change', updateBankSelectionUI);
        tdCheck.appendChild(cb);

        var tdNumber = document.createElement('td');
        tdNumber.setAttribute('data-label', 'Account Number');
        tdNumber.textContent = acc.account_number;
        var tdName = document.createElement('td');
        tdName.setAttribute('data-label', 'Account Name');
        tdName.textContent = acc.account_name;

        var tdActions = document.createElement('td');
        tdActions.setAttribute('data-label', 'Actions');
        var btnEdit = document.createElement('button');
        btnEdit.type = 'button';
        btnEdit.className = 'btn btn-secondary';
        btnEdit.style.marginRight = '6px';
        btnEdit.innerHTML = '<i class="ti ti-pencil"></i>';
        btnEdit.addEventListener('click', function () { openEditBankForm(acc); });

        var btnDelete = document.createElement('button');
        btnDelete.type = 'button';
        btnDelete.className = 'btn btn-danger';
        btnDelete.innerHTML = '<i class="ti ti-trash"></i>';
        btnDelete.addEventListener('click', function () { confirmDeleteBankAccount(acc); });

        tdActions.appendChild(btnEdit);
        tdActions.appendChild(btnDelete);

        tr.appendChild(tdCheck);
        tr.appendChild(tdNumber);
        tr.appendChild(tdName);
        if (!isIdr) {
          var tdSwift = document.createElement('td');
          tdSwift.setAttribute('data-label', 'SWIFT');
          tdSwift.textContent = acc.swift_code || '-';
          var tdAddress = document.createElement('td');
          tdAddress.setAttribute('data-label', 'Address');
          tdAddress.textContent = acc.address || '-';
          tr.appendChild(tdSwift);
          tr.appendChild(tdAddress);
        }
        tr.appendChild(tdActions);
        tbody.appendChild(tr);
      });

      table.appendChild(tbody);
      wrapper.appendChild(table);
      groupDiv.appendChild(wrapper);
      bankGroupsWrapper.appendChild(groupDiv);

      var selectAllGroup = table.querySelector('.bank-select-all-group');
      selectAllGroup.addEventListener('change', function () {
        tbody.querySelectorAll('.bank-row-checkbox').forEach(function (cb) {
          cb.checked = selectAllGroup.checked;
        });
        updateBankSelectionUI();
      });
    });

    updateBankSelectionUI();
  }

  function getSelectedBankIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.bank-row-checkbox:checked')).map(function (cb) {
      return cb.value;
    });
  }

  function updateBankSelectionUI() {
    var selected = getSelectedBankIds();
    bankSelectedCount.textContent = selected.length + ' selected';
    bankShareBtn.disabled = selected.length === 0;
  }

  function openEditBankForm(acc) {
    bankEditingId.value = acc.id;
    bankAccountNumber.value = acc.account_number;
    bankAccountName.value = acc.account_name;
    loadCurrencies(acc.currency).then(function () {
      bankSwiftCode.value = acc.swift_code || '';
      bankAddress.value = acc.address || '';
      toggleBankIntlFields();
    });
    bankSubmitBtn.textContent = 'Update Account';
    bankCancelEditBtn.style.display = 'inline-flex';
    bankForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  bankForm.addEventListener('submit', function (e) {
    e.preventDefault();
    bankFormMessage.textContent = '';
    bankFormMessage.className = 'settings-message';

    var payload = {
      account_number: bankAccountNumber.value.trim(),
      account_name: bankAccountName.value.trim(),
      currency: bankCurrencySelect.value,
      swift_code: bankSwiftCode.value.trim(),
      address: bankAddress.value.trim(),
    };

    if (bankEditingId.value) {
      openConfirmModal(
        'Confirm Edit',
        'You are about to update this bank account. Enter your password to continue.',
        function () {
          var fd = new FormData();
          fd.append('id', bankEditingId.value);
          Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });

          fetch('ajax/save_bank_account.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (!data.success) {
                confirmModalError(data.message || 'Failed to update account.');
                return;
              }
              hideModal(confirmOverlay);
              resetBankForm();
              loadBankAccounts();
            })
            .catch(function () { confirmModalError('Network error.'); });
        }
      );
      return;
    }

    // Create (no password needed).
    var fd = new FormData();
    Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });

    bankSubmitBtn.disabled = true;
    fetch('ajax/save_bank_account.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        bankSubmitBtn.disabled = false;
        if (!data.success) {
          bankFormMessage.textContent = data.message || 'Failed to save account.';
          bankFormMessage.className = 'settings-message settings-message-error';
          return;
        }
        resetBankForm();
        loadBankAccounts();
      })
      .catch(function () {
        bankSubmitBtn.disabled = false;
        bankFormMessage.textContent = 'Network error.';
        bankFormMessage.className = 'settings-message settings-message-error';
      });
  });

  function confirmDeleteBankAccount(acc) {
    openConfirmModal(
      'Delete Bank Account',
      'This will permanently delete account "' + acc.account_number + ' - ' + acc.account_name + '". This action cannot be undone. Enter your password to confirm.',
      function () {
        var fd = new FormData();
        fd.append('id', acc.id);
        fetch('ajax/delete_bank_account.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success) {
              confirmModalError(data.message || 'Failed to delete account.');
              return;
            }
            hideModal(confirmOverlay);
            loadBankAccounts();
          })
          .catch(function () { confirmModalError('Network error.'); });
      }
    );
  }

  bankShareBtn.addEventListener('click', function () {
    var ids = getSelectedBankIds();
    if (ids.length === 0) return;
    openConfirmModal(
      'Share via WhatsApp',
      'This will send full details of ' + ids.length + ' selected bank account(s) as text via WhatsApp. Enter your password to continue.',
      function () {
        var fd = new FormData();
        ids.forEach(function (id) { fd.append('ids[]', id); });

        fetch('ajax/share_bank_accounts.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success) {
              confirmModalError(data.message || 'Failed to prepare share.');
              return;
            }
            hideModal(confirmOverlay);

            var lines = ['Company Bank Accounts:'];
            data.accounts.forEach(function (acc) {
              lines.push('');
              lines.push('Account Number: ' + acc.account_number);
              lines.push('Account Name: ' + acc.account_name);
              lines.push('Currency: ' + acc.currency);
              if (acc.currency !== 'IDR') {
                lines.push('SWIFT Code: ' + (acc.swift_code || '-'));
                lines.push('Address: ' + (acc.address || '-'));
              }
            });
            var waText = encodeURIComponent(lines.join('\n'));
            window.open('https://wa.me/?text=' + waText, '_blank');
          })
          .catch(function () { confirmModalError('Network error.'); });
      }
    );
  });

  /* ---------- Init ---------- */
  loadCurrencies();

  // Only load table/list data the first time each card is actually opened
  // (handled in the header click listener above), so Settings stays cheap
  // to render while the section is not in use.
})();
</script>