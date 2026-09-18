<?php
// lisani_aos/logistic_content.php
// Included from index.php, same pattern as transaction_content.php: this
// file wraps its OWN <div class="menu-section" data-section="logistic" ...>
// — index.php's include REPLACES that section wrapper entirely, do not
// wrap it again from index.php.
//
// Primary/Secondary packaging units are NOT rendered from the JSON files
// at page-load time anymore — they're fetched live via
// ajax/manage_packaging_units.php (action=list) and can be added/edited/
// deleted only through the "Edit" fly windows next to each select, never
// by hand-editing json_file/*.json.

$departmentsFile = __DIR__ . '/departments.json';
$departmentsData = json_decode(file_get_contents($departmentsFile), true) ?: ['departments' => []];
$departments     = $departmentsData['departments'];

// Only "dates" is currently supported for Logistic — every other
// department is shown but disabled in the UI (see logDepartment below).
$LOG_SUPPORTED_DEPARTMENT = 'dates';
?>

<div class="menu-section" data-section="logistic" style="display:none;">

<div class="card" id="viewLogistic" style="width:100%;">
  <div class="panel-header">
    <div class="panel-title">Logistic</div>
  </div>

  <style>
    #logTabGroup {
      display: flex;
      width: 100%;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      background: none;
      padding: 0;
    }
    #logTabGroup .tab {
      flex: 1 1 0;
      text-align: center;
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-md);
      background: var(--bg-recessed);
      box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
      color: var(--text-secondary);
      font-size: var(--text-sm);
      font-weight: 600;
      cursor: pointer;
      transition: color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
    }
    #logTabGroup .tab:hover { color: var(--text-primary); }
    #logTabGroup .tab.active {
      background: var(--bg-surface);
      color: var(--accent);
      box-shadow: 5px 5px 10px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
    }

    /* Free-text inputs across this form are forced to uppercase as the
       user types (see the global input-uppercase listener below) — except
       anything tied to a physical folder/file path, which must stay
       exactly as typed. text-transform here just keeps the *display*
       consistent while the JS listener rewrites the actual value. */
    .input-uppercase { text-transform: uppercase; }

    /* Circular "!" info icon next to a label — click/tap to reveal a short
       tooltip. Used for Primary/Secondary Packaging so the qty-source
       explanation isn't permanently taking up space under the form. */
    .info-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: var(--bg-surface);
      box-shadow: 2px 2px 4px var(--shadow-dark), -1px -1px 3px var(--shadow-light);
      color: var(--text-muted);
      font-size: 11px;
      font-weight: 700;
      font-style: normal;
      cursor: pointer;
      user-select: none;
      flex-shrink: 0;
    }
    .info-icon:hover, .info-icon:focus { color: var(--accent); outline: none; }
    .info-tooltip {
      display: none;
      margin: var(--space-2) 0 0;
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-sm);
      background: var(--bg-recessed);
      color: var(--text-muted);
      font-size: var(--text-sm);
    }
    .info-tooltip.open { display: block; }

    #logDocToggle:hover { background: var(--bg-surface-alt); }

    .log-unit-row {
      padding: var(--space-3);
      display: flex;
      align-items: center;
      gap: var(--space-2);
      margin-bottom: var(--space-2);
    }
  </style>

  <div class="tab-group" id="logTabGroup">
    <div class="tab active" data-log-tab="list">Logistic List</div>
    <div class="tab" data-log-tab="create">Create New Logistic</div>
  </div>

  <!-- Tab 1: Logistic List (preview) — default tab on entering the menu -->
  <div id="logTabPanelList">
    <div class="accordion-list" id="logPreviewList"></div>
    <div class="empty-state" id="logPreviewEmpty" style="display:none;">
      <div class="empty-title">No logistic records yet</div>
      <div class="empty-sub">Switch to the Create New Logistic tab to add one.</div>
    </div>
  </div>

  <!-- Tab 2: Create New Logistic -->
  <div id="logTabPanelCreate" style="display:none;">

    <div class="form-group">
      <div class="label">Department</div>
      <select class="select" id="logDepartment">
        <?php foreach ($departments as $dept): ?>
          <option value="<?= htmlspecialchars($dept['key']) ?>"
            <?= $dept['key'] !== $LOG_SUPPORTED_DEPARTMENT ? 'data-unsupported="1"' : '' ?>>
            <?= htmlspecialchars($dept['label']) ?><?= $dept['key'] !== $LOG_SUPPORTED_DEPARTMENT ? ' (not available yet)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <div class="label">Activity Code</div>
      <select class="select" id="logActivityCode">
        <option value="">Select activity code…</option>
      </select>
      <div class="empty-sub" id="logActivityCodeEmpty" style="display:none;">No activity codes available.</div>
    </div>

    <div class="form-group">
      <div class="label">Incoming Date</div>
      <input type="date" class="input" id="logIncomingDate">
      <div class="empty-sub">Optional — can be left empty and filled in later.</div>
    </div>

    <!-- Import Document: collapsible, collapsed by default -->
    <div class="neo-inset" style="border-radius:var(--radius-md); margin-bottom:var(--space-4); overflow:hidden;">
      <div id="logDocToggle" style="padding:var(--space-3) var(--space-4); display:flex; justify-content:space-between; align-items:center; cursor:pointer; transition:background .15s ease;">
        <span style="font-weight:600; color:var(--text-primary);">Import Document</span>
        <i class="ti ti-chevron-down" id="logDocChevron" style="color:var(--text-muted); transition:transform .18s ease;"></i>
      </div>
      <div id="logDocBody" style="max-height:0; overflow:hidden; transition:max-height .2s ease;">
        <div style="padding:0 var(--space-4) var(--space-4);">
          <div class="form-group">
            <div class="label">Document Group</div>
            <select class="select" id="logDocType">
              <option value="shipper">Shipper</option>
              <option value="custom">Custom</option>
              <option value="consignee">Consignee</option>
            </select>
          </div>
          <div class="form-group">
            <div class="label">Document Name</div>
            <input type="text" class="input input-uppercase" id="logDocName" placeholder="e.g. Invoice, Packing List">
          </div>
          <div class="form-group">
            <div class="label">Document Date</div>
            <input type="date" class="input" id="logDocDate">
          </div>
          <div class="form-group">
            <div class="label">File</div>
            <input type="file" class="input" id="logDocFile">
          </div>
          <div class="empty-sub" id="logDocMsg" style="display:none;"></div>
          <div style="display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" id="btnLogDocUpload">Upload Document</button>
          </div>
          <div id="logDocUploadedList" style="margin-top:var(--space-3); display:flex; flex-direction:column; gap:var(--space-2);"></div>
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="label" style="display:flex; align-items:center; gap:var(--space-2);">
        Primary Packaging
        <span class="info-icon" id="logPrimaryInfoIcon" tabindex="0">!</span>
      </div>
      <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
        <input type="text" inputmode="decimal" class="input input-number-comma" id="logPrimaryQty" placeholder="Qty" style="flex:1; min-width:100px;">
        <select class="select" id="logPrimaryUnit" style="flex:2; min-width:200px;">
          <option value="">-- select unit --</option>
        </select>
        <input type="text" class="input" id="logPrimaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
        <button type="button" class="btn btn-secondary" id="btnManagePrimaryUnits">Edit</button>
      </div>
      <div class="info-tooltip" id="logPrimaryInfoTooltip">Fill in either Primary or Secondary Qty — the other one is calculated automatically from the selected unit's ratio.</div>
    </div>

    <div class="form-group">
      <div class="label" style="display:flex; align-items:center; gap:var(--space-2);">
        Secondary Packaging
        <span class="info-icon" id="logSecondaryInfoIcon" tabindex="0">!</span>
      </div>
      <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
        <input type="text" inputmode="decimal" class="input input-number-comma" id="logSecondaryQty" placeholder="Qty" style="flex:1; min-width:100px;">
        <select class="select" id="logSecondaryUnit" style="flex:2; min-width:200px;">
          <option value="">-- select unit --</option>
        </select>
        <input type="text" class="input" id="logSecondaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
        <button type="button" class="btn btn-secondary" id="btnManageSecondaryUnits">Edit</button>
      </div>
      <div class="info-tooltip" id="logSecondaryInfoTooltip">Fill in either Primary or Secondary Qty — the other one is calculated automatically from the selected unit's ratio.</div>
    </div>

    <div class="empty-sub" id="logCreateError" style="display:none; color:var(--danger);"></div>

    <div style="display:flex; justify-content:flex-end; margin-top:var(--space-5);">
      <button type="button" class="btn btn-primary" id="btnLogCreateSubmit">Save</button>
    </div>
  </div>

</div>

<!-- Manage Primary Units fly window -->
<div class="modal-overlay" id="managePrimaryUnitsOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Manage Primary Units</div></div>
    <div class="modal-body">
      <div id="primaryUnitList"></div>
      <div class="empty-sub" id="primaryUnitError" style="display:none; color:var(--danger);"></div>
      <div class="form-group" style="margin-top:var(--space-3);">
        <div class="label">New unit name</div>
        <input type="text" class="input input-uppercase" id="primaryUnitNewLabel" placeholder="e.g. Master Carton">
      </div>
      <div class="form-group">
        <div class="label">Weight per unit (KG)</div>
        <input type="text" inputmode="decimal" class="input input-number-comma" id="primaryUnitNewWeight">
      </div>
      <button type="button" class="btn btn-primary" id="btnPrimaryUnitAdd" style="width:100%;">Add Unit</button>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnClosePrimaryUnits">Close</button>
    </div>
  </div>
</div>

<!-- Manage Secondary Units fly window -->
<div class="modal-overlay" id="manageSecondaryUnitsOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Manage Secondary Units</div></div>
    <div class="modal-body">
      <div id="secondaryUnitList"></div>
      <div class="empty-sub" id="secondaryUnitError" style="display:none; color:var(--danger);"></div>
      <div class="form-group" style="margin-top:var(--space-3);">
        <div class="label">New unit name</div>
        <input type="text" class="input input-uppercase" id="secondaryUnitNewLabel" placeholder="e.g. Baby Carton">
      </div>
      <div class="form-group">
        <div class="label">1 Primary unit = how many of this unit</div>
        <input type="text" inputmode="decimal" class="input input-number-comma" id="secondaryUnitNewRatio">
      </div>
      <div class="form-group">
        <div class="label">Weight per unit (KG)</div>
        <input type="text" inputmode="decimal" class="input input-number-comma" id="secondaryUnitNewWeight">
      </div>
      <button type="button" class="btn btn-primary" id="btnSecondaryUnitAdd" style="width:100%;">Add Unit</button>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnCloseSecondaryUnits">Close</button>
    </div>
  </div>
</div>

<!-- Re-verify password: shared gate before Edit or Delete on a Logistic
     List row. Mirrors verify_password.php + aos_require_recent_reverify()
     used elsewhere in the app (Settings > Company Documents & Bank
     Accounts) — one password prompt, then a short window (120s server-side)
     where update_logistic.php / delete_logistic.php don't ask again. -->
<div class="modal-overlay" id="logReverifyOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Confirm Password</div></div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Enter your password to continue</div>
        <input type="password" class="input" id="logReverifyPassword" placeholder="Password">
      </div>
      <div class="empty-sub" id="logReverifyError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnLogReverifyCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnLogReverifyConfirm">Confirm</button>
    </div>
  </div>
</div>

<!-- Edit Logistic: opens only after logReverifyOverlay succeeds. Reuses
     the same rate-column fields as Create New Logistic (Primary/Secondary
     packaging), pre-filled from the row being edited. Activity Code itself
     is NOT editable here — a logistic is tied 1:1 to its activity code
     (UNIQUE(activity_id) in the DB), changing it would mean moving the
     record to a different code entirely, which isn't what "Edit" means
     here. -->
<div class="modal-overlay" id="logEditOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Edit Logistic</div></div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Activity Code</div>
        <input type="text" class="input" id="logEditActivityLabel" disabled>
      </div>
      <div class="form-group">
        <div class="label">Incoming Date</div>
        <input type="date" class="input" id="logEditIncomingDate">
      </div>
      <div class="form-group">
        <div class="label" style="display:flex; align-items:center; gap:var(--space-2);">
          Primary Packaging
          <span class="info-icon" id="logEditPrimaryInfoIcon" tabindex="0">!</span>
        </div>
        <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
          <input type="text" inputmode="decimal" class="input input-number-comma" id="logEditPrimaryQty" placeholder="Qty" style="flex:1; min-width:100px;">
          <select class="select" id="logEditPrimaryUnit" style="flex:2; min-width:200px;">
            <option value="">-- select unit --</option>
          </select>
          <input type="text" class="input" id="logEditPrimaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
        </div>
        <div class="info-tooltip" id="logEditPrimaryInfoTooltip">Fill in either Primary or Secondary Qty — the other one is calculated automatically from the selected unit's ratio.</div>
      </div>
      <div class="form-group">
        <div class="label" style="display:flex; align-items:center; gap:var(--space-2);">
          Secondary Packaging
          <span class="info-icon" id="logEditSecondaryInfoIcon" tabindex="0">!</span>
        </div>
        <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
          <input type="text" inputmode="decimal" class="input input-number-comma" id="logEditSecondaryQty" placeholder="Qty" style="flex:1; min-width:100px;">
          <select class="select" id="logEditSecondaryUnit" style="flex:2; min-width:200px;">
            <option value="">-- select unit --</option>
          </select>
          <input type="text" class="input" id="logEditSecondaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
        </div>
        <div class="info-tooltip" id="logEditSecondaryInfoTooltip">Fill in either Primary or Secondary Qty — the other one is calculated automatically from the selected unit's ratio.</div>
      </div>
      <div class="empty-sub" id="logEditError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnLogEditCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnLogEditSave">Save</button>
    </div>
  </div>
</div>

<!-- Add Document: opens straight from a Logistic List row's "Add Document"
     button, no password reverify needed (same as Import Document on the
     Create tab). This exists because the Activity Code dropdown on the
     Create tab disables activity codes that already have a logistic
     (has_logistic), so once a logistic is saved there was previously no
     way back into the upload form for that activity — this modal targets
     the row's activity_id directly instead of going through that select. -->
<div class="modal-overlay" id="logAddDocOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title" id="logAddDocTitle">Add Document</div></div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Document Group</div>
        <select class="select" id="logAddDocType">
          <option value="shipper">Shipper</option>
          <option value="custom">Custom</option>
          <option value="consignee">Consignee</option>
        </select>
      </div>
      <div class="form-group">
        <div class="label">Document Name</div>
        <input type="text" class="input input-uppercase" id="logAddDocName" placeholder="e.g. Invoice, Packing List">
      </div>
      <div class="form-group">
        <div class="label">Document Date</div>
        <input type="date" class="input" id="logAddDocDate">
      </div>
      <div class="form-group">
        <div class="label">File</div>
        <input type="file" class="input" id="logAddDocFile">
      </div>
      <div class="empty-sub" id="logAddDocMsg" style="display:none;"></div>
      <div id="logAddDocUploadedList" style="margin-top:var(--space-3); display:flex; flex-direction:column; gap:var(--space-2);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnLogAddDocClose">Close</button>
      <button type="button" class="btn btn-primary" id="btnLogAddDocUpload">Upload Document</button>
    </div>
  </div>
</div>

<!-- Delete Logistic confirmation: opens only after logReverifyOverlay
     succeeds. All uploaded import documents for this activity code are
     moved to storage/recycle/ (not deleted outright) — see
     delete_logistic.php. -->
<div class="modal-overlay" id="logDeleteOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Delete Logistic</div></div>
    <div class="modal-body">
      <div class="empty-sub" id="logDeleteWarning" style="color:var(--danger);"></div>
      <div class="empty-sub" id="logDeleteError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnLogDeleteCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="btnLogDeleteConfirm">Delete</button>
    </div>
  </div>
</div>

</div>

<script>
(function () {
  function show(el) { el.style.display = 'flex'; }
  function hide(el) { el.style.display = 'none'; }

  // ---------- Uppercase free-text inputs ----------
  // Every free-text input in this form is forced to uppercase as the user
  // types, EXCEPT anything tied to a physical folder/file path. Document
  // Name (logDocName) is included here even though it becomes part of the
  // stored file name server-side — the server lowercases it again when
  // building the physical filename (see upload_logistic_document.php), so
  // uppercasing it here doesn't conflict with that.
  // ---------- Number inputs: thousand-comma formatting while typing ----------
  // Any field with class .input-number-comma is type="text" (not type=
  // "number", which rejects commas outright) and gets live comma-grouping
  // as the user types, cursor position preserved. parseNumberInput() strips
  // the commas back out before the value is used in any calculation or
  // sent to the server — always read these fields through it, never
  // parseFloat(el.value) directly.
  function formatNumberInput(raw) {
    if (raw === '') return '';
    var neg = raw.trim().charAt(0) === '-';
    var cleaned = raw.replace(/[^0-9.]/g, '');
    var firstDot = cleaned.indexOf('.');
    var intPart = firstDot === -1 ? cleaned : cleaned.slice(0, firstDot);
    var decPart = firstDot === -1 ? '' : cleaned.slice(firstDot + 1).replace(/\./g, '');
    intPart = intPart.replace(/^0+(?=\d)/, '');
    var grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var result = (neg ? '-' : '') + grouped + (firstDot === -1 ? '' : '.' + decPart);
    return result;
  }

  function parseNumberInput(raw) {
    if (raw === null || raw === undefined) return NaN;
    var cleaned = String(raw).replace(/,/g, '').trim();
    if (cleaned === '') return NaN;
    return parseFloat(cleaned);
  }

  function initNumberCommaInput(el) {
    el.addEventListener('input', function () {
      var before = el.value;
      var pos = el.selectionStart;
      var digitsBeforeCursor = before.slice(0, pos).replace(/[^0-9]/g, '').length;
      el.value = formatNumberInput(before);
      // Re-find the cursor position by counting digits back in from the left,
      // so inserting/deleting a digit mid-number doesn't jump the cursor to
      // the end as the comma grouping shifts around it.
      var count = 0, newPos = el.value.length;
      for (var i = 0; i < el.value.length; i++) {
        if (/[0-9]/.test(el.value.charAt(i))) count++;
        if (count === digitsBeforeCursor) { newPos = i + 1; break; }
      }
      if (digitsBeforeCursor === 0) newPos = 0;
      el.setSelectionRange(newPos, newPos);
    });
  }

  document.querySelectorAll('.input-number-comma').forEach(initNumberCommaInput);

  document.querySelectorAll('.input-uppercase').forEach(function (el) {
    el.addEventListener('input', function () {
      var pos = el.selectionStart;
      el.value = el.value.toUpperCase();
      if (pos !== null) el.setSelectionRange(pos, pos);
    });
  });

  // ---------- Info icons (Primary/Secondary Packaging) ----------
  [['logPrimaryInfoIcon', 'logPrimaryInfoTooltip'], ['logSecondaryInfoIcon', 'logSecondaryInfoTooltip']].forEach(function (pair) {
    var icon = document.getElementById(pair[0]);
    var tooltip = document.getElementById(pair[1]);
    icon.addEventListener('click', function () { tooltip.classList.toggle('open'); });
    icon.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tooltip.classList.toggle('open'); }
    });
  });

  // ---------- Tabs ----------
  var logTabGroup = document.getElementById('logTabGroup');
  var panels = {
    list:   document.getElementById('logTabPanelList'),
    create: document.getElementById('logTabPanelCreate')
  };

  function setActiveLogTab(name) {
    logTabGroup.querySelectorAll('.tab').forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-log-tab') === name);
    });
    Object.keys(panels).forEach(function (key) {
      panels[key].style.display = key === name ? 'block' : 'none';
    });
    if (name === 'list') loadLogisticList();
  }

  logTabGroup.querySelectorAll('.tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      setActiveLogTab(tab.getAttribute('data-log-tab'));
    });
  });

  // ---------- Accordion (same pattern as Activity Code / Customer List) ----------
  function openAccordionItem(item) {
    item.classList.add('open');
    var body = item.querySelector('.accordion-body');
    body.style.maxHeight = body.scrollHeight + 'px';
  }
  function closeAccordionItem(item) {
    item.classList.remove('open');
    var body = item.querySelector('.accordion-body');
    body.style.maxHeight = '0px';
  }
  function toggleAccordionItem(item) {
    var isOpen = item.classList.contains('open');
    var ownList = item.closest('.accordion-list');
    if (ownList) ownList.querySelectorAll('.accordion-item.open').forEach(closeAccordionItem);
    if (!isOpen) openAccordionItem(item);
  }

  function accordionRow(label, value) {
    var row = document.createElement('div');
    row.className = 'accordion-row';
    var l = document.createElement('span');
    l.className = 'accordion-row-label';
    l.textContent = label;
    var v = document.createElement('span');
    v.className = 'accordion-row-value';
    v.textContent = value;
    row.appendChild(l);
    row.appendChild(v);
    return row;
  }

  // ---------- Tab 1: Logistic List ----------
  var logPreviewList  = document.getElementById('logPreviewList');
  var logPreviewEmpty = document.getElementById('logPreviewEmpty');

  function fmtNum(v) {
    if (v === null || v === undefined || v === '') return '-';
    var n = Number(v);
    if (isNaN(n)) return '-';
    return n.toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

  function renderLogisticList(list) {
    logPreviewList.innerHTML = '';
    logPreviewEmpty.style.display = list.length ? 'none' : 'block';

    list.forEach(function (l) {
      var item = document.createElement('div');
      item.className = 'accordion-item';

      var header = document.createElement('div');
      header.className = 'accordion-header';

      var title = document.createElement('span');
      title.className = 'accordion-title';
      title.textContent = l.activity_name;

      var chevron = document.createElement('i');
      chevron.className = 'ti ti-chevron-down accordion-chevron';

      header.appendChild(title);
      header.appendChild(chevron);
      header.addEventListener('click', function () { toggleAccordionItem(item); });

      var body = document.createElement('div');
      body.className = 'accordion-body';
      var bodyInner = document.createElement('div');
      bodyInner.className = 'accordion-body-inner';

      bodyInner.appendChild(accordionRow('Activity Code', l.activity_code));
      bodyInner.appendChild(accordionRow('Department', l.department));
      bodyInner.appendChild(accordionRow('Incoming Date', l.incoming_date || 'Not set yet'));
      bodyInner.appendChild(accordionRow('Primary Packaging', fmtNum(l.primary_qty) + ' ' + (l.primary_unit_label || '') + '  (' + fmtNum(l.primary_total_weight_kg) + ' KG)'));
      bodyInner.appendChild(accordionRow('Remaining Primary Qty', fmtNum(l.remaining_primary_qty) + ' / ' + fmtNum(l.primary_qty)));
      bodyInner.appendChild(accordionRow('Secondary Packaging', fmtNum(l.secondary_qty) + ' ' + (l.secondary_unit_label || '') + '  (' + fmtNum(l.secondary_total_weight_kg) + ' KG)'));
      bodyInner.appendChild(accordionRow('Documents', 'Shipper: ' + l.documents.shipper + ' · Custom: ' + l.documents.custom + ' · Consignee: ' + l.documents.consignee));

      var actionsRow = document.createElement('div');
      actionsRow.style.cssText = 'display:flex; gap:var(--space-2); margin-top:var(--space-3);';

      var addDocBtn = document.createElement('button');
      addDocBtn.type = 'button';
      addDocBtn.className = 'btn btn-secondary';
      addDocBtn.textContent = 'Add Document';
      addDocBtn.style.cssText = 'flex:1; padding:var(--space-2) var(--space-3); font-size:var(--text-sm); justify-content:center;';
      addDocBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openAddDocument(l);
      });

      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-secondary';
      editBtn.textContent = 'Edit';
      editBtn.style.cssText = 'flex:1; padding:var(--space-2) var(--space-3); font-size:var(--text-sm); justify-content:center;';
      editBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openLogisticReverify('edit', l);
      });

      var deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'btn btn-danger';
      deleteBtn.textContent = 'Delete';
      deleteBtn.style.cssText = 'flex:1; padding:var(--space-2) var(--space-3); font-size:var(--text-sm); justify-content:center;';
      deleteBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openLogisticReverify('delete', l);
      });

      actionsRow.appendChild(addDocBtn);
      actionsRow.appendChild(editBtn);
      actionsRow.appendChild(deleteBtn);
      bodyInner.appendChild(actionsRow);

      body.appendChild(bodyInner);
      item.appendChild(header);
      item.appendChild(body);
      logPreviewList.appendChild(item);
    });
  }

  function loadLogisticList() {
    fetch('ajax/list_logistics.php')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) renderLogisticList(res.data);
        else console.warn(res.message);
      })
      .catch(function (e) { console.error(e); });
  }

  // ---------- Tab 2: Create New Logistic ----------
  var logDepartment       = document.getElementById('logDepartment');
  var logActivityCode     = document.getElementById('logActivityCode');
  var logActivityCodeEmpty= document.getElementById('logActivityCodeEmpty');
  var selectedActivity    = null; // { id, activity_code, activity_name, relative_path, year }
  var activityById        = {};   // id -> activity data, for selectedActivity lookup on change

  function loadActivityCodes() {
    var dept = logDepartment.value;
    selectedActivity = null;
    activityById = {};
    logActivityCode.innerHTML = '<option value="">Select activity code…</option>';
    updatePackagingAvailability();
    loadUploadedDocuments();

    var deptOption = logDepartment.selectedOptions[0];
    if (deptOption && deptOption.getAttribute('data-unsupported')) {
      logActivityCode.disabled = true;
      logActivityCodeEmpty.textContent = 'Logistic is not available for this department yet.';
      logActivityCodeEmpty.style.display = 'block';
      return;
    }
    logActivityCode.disabled = false;

    fetch('ajax/list_logistic_activities.php?department=' + encodeURIComponent(dept))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) { console.warn(res.message); return; }
        logActivityCodeEmpty.style.display = res.data.length ? 'none' : 'block';
        logActivityCodeEmpty.textContent = 'No activity codes for this department yet.';

        res.data.forEach(function (a) {
          activityById[a.id] = a;
          var opt = document.createElement('option');
          opt.value = a.id;
          opt.textContent = a.year + ' — ' + a.activity_code + ' — ' + a.activity_name + (a.has_logistic ? ' (logistic already exists)' : '');
          if (a.has_logistic) opt.disabled = true;
          logActivityCode.appendChild(opt);
        });
      })
      .catch(function (e) { console.error(e); });
  }

  logDepartment.addEventListener('change', loadActivityCodes);

  logActivityCode.addEventListener('change', function () {
    selectedActivity = activityById[logActivityCode.value] || null;
    updatePackagingAvailability();
    loadUploadedDocuments();
  });

  // ---------- Import Document: collapsible ----------
  var logDocToggle  = document.getElementById('logDocToggle');
  var logDocChevron = document.getElementById('logDocChevron');
  var logDocBody    = document.getElementById('logDocBody');
  var logDocOpen    = false;

  logDocToggle.addEventListener('click', function () {
    logDocOpen = !logDocOpen;
    logDocChevron.style.transform = logDocOpen ? 'rotate(180deg)' : 'rotate(0deg)';
    logDocBody.style.maxHeight = logDocOpen ? logDocBody.scrollHeight + 'px' : '0px';
  });

  var logDocType   = document.getElementById('logDocType');
  var logDocName   = document.getElementById('logDocName');
  var logDocDate   = document.getElementById('logDocDate');
  var logDocFile   = document.getElementById('logDocFile');
  var logDocMsg    = document.getElementById('logDocMsg');
  var logDocUploadedList = document.getElementById('logDocUploadedList');

  function renderUploadedDocuments(docs) {
    logDocUploadedList.innerHTML = '';
    docs.forEach(function (d) {
      var row = document.createElement('div');
      row.className = 'accordion-row';
      row.style.cssText = 'background:var(--bg-surface); border-radius:var(--radius-sm); padding:var(--space-2) var(--space-3); display:flex; justify-content:space-between; gap:var(--space-2);';

      var left = document.createElement('span');
      left.textContent = '[' + d.document_type + '] ' + d.document_name;

      var right = document.createElement('span');
      right.style.color = 'var(--text-muted)';
      right.textContent = d.document_date || '';

      row.appendChild(left);
      row.appendChild(right);
      logDocUploadedList.appendChild(row);
    });
    // The uploaded-documents list lives inside the collapsible logDocBody,
    // whose max-height is otherwise only recalculated on toggle click. If
    // the panel is already open (e.g. right after an upload) and this call
    // adds/changes rows, the old max-height would clip the new content —
    // so resync it here whenever the panel is open.
    if (logDocOpen) {
      logDocBody.style.maxHeight = logDocBody.scrollHeight + 'px';
    }
  }

  function loadUploadedDocuments() {
    if (!selectedActivity) {
      logDocUploadedList.innerHTML = '';
      return;
    }
    fetch('ajax/list_logistic_documents.php?activity_id=' + selectedActivity.id)
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) renderUploadedDocuments(res.data);
      })
      .catch(function (e) { console.error(e); });
  }

  document.getElementById('btnLogDocUpload').addEventListener('click', function () {
    logDocMsg.style.display = 'none';

    if (!selectedActivity) {
      logDocMsg.textContent = 'Select an Activity Code first.';
      logDocMsg.style.color = 'var(--danger)';
      logDocMsg.style.display = 'block';
      return;
    }
    if (!logDocName.value.trim()) {
      logDocMsg.textContent = 'Document name is required.';
      logDocMsg.style.color = 'var(--danger)';
      logDocMsg.style.display = 'block';
      return;
    }
    if (!logDocFile.files.length) {
      logDocMsg.textContent = 'Choose a file to upload.';
      logDocMsg.style.color = 'var(--danger)';
      logDocMsg.style.display = 'block';
      return;
    }

    var fd = new FormData();
    fd.append('activity_id', selectedActivity.id);
    fd.append('document_type', logDocType.value);
    fd.append('document_name', logDocName.value.trim());
    fd.append('document_date', logDocDate.value);
    fd.append('file', logDocFile.files[0]);

    fetch('ajax/upload_logistic_document.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          logDocMsg.textContent = 'Document uploaded.';
          logDocMsg.style.color = 'var(--success)';
          logDocMsg.style.display = 'block';

          loadUploadedDocuments();

          // Reset the form so it's ready for the next document right away.
          logDocType.value = 'shipper';
          logDocName.value = '';
          logDocDate.value = '';
          logDocFile.value = '';
        } else {
          logDocMsg.textContent = res.message || 'Failed to upload document.';
          logDocMsg.style.color = 'var(--danger)';
          logDocMsg.style.display = 'block';
        }
      })
      .catch(function () {
        logDocMsg.textContent = 'Connection error.';
        logDocMsg.style.color = 'var(--danger)';
        logDocMsg.style.display = 'block';
      });
  });

  // ---------- Add Document (from Logistic List row) ----------
  var logAddDocOverlay      = document.getElementById('logAddDocOverlay');
  var logAddDocTitle        = document.getElementById('logAddDocTitle');
  var logAddDocType         = document.getElementById('logAddDocType');
  var logAddDocName         = document.getElementById('logAddDocName');
  var logAddDocDate         = document.getElementById('logAddDocDate');
  var logAddDocFile         = document.getElementById('logAddDocFile');
  var logAddDocMsg          = document.getElementById('logAddDocMsg');
  var logAddDocUploadedList = document.getElementById('logAddDocUploadedList');
  var addDocActivityId      = null; // activity_id of the logistic row currently open in this modal

  function renderAddDocUploadedList(docs) {
    logAddDocUploadedList.innerHTML = '';
    docs.forEach(function (d) {
      var row = document.createElement('div');
      row.className = 'accordion-row';
      row.style.cssText = 'background:var(--bg-surface); border-radius:var(--radius-sm); padding:var(--space-2) var(--space-3); display:flex; justify-content:space-between; gap:var(--space-2);';

      var left = document.createElement('span');
      left.textContent = '[' + d.document_type + '] ' + d.document_name;

      var right = document.createElement('span');
      right.style.color = 'var(--text-muted)';
      right.textContent = d.document_date || '';

      row.appendChild(left);
      row.appendChild(right);
      logAddDocUploadedList.appendChild(row);
    });
  }

  function loadAddDocUploadedList() {
    if (!addDocActivityId) { logAddDocUploadedList.innerHTML = ''; return; }
    fetch('ajax/list_logistic_documents.php?activity_id=' + addDocActivityId)
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res.ok) renderAddDocUploadedList(res.data); })
      .catch(function (e) { console.error(e); });
  }

  function openAddDocument(row) {
    addDocActivityId = row.activity_id;
    logAddDocTitle.textContent = 'Add Document — ' + row.activity_name + ' (' + row.activity_code + ')';
    logAddDocType.value = 'shipper';
    logAddDocName.value = '';
    logAddDocDate.value = '';
    logAddDocFile.value = '';
    logAddDocMsg.style.display = 'none';
    loadAddDocUploadedList();
    show(logAddDocOverlay);
  }

  document.getElementById('btnLogAddDocClose').addEventListener('click', function () {
    hide(logAddDocOverlay);
    addDocActivityId = null;
    loadLogisticList(); // refresh document counts shown on the row
  });

  document.getElementById('btnLogAddDocUpload').addEventListener('click', function () {
    logAddDocMsg.style.display = 'none';

    if (!addDocActivityId) return;
    if (!logAddDocName.value.trim()) {
      logAddDocMsg.textContent = 'Document name is required.';
      logAddDocMsg.style.color = 'var(--danger)';
      logAddDocMsg.style.display = 'block';
      return;
    }
    if (!logAddDocFile.files.length) {
      logAddDocMsg.textContent = 'Choose a file to upload.';
      logAddDocMsg.style.color = 'var(--danger)';
      logAddDocMsg.style.display = 'block';
      return;
    }

    var fd = new FormData();
    fd.append('activity_id', addDocActivityId);
    fd.append('document_type', logAddDocType.value);
    fd.append('document_name', logAddDocName.value.trim());
    fd.append('document_date', logAddDocDate.value);
    fd.append('file', logAddDocFile.files[0]);

    fetch('ajax/upload_logistic_document.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          logAddDocMsg.textContent = 'Document uploaded.';
          logAddDocMsg.style.color = 'var(--success)';
          logAddDocMsg.style.display = 'block';

          loadAddDocUploadedList();

          logAddDocType.value = 'shipper';
          logAddDocName.value = '';
          logAddDocDate.value = '';
          logAddDocFile.value = '';
        } else {
          logAddDocMsg.textContent = res.message || 'Failed to upload document.';
          logAddDocMsg.style.color = 'var(--danger)';
          logAddDocMsg.style.display = 'block';
        }
      })
      .catch(function () {
        logAddDocMsg.textContent = 'Connection error.';
        logAddDocMsg.style.color = 'var(--danger)';
        logAddDocMsg.style.display = 'block';
      });
  });

  // ---------- Primary / Secondary units: loaded live from the server ----------
  var logPrimaryQty      = document.getElementById('logPrimaryQty');
  var logPrimaryUnit     = document.getElementById('logPrimaryUnit');
  var logPrimaryWeight   = document.getElementById('logPrimaryWeight');
  var logSecondaryQty    = document.getElementById('logSecondaryQty');
  var logSecondaryUnit   = document.getElementById('logSecondaryUnit');
  var logSecondaryWeight = document.getElementById('logSecondaryWeight');

  function fillUnitSelect(select, units, kind) {
    var prevValue = select.value;
    select.innerHTML = '<option value="">-- select unit --</option>';
    units.forEach(function (u) {
      var opt = document.createElement('option');
      opt.value = String(u.id);
      opt.setAttribute('data-label', u.label);
      opt.setAttribute('data-weight', u.weight_kg);
      if (kind === 'secondary') {
        opt.setAttribute('data-ratio', u.ratio_per_primary);
        opt.textContent = u.label + ' (1 primary = ' + u.ratio_per_primary + ' ' + u.label + ' @ ' + u.weight_kg + ' kg)';
      } else {
        opt.textContent = u.label + ' @ ' + u.weight_kg + ' kg';
      }
      select.appendChild(opt);
    });
    // Keep the previous selection if that unit still exists after a manage-window edit.
    if (units.some(function (u) { return String(u.id) === prevValue; })) select.value = prevValue;
  }

  function loadUnits(kind, select, callback) {
    fetch('ajax/manage_packaging_units.php?kind=' + kind + '&action=list')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          fillUnitSelect(select, res.units, kind);
          if (callback) callback(res.units);
        }
      })
      .catch(function (e) { console.error(e); });
  }

  function updatePackagingAvailability() {
    var enabled = !!selectedActivity;
    [logPrimaryQty, logPrimaryUnit, logSecondaryQty, logSecondaryUnit].forEach(function (el) { el.disabled = !enabled; });
    if (enabled) updateQtySourceLock(); // re-apply whichever qty is currently the source
  }

  // Qty can now flow either direction: Primary → Secondary (default) or
  // Secondary → Primary. Whichever field the user starts typing into
  // becomes the "source" and locks (disables) the other qty field for as
  // long as it has a value, so there's never ambiguity about which one
  // drives the calculation. Clearing the source field unlocks both again.
  var qtySource = null; // 'primary' | 'secondary' | null

  function updateQtySourceLock() {
    if (qtySource === 'primary') {
      logSecondaryQty.disabled = true;
    } else if (qtySource === 'secondary') {
      logPrimaryQty.disabled = true;
    } else {
      logPrimaryQty.disabled = !selectedActivity;
      logSecondaryQty.disabled = !selectedActivity;
    }
  }

  function recalcPackaging() {
    var pOpt = logPrimaryUnit.selectedOptions[0];
    var pWeight = pOpt ? parseFloat(pOpt.getAttribute('data-weight')) : NaN;

    var sOpt = logSecondaryUnit.selectedOptions[0];
    var ratio = sOpt ? parseFloat(sOpt.getAttribute('data-ratio')) : NaN;
    var sWeight = sOpt ? parseFloat(sOpt.getAttribute('data-weight')) : NaN;

    var primaryQty, secondaryQty;

    if (qtySource === 'secondary') {
      var secQtyInput = parseNumberInput(logSecondaryQty.value);
      if (!isNaN(secQtyInput) && !isNaN(ratio) && ratio !== 0 && logSecondaryUnit.value !== '') {
        secondaryQty = secQtyInput;
        primaryQty = secQtyInput / ratio;
        logPrimaryQty.value = formatNumberInput(primaryQty.toFixed(3).replace(/\.?0+$/, ''));
      } else {
        primaryQty = NaN;
        secondaryQty = secQtyInput;
        logPrimaryQty.value = '';
      }
    } else {
      // qtySource is 'primary' or null — Primary drives Secondary, same as before.
      var priQtyInput = parseNumberInput(logPrimaryQty.value);
      primaryQty = priQtyInput;
      if (!isNaN(priQtyInput) && !isNaN(ratio) && logSecondaryUnit.value !== '') {
        secondaryQty = priQtyInput * ratio;
        logSecondaryQty.value = formatNumberInput(secondaryQty.toFixed(2).replace(/\.?0+$/, ''));
      } else {
        secondaryQty = NaN;
        logSecondaryQty.value = '';
      }
    }

    if (!isNaN(primaryQty) && !isNaN(pWeight) && logPrimaryUnit.value !== '') {
      logPrimaryWeight.value = formatNumberInput((primaryQty * pWeight).toFixed(3).replace(/\.?0+$/, ''));
    } else {
      logPrimaryWeight.value = '';
    }

    if (!isNaN(secondaryQty) && !isNaN(sWeight) && logSecondaryUnit.value !== '') {
      logSecondaryWeight.value = formatNumberInput((secondaryQty * sWeight).toFixed(3).replace(/\.?0+$/, ''));
    } else {
      logSecondaryWeight.value = '';
    }
  }

  logPrimaryQty.addEventListener('input', function () {
    qtySource = logPrimaryQty.value.trim() === '' ? null : 'primary';
    updateQtySourceLock();
    recalcPackaging();
  });
  logSecondaryQty.addEventListener('input', function () {
    qtySource = logSecondaryQty.value.trim() === '' ? null : 'secondary';
    updateQtySourceLock();
    recalcPackaging();
  });
  logPrimaryUnit.addEventListener('change', recalcPackaging);
  logSecondaryUnit.addEventListener('change', recalcPackaging);

  // ---------- Manage Primary/Secondary Units fly windows ----------
  var managePrimaryUnitsOverlay   = document.getElementById('managePrimaryUnitsOverlay');
  var manageSecondaryUnitsOverlay = document.getElementById('manageSecondaryUnitsOverlay');

  // The response is read as TEXT first and parsed by hand. r.json() throws
  // on anything that isn't valid JSON (a PHP notice/warning printed before
  // the payload, an HTML error page, a truncated response), and since the
  // callers below had no .catch() that rejection was swallowed silently —
  // the unit really was saved/deleted server-side, but the list never
  // re-rendered and no message appeared, so it looked like the button did
  // nothing at all. Now every outcome resolves to an {ok, message} object,
  // so there is always visible feedback.
  function unitRequest(kind, action, extra) {
    var payload = Object.assign({ kind: kind, action: action }, extra || {});
    return fetch('ajax/manage_packaging_units.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(payload).toString()
    })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        try {
          return JSON.parse(text);
        } catch (e) {
          console.error('manage_packaging_units.php returned non-JSON:', text);
          return { ok: false, message: 'Unexpected server response. Check the browser console.' };
        }
      })
      .catch(function (err) {
        console.error('unitRequest failed:', err);
        return { ok: false, message: 'Could not reach the server.' };
      });
  }

  // Single place that renders Manage Units feedback, so success and failure
  // always look the same and always end up on screen (the message element
  // sits under the unit list, which can be pushed out of view once the list
  // gets long — hence the scrollIntoView).
  var unitFeedbackTimer = { primary: null, secondary: null };
  function showUnitFeedback(kind, message, isSuccess) {
    var errEl = document.getElementById(kind + 'UnitError');
    if (!errEl) return;
    if (unitFeedbackTimer[kind]) clearTimeout(unitFeedbackTimer[kind]);
    errEl.textContent = message;
    errEl.style.color = isSuccess ? 'var(--success)' : 'var(--danger)';
    errEl.style.display = 'block';
    errEl.scrollIntoView({ block: 'nearest' });
    if (isSuccess) {
      unitFeedbackTimer[kind] = setTimeout(function () {
        errEl.style.display = 'none';
        errEl.style.color = 'var(--danger)';
      }, 2000);
    }
  }

  function renderUnitManageList(kind, listEl, units) {
    listEl.innerHTML = '';
    units.forEach(function (u) {
      var row = document.createElement('div');
      row.className = 'card log-unit-row';

      var labelSpan = document.createElement('div');
      labelSpan.style.flex = '1';
      labelSpan.textContent = kind === 'secondary'
        ? u.label + ' — 1 primary = ' + u.ratio_per_primary + ' @ ' + u.weight_kg + ' kg'
        : u.label + ' @ ' + u.weight_kg + ' kg';
      row.appendChild(labelSpan);

      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-secondary';
      editBtn.style.cssText = 'padding:var(--space-2) var(--space-3); font-size:var(--text-sm);';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', function () { startEditUnitRow(kind, row, u); });
      row.appendChild(editBtn);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn btn-danger';
      delBtn.style.cssText = 'padding:var(--space-2) var(--space-3); font-size:var(--text-sm);';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', function () { deleteUnit(kind, u); });
      row.appendChild(delBtn);

      listEl.appendChild(row);
    });
  }

  function startEditUnitRow(kind, row, u) {
    row.innerHTML = '';
    row.style.flexWrap = 'wrap';

    var labelInput = document.createElement('input');
    labelInput.type = 'text';
    labelInput.className = 'input';
    labelInput.value = u.label;
    labelInput.style.flex = '1';
    row.appendChild(labelInput);

    var ratioInput = null;
    if (kind === 'secondary') {
      ratioInput = document.createElement('input');
      ratioInput.type = 'text';
      ratioInput.inputMode = 'decimal';
      ratioInput.className = 'input input-number-comma';
      ratioInput.value = formatNumberInput(String(u.ratio_per_primary));
      ratioInput.style.width = '110px';
      initNumberCommaInput(ratioInput);
      row.appendChild(ratioInput);
    }

    var weightInput = document.createElement('input');
    weightInput.type = 'text';
    weightInput.inputMode = 'decimal';
    weightInput.className = 'input input-number-comma';
    weightInput.value = formatNumberInput(String(u.weight_kg));
    weightInput.style.width = '110px';
    initNumberCommaInput(weightInput);
    row.appendChild(weightInput);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn-primary';
    saveBtn.textContent = 'Save';
    saveBtn.addEventListener('click', function () {
      var errEl = document.getElementById(kind + 'UnitError');
      errEl.style.display = 'none';
      var payload = { id: u.id, label: labelInput.value.trim(), weight_kg: String(parseNumberInput(weightInput.value)) };
      if (kind === 'secondary') payload.ratio_per_primary = String(parseNumberInput(ratioInput.value));

      var listEl = row.parentElement;
      unitRequest(kind, 'edit', payload).then(function (res) {
        if (res.ok) {
          renderUnitManageList(kind, listEl || document.getElementById(kind + 'UnitList'), res.units);
          refreshUnitSelects();
          showUnitFeedback(kind, 'Unit updated.', true);
        } else {
          showUnitFeedback(kind, res.message || 'Failed to save.', false);
        }
      });
    });
    row.appendChild(saveBtn);

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn btn-secondary';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', function () { loadUnitManageList(kind); });
    row.appendChild(cancelBtn);
  }

  function deleteUnit(kind, u) {
    if (!confirm('Delete unit "' + u.label + '"?')) return;
    var errEl = document.getElementById(kind + 'UnitError');
    errEl.style.display = 'none';
    unitRequest(kind, 'delete', { id: u.id }).then(function (res) {
      if (res.ok) {
        renderUnitManageList(kind, document.getElementById(kind + 'UnitList'), res.units);
        refreshUnitSelects();
        showUnitFeedback(kind, 'Unit deleted.', true);
      } else {
        showUnitFeedback(kind, res.message || 'Failed to delete.', false);
      }
    });
  }

  function loadUnitManageList(kind) {
    var errEl = document.getElementById(kind + 'UnitError');
    errEl.style.display = 'none';
    unitRequest(kind, 'list').then(function (res) {
      if (res.ok) {
        renderUnitManageList(kind, document.getElementById(kind + 'UnitList'), res.units);
      } else {
        showUnitFeedback(kind, res.message || 'Failed to load units.', false);
      }
    });
  }

  function refreshUnitSelects() {
    loadUnits('primary', logPrimaryUnit, recalcPackaging);
    loadUnits('secondary', logSecondaryUnit, recalcPackaging);
  }

  // ---------- Logistic List: Edit / Delete (reverify-gated) ----------
  var logReverifyOverlay   = document.getElementById('logReverifyOverlay');
  var logReverifyPassword  = document.getElementById('logReverifyPassword');
  var logReverifyError     = document.getElementById('logReverifyError');
  var logEditOverlay       = document.getElementById('logEditOverlay');
  var logDeleteOverlay     = document.getElementById('logDeleteOverlay');
  var pendingLogisticAction = null; // 'edit' | 'delete'
  var pendingLogisticRow    = null; // the row object from list_logistics.php

  function openLogisticReverify(action, row) {
    pendingLogisticAction = action;
    pendingLogisticRow = row;
    logReverifyPassword.value = '';
    logReverifyError.style.display = 'none';
    show(logReverifyOverlay);
    logReverifyPassword.focus();
  }

  document.getElementById('btnLogReverifyCancel').addEventListener('click', function () {
    hide(logReverifyOverlay);
    pendingLogisticAction = null;
    pendingLogisticRow = null;
  });

  function submitReverify() {
    var pwd = logReverifyPassword.value;
    logReverifyError.style.display = 'none';
    if (!pwd) {
      logReverifyError.textContent = 'Password wajib diisi.';
      logReverifyError.style.display = 'block';
      return;
    }
    fetch('ajax/verify_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ password: pwd }).toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) {
          logReverifyError.textContent = res.message || 'Password salah.';
          logReverifyError.style.display = 'block';
          return;
        }
        hide(logReverifyOverlay);
        if (pendingLogisticAction === 'edit') {
          openEditLogistic(pendingLogisticRow);
        } else if (pendingLogisticAction === 'delete') {
          openDeleteLogistic(pendingLogisticRow);
        }
      })
      .catch(function () {
        logReverifyError.textContent = 'Gagal menghubungi server.';
        logReverifyError.style.display = 'block';
      });
  }

  document.getElementById('btnLogReverifyConfirm').addEventListener('click', submitReverify);
  logReverifyPassword.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); submitReverify(); }
  });

  // --- Edit Logistic ---
  var logEditActivityLabel = document.getElementById('logEditActivityLabel');
  var logEditIncomingDate  = document.getElementById('logEditIncomingDate');
  var logEditPrimaryQty    = document.getElementById('logEditPrimaryQty');
  var logEditPrimaryUnit   = document.getElementById('logEditPrimaryUnit');
  var logEditPrimaryWeight = document.getElementById('logEditPrimaryWeight');
  var logEditSecondaryQty    = document.getElementById('logEditSecondaryQty');
  var logEditSecondaryUnit   = document.getElementById('logEditSecondaryUnit');
  var logEditSecondaryWeight = document.getElementById('logEditSecondaryWeight');
  var logEditError = document.getElementById('logEditError');
  var editQtySource = null; // mirrors qtySource, but scoped to the Edit modal

  // Info icons inside the Edit modal — same click-to-toggle pattern as the
  // Create form's.
  [['logEditPrimaryInfoIcon', 'logEditPrimaryInfoTooltip'], ['logEditSecondaryInfoIcon', 'logEditSecondaryInfoTooltip']].forEach(function (pair) {
    var icon = document.getElementById(pair[0]);
    var tooltip = document.getElementById(pair[1]);
    icon.addEventListener('click', function () { tooltip.classList.toggle('open'); });
    icon.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tooltip.classList.toggle('open'); }
    });
  });

  function selectUnitByLabel(select, label) {
    var match = null;
    Array.prototype.forEach.call(select.options, function (opt) {
      if (opt.getAttribute('data-label') === label) match = opt;
    });
    select.value = match ? match.value : '';
  }

  function recalcEditPackaging() {
    var pOpt = logEditPrimaryUnit.selectedOptions[0];
    var pWeight = pOpt ? parseFloat(pOpt.getAttribute('data-weight')) : NaN;

    var sOpt = logEditSecondaryUnit.selectedOptions[0];
    var ratio = sOpt ? parseFloat(sOpt.getAttribute('data-ratio')) : NaN;
    var sWeight = sOpt ? parseFloat(sOpt.getAttribute('data-weight')) : NaN;

    var primaryQty, secondaryQty;

    if (editQtySource === 'secondary') {
      var secQtyInput = parseNumberInput(logEditSecondaryQty.value);
      if (!isNaN(secQtyInput) && !isNaN(ratio) && ratio !== 0 && logEditSecondaryUnit.value !== '') {
        secondaryQty = secQtyInput;
        primaryQty = secQtyInput / ratio;
        logEditPrimaryQty.value = formatNumberInput(primaryQty.toFixed(3).replace(/\.?0+$/, ''));
      } else {
        primaryQty = NaN;
        secondaryQty = secQtyInput;
        logEditPrimaryQty.value = '';
      }
    } else {
      var priQtyInput = parseNumberInput(logEditPrimaryQty.value);
      primaryQty = priQtyInput;
      if (!isNaN(priQtyInput) && !isNaN(ratio) && logEditSecondaryUnit.value !== '') {
        secondaryQty = priQtyInput * ratio;
        logEditSecondaryQty.value = formatNumberInput(secondaryQty.toFixed(2).replace(/\.?0+$/, ''));
      } else {
        secondaryQty = NaN;
        logEditSecondaryQty.value = '';
      }
    }

    if (!isNaN(primaryQty) && !isNaN(pWeight) && logEditPrimaryUnit.value !== '') {
      logEditPrimaryWeight.value = formatNumberInput((primaryQty * pWeight).toFixed(3).replace(/\.?0+$/, ''));
    } else {
      logEditPrimaryWeight.value = '';
    }

    if (!isNaN(secondaryQty) && !isNaN(sWeight) && logEditSecondaryUnit.value !== '') {
      logEditSecondaryWeight.value = formatNumberInput((secondaryQty * sWeight).toFixed(3).replace(/\.?0+$/, ''));
    } else {
      logEditSecondaryWeight.value = '';
    }
  }

  logEditPrimaryQty.addEventListener('input', function () {
    editQtySource = logEditPrimaryQty.value.trim() === '' ? null : 'primary';
    logEditSecondaryQty.disabled = (editQtySource === 'primary');
    recalcEditPackaging();
  });
  logEditSecondaryQty.addEventListener('input', function () {
    editQtySource = logEditSecondaryQty.value.trim() === '' ? null : 'secondary';
    logEditPrimaryQty.disabled = (editQtySource === 'secondary');
    recalcEditPackaging();
  });
  logEditPrimaryUnit.addEventListener('change', recalcEditPackaging);
  logEditSecondaryUnit.addEventListener('change', recalcEditPackaging);
  initNumberCommaInput(logEditPrimaryQty);
  initNumberCommaInput(logEditSecondaryQty);

  function openEditLogistic(row) {
    logEditError.style.display = 'none';
    editQtySource = null;
    logEditPrimaryQty.disabled = false;
    logEditSecondaryQty.disabled = false;

    logEditActivityLabel.value = row.activity_code + ' — ' + row.activity_name;
    logEditIncomingDate.value = row.incoming_date || '';

    Promise.all([
      new Promise(function (resolve) {
        fetch('ajax/manage_packaging_units.php?kind=primary&action=list')
          .then(function (r) { return r.json(); })
          .then(function (res) { if (res.ok) fillUnitSelect(logEditPrimaryUnit, res.units, 'primary'); resolve(); })
          .catch(function () { resolve(); });
      }),
      new Promise(function (resolve) {
        fetch('ajax/manage_packaging_units.php?kind=secondary&action=list')
          .then(function (r) { return r.json(); })
          .then(function (res) { if (res.ok) fillUnitSelect(logEditSecondaryUnit, res.units, 'secondary'); resolve(); })
          .catch(function () { resolve(); });
      })
    ]).then(function () {
      selectUnitByLabel(logEditPrimaryUnit, row.primary_unit_label);
      selectUnitByLabel(logEditSecondaryUnit, row.secondary_unit_label);
      logEditPrimaryQty.value = row.primary_qty !== null ? formatNumberInput(String(row.primary_qty)) : '';
      logEditSecondaryQty.value = '';
      recalcEditPackaging();
      show(logEditOverlay);
    });
  }

  document.getElementById('btnLogEditCancel').addEventListener('click', function () {
    hide(logEditOverlay);
    pendingLogisticRow = null;
  });

  document.getElementById('btnLogEditSave').addEventListener('click', function () {
    logEditError.style.display = 'none';
    if (!pendingLogisticRow) return;

    var pOpt = logEditPrimaryUnit.selectedOptions[0];
    var sOpt = logEditSecondaryUnit.selectedOptions[0];
    var primaryQtyClean = parseNumberInput(logEditPrimaryQty.value);

    var payload = new URLSearchParams({
      id: pendingLogisticRow.id,
      incoming_date: logEditIncomingDate.value,
      primary_qty: isNaN(primaryQtyClean) ? '' : String(primaryQtyClean),
      primary_unit_label: pOpt ? (pOpt.getAttribute('data-label') || '') : '',
      primary_unit_weight_kg: pOpt ? (pOpt.getAttribute('data-weight') || '') : '',
      secondary_unit_label: sOpt ? (sOpt.getAttribute('data-label') || '') : '',
      secondary_unit_weight_kg: sOpt ? (sOpt.getAttribute('data-weight') || '') : '',
      secondary_ratio_per_primary: sOpt ? (sOpt.getAttribute('data-ratio') || '') : ''
    });

    fetch('ajax/update_logistic.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success === false) {
          // Reverify window expired server-side between opening this modal
          // and hitting Save — send the user back through the password gate.
          hide(logEditOverlay);
          openLogisticReverify('edit', pendingLogisticRow);
          return;
        }
        if (!res.ok) {
          logEditError.textContent = res.message || 'Gagal menyimpan perubahan.';
          logEditError.style.display = 'block';
          return;
        }
        hide(logEditOverlay);
        pendingLogisticRow = null;
        loadLogisticList();
      })
      .catch(function () {
        logEditError.textContent = 'Gagal menghubungi server.';
        logEditError.style.display = 'block';
      });
  });

  // --- Delete Logistic ---
  var logDeleteWarning = document.getElementById('logDeleteWarning');
  var logDeleteError   = document.getElementById('logDeleteError');

  function openDeleteLogistic(row) {
    logDeleteError.style.display = 'none';
    var docTotal = row.documents.shipper + row.documents.custom + row.documents.consignee;
    logDeleteWarning.textContent = 'Delete logistic for "' + row.activity_name + '" (' + row.activity_code + ')? '
      + (docTotal > 0
          ? 'All ' + docTotal + ' uploaded document(s) will be moved to the recycle bin.'
          : 'No documents were uploaded for this logistic.')
      + ' This cannot be undone from here.';
    show(logDeleteOverlay);
  }

  document.getElementById('btnLogDeleteCancel').addEventListener('click', function () {
    hide(logDeleteOverlay);
    pendingLogisticRow = null;
  });

  document.getElementById('btnLogDeleteConfirm').addEventListener('click', function () {
    logDeleteError.style.display = 'none';
    if (!pendingLogisticRow) return;

    fetch('ajax/delete_logistic.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id: pendingLogisticRow.id }).toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success === false) {
          hide(logDeleteOverlay);
          openLogisticReverify('delete', pendingLogisticRow);
          return;
        }
        if (!res.ok) {
          logDeleteError.textContent = res.message || 'Gagal menghapus logistic.';
          logDeleteError.style.display = 'block';
          return;
        }
        hide(logDeleteOverlay);
        pendingLogisticRow = null;
        loadLogisticList();
      })
      .catch(function () {
        logDeleteError.textContent = 'Gagal menghubungi server.';
        logDeleteError.style.display = 'block';
      });
  });

  document.getElementById('btnManagePrimaryUnits').addEventListener('click', function () {
    loadUnitManageList('primary');
    document.getElementById('primaryUnitNewLabel').value = '';
    document.getElementById('primaryUnitNewWeight').value = '';
    show(managePrimaryUnitsOverlay);
  });
  document.getElementById('btnClosePrimaryUnits').addEventListener('click', function () {
    hide(managePrimaryUnitsOverlay);
    refreshUnitSelects();
  });
  document.getElementById('btnPrimaryUnitAdd').addEventListener('click', function () {
    var errEl = document.getElementById('primaryUnitError');
    errEl.style.display = 'none';
    var label = document.getElementById('primaryUnitNewLabel').value.trim();
    var weightRaw = document.getElementById('primaryUnitNewWeight').value;
    var weight = isNaN(parseNumberInput(weightRaw)) ? '' : String(parseNumberInput(weightRaw));
    if (!label || weight === '') return;
    unitRequest('primary', 'add', { label: label, weight_kg: weight }).then(function (res) {
      if (res.ok) {
        document.getElementById('primaryUnitNewLabel').value = '';
        document.getElementById('primaryUnitNewWeight').value = '';
        renderUnitManageList('primary', document.getElementById('primaryUnitList'), res.units);
        refreshUnitSelects();
        showUnitFeedback('primary', 'Unit added.', true);
      } else {
        showUnitFeedback('primary', res.message || 'Failed to add.', false);
      }
    });
  });

  document.getElementById('btnManageSecondaryUnits').addEventListener('click', function () {
    loadUnitManageList('secondary');
    document.getElementById('secondaryUnitNewLabel').value = '';
    document.getElementById('secondaryUnitNewRatio').value = '';
    document.getElementById('secondaryUnitNewWeight').value = '';
    show(manageSecondaryUnitsOverlay);
  });
  document.getElementById('btnCloseSecondaryUnits').addEventListener('click', function () {
    hide(manageSecondaryUnitsOverlay);
    refreshUnitSelects();
  });
  document.getElementById('btnSecondaryUnitAdd').addEventListener('click', function () {
    var errEl = document.getElementById('secondaryUnitError');
    errEl.style.display = 'none';
    var label = document.getElementById('secondaryUnitNewLabel').value.trim();
    var ratioRaw = document.getElementById('secondaryUnitNewRatio').value;
    var weightRaw = document.getElementById('secondaryUnitNewWeight').value;
    var ratio = isNaN(parseNumberInput(ratioRaw)) ? '' : String(parseNumberInput(ratioRaw));
    var weight = isNaN(parseNumberInput(weightRaw)) ? '' : String(parseNumberInput(weightRaw));
    if (!label || ratio === '' || weight === '') return;
    unitRequest('secondary', 'add', { label: label, ratio_per_primary: ratio, weight_kg: weight }).then(function (res) {
      if (res.ok) {
        document.getElementById('secondaryUnitNewLabel').value = '';
        document.getElementById('secondaryUnitNewRatio').value = '';
        document.getElementById('secondaryUnitNewWeight').value = '';
        renderUnitManageList('secondary', document.getElementById('secondaryUnitList'), res.units);
        refreshUnitSelects();
        showUnitFeedback('secondary', 'Unit added.', true);
      } else {
        showUnitFeedback('secondary', res.message || 'Failed to add.', false);
      }
    });
  });

  // ---------- Save ----------
  var logCreateError = document.getElementById('logCreateError');

  function resetCreateLogisticForm() {
    selectedActivity = null;
    logActivityCode.value = '';
    document.getElementById('logIncomingDate').value = '';
    logDocName.value = ''; logDocDate.value = ''; logDocFile.value = '';
    logDocUploadedList.innerHTML = '';
    logDocOpen = false; logDocChevron.style.transform = 'rotate(0deg)'; logDocBody.style.maxHeight = '0px';
    logPrimaryQty.value = ''; logPrimaryUnit.value = ''; logPrimaryWeight.value = '';
    logSecondaryUnit.value = ''; logSecondaryQty.value = ''; logSecondaryWeight.value = '';
    qtySource = null;
    updateQtySourceLock();
    logCreateError.style.display = 'none';
    updatePackagingAvailability();
    loadActivityCodes();
  }

  document.getElementById('btnLogCreateSubmit').addEventListener('click', function () {
    logCreateError.style.display = 'none';

    if (!selectedActivity) {
      logCreateError.textContent = 'Select an Activity Code first.';
      logCreateError.style.display = 'block';
      return;
    }

    var pOpt = logPrimaryUnit.selectedOptions[0];
    var sOpt = logSecondaryUnit.selectedOptions[0];

    // Only rates are sent — totals (weight, secondary qty) are calculated
    // by the server when the Logistic List is loaded, never stored as-is.
    // primary_qty is read through parseNumberInput() to strip the comma
    // grouping the field displays while typing — create_logistic.php's
    // is_numeric() check would otherwise reject "1,234.5".
    var primaryQtyClean = parseNumberInput(logPrimaryQty.value);
    var payload = new URLSearchParams({
      activity_id: selectedActivity.id,
      incoming_date: document.getElementById('logIncomingDate').value,
      primary_qty: isNaN(primaryQtyClean) ? '' : String(primaryQtyClean),
      primary_unit_label: pOpt ? (pOpt.getAttribute('data-label') || '') : '',
      primary_unit_weight_kg: pOpt ? (pOpt.getAttribute('data-weight') || '') : '',
      secondary_unit_label: sOpt ? (sOpt.getAttribute('data-label') || '') : '',
      secondary_unit_weight_kg: sOpt ? (sOpt.getAttribute('data-weight') || '') : '',
      secondary_ratio_per_primary: sOpt ? (sOpt.getAttribute('data-ratio') || '') : ''
    });

    fetch('ajax/create_logistic.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          resetCreateLogisticForm();
          setActiveLogTab('list');
        } else {
          logCreateError.textContent = res.message || 'Failed to save logistic.';
          logCreateError.style.display = 'block';
        }
      })
      .catch(function () {
        logCreateError.textContent = 'Connection error.';
        logCreateError.style.display = 'block';
      });
  });

  // ---------- Init ----------
  refreshUnitSelects();
  loadActivityCodes();
  loadLogisticList(); // Logistic List is the default tab on entering the menu
})();
</script>