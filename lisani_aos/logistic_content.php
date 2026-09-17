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

    /* Activity code picker: option-like list, not a <select>, so a code
       that already has a logistic can be shown disabled with a distinct
       look instead of just being missing from the list. */
    .log-code-option {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-3);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-sm);
      cursor: pointer;
      font-size: var(--text-sm);
      color: var(--text-primary);
      background: var(--bg-surface);
      box-shadow: 2px 2px 5px var(--shadow-dark), -1px -1px 3px var(--shadow-light);
      transition: box-shadow .15s ease, color .15s ease;
    }
    .log-code-option:hover { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -1px -1px 3px var(--shadow-light); }
    .log-code-option.selected {
      color: var(--accent);
      box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
    }
    .log-code-option.disabled {
      cursor: not-allowed;
      color: var(--text-disabled);
      background: var(--bg-recessed);
      box-shadow: none;
      opacity: 0.6;
    }

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
      <div id="logActivityCodeList" style="display:flex; flex-direction:column; gap:var(--space-2); max-height:280px; overflow-y:auto; padding:var(--space-1);"></div>
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
            <input type="text" class="input" id="logDocName" placeholder="e.g. Invoice, Packing List">
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
      <div class="label">Primary Packaging</div>
      <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
        <input type="number" class="input" id="logPrimaryQty" placeholder="Qty" min="0" step="any" style="flex:1; min-width:100px;">
        <select class="select" id="logPrimaryUnit" style="flex:2; min-width:200px;">
          <option value="">-- select unit --</option>
        </select>
        <input type="text" class="input" id="logPrimaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
        <button type="button" class="btn btn-secondary" id="btnManagePrimaryUnits">Edit</button>
      </div>
    </div>

    <div class="form-group">
      <div class="label">Secondary Packaging</div>
      <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
        <input type="text" class="input" id="logSecondaryQty" placeholder="Qty (auto)" disabled style="flex:1; min-width:100px;">
        <select class="select" id="logSecondaryUnit" style="flex:2; min-width:200px;">
          <option value="">-- select unit --</option>
        </select>
        <input type="text" class="input" id="logSecondaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
        <button type="button" class="btn btn-secondary" id="btnManageSecondaryUnits">Edit</button>
      </div>
      <div class="empty-sub">Quantity is auto-calculated from Primary Packaging qty × the selected unit's ratio.</div>
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
        <input type="text" class="input" id="primaryUnitNewLabel" placeholder="e.g. Master Carton">
      </div>
      <div class="form-group">
        <div class="label">Weight per unit (KG)</div>
        <input type="number" class="input" id="primaryUnitNewWeight" step="any" min="0">
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
        <input type="text" class="input" id="secondaryUnitNewLabel" placeholder="e.g. Baby Carton">
      </div>
      <div class="form-group">
        <div class="label">1 Primary unit = how many of this unit</div>
        <input type="number" class="input" id="secondaryUnitNewRatio" step="any" min="0">
      </div>
      <div class="form-group">
        <div class="label">Weight per unit (KG)</div>
        <input type="number" class="input" id="secondaryUnitNewWeight" step="any" min="0">
      </div>
      <button type="button" class="btn btn-primary" id="btnSecondaryUnitAdd" style="width:100%;">Add Unit</button>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnCloseSecondaryUnits">Close</button>
    </div>
  </div>
</div>

</div>

<script>
(function () {
  function show(el) { el.style.display = 'flex'; }
  function hide(el) { el.style.display = 'none'; }

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
  var logActivityCodeList = document.getElementById('logActivityCodeList');
  var logActivityCodeEmpty= document.getElementById('logActivityCodeEmpty');
  var selectedActivity    = null; // { id, activity_code, activity_name, relative_path }

  function loadActivityCodes() {
    var dept = logDepartment.value;
    selectedActivity = null;
    logActivityCodeList.innerHTML = '';
    updatePackagingAvailability();

    var deptOption = logDepartment.selectedOptions[0];
    if (deptOption && deptOption.getAttribute('data-unsupported')) {
      logActivityCodeEmpty.textContent = 'Logistic is not available for this department yet.';
      logActivityCodeEmpty.style.display = 'block';
      return;
    }

    fetch('ajax/list_logistic_activities.php?department=' + encodeURIComponent(dept))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) { console.warn(res.message); return; }
        logActivityCodeEmpty.style.display = res.data.length ? 'none' : 'block';
        logActivityCodeEmpty.textContent = 'No activity codes for this department yet.';

        res.data.forEach(function (a) {
          var opt = document.createElement('div');
          opt.className = 'log-code-option' + (a.has_logistic ? ' disabled' : '');
          opt.textContent = a.activity_code + ' — ' + a.activity_name + (a.has_logistic ? ' (logistic already exists)' : '');
          if (!a.has_logistic) {
            opt.addEventListener('click', function () {
              selectedActivity = a;
              logActivityCodeList.querySelectorAll('.log-code-option').forEach(function (el) {
                el.classList.remove('selected');
              });
              opt.classList.add('selected');
            });
          }
          logActivityCodeList.appendChild(opt);
        });
      })
      .catch(function (e) { console.error(e); });
  }

  logDepartment.addEventListener('change', loadActivityCodes);

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

          var row = document.createElement('div');
          row.className = 'accordion-row';
          row.style.cssText = 'background:var(--bg-surface); border-radius:var(--radius-sm); padding:var(--space-2) var(--space-3);';
          row.textContent = '[' + res.data.document_type + '] ' + res.data.document_name;
          logDocUploadedList.appendChild(row);

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
    [logPrimaryQty, logPrimaryUnit, logSecondaryUnit].forEach(function (el) { el.disabled = !enabled; });
  }

  function recalcPackaging() {
    var qty = parseFloat(logPrimaryQty.value);
    var pOpt = logPrimaryUnit.selectedOptions[0];
    var pWeight = pOpt ? parseFloat(pOpt.getAttribute('data-weight')) : NaN;

    if (!isNaN(qty) && !isNaN(pWeight) && logPrimaryUnit.value !== '') {
      logPrimaryWeight.value = (qty * pWeight).toLocaleString('en-US', { maximumFractionDigits: 3 });
    } else {
      logPrimaryWeight.value = '';
    }

    var sOpt = logSecondaryUnit.selectedOptions[0];
    var ratio = sOpt ? parseFloat(sOpt.getAttribute('data-ratio')) : NaN;
    var sWeight = sOpt ? parseFloat(sOpt.getAttribute('data-weight')) : NaN;

    if (!isNaN(qty) && !isNaN(ratio) && logSecondaryUnit.value !== '') {
      var secQty = qty * ratio;
      logSecondaryQty.value = secQty.toLocaleString('en-US', { maximumFractionDigits: 2 });
      if (!isNaN(sWeight)) {
        logSecondaryWeight.value = (secQty * sWeight).toLocaleString('en-US', { maximumFractionDigits: 3 });
      } else {
        logSecondaryWeight.value = '';
      }
    } else {
      logSecondaryQty.value = '';
      logSecondaryWeight.value = '';
    }
  }

  logPrimaryQty.addEventListener('input', recalcPackaging);
  logPrimaryUnit.addEventListener('change', recalcPackaging);
  logSecondaryUnit.addEventListener('change', recalcPackaging);

  // ---------- Manage Primary/Secondary Units fly windows ----------
  var managePrimaryUnitsOverlay   = document.getElementById('managePrimaryUnitsOverlay');
  var manageSecondaryUnitsOverlay = document.getElementById('manageSecondaryUnitsOverlay');

  function unitRequest(kind, action, extra) {
    var payload = Object.assign({ kind: kind, action: action }, extra || {});
    return fetch('ajax/manage_packaging_units.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(payload).toString()
    }).then(function (r) { return r.json(); });
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
      ratioInput.type = 'number';
      ratioInput.step = 'any';
      ratioInput.className = 'input';
      ratioInput.value = u.ratio_per_primary;
      ratioInput.style.width = '110px';
      row.appendChild(ratioInput);
    }

    var weightInput = document.createElement('input');
    weightInput.type = 'number';
    weightInput.step = 'any';
    weightInput.className = 'input';
    weightInput.value = u.weight_kg;
    weightInput.style.width = '110px';
    row.appendChild(weightInput);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn-primary';
    saveBtn.textContent = 'Save';
    saveBtn.addEventListener('click', function () {
      var errEl = document.getElementById(kind + 'UnitError');
      errEl.style.display = 'none';
      var payload = { id: u.id, label: labelInput.value.trim(), weight_kg: weightInput.value };
      if (kind === 'secondary') payload.ratio_per_primary = ratioInput.value;

      unitRequest(kind, 'edit', payload).then(function (res) {
        if (res.ok) {
          renderUnitManageList(kind, row.parentElement, res.units);
          refreshUnitSelects();
        } else {
          errEl.textContent = res.message || 'Failed to save.';
          errEl.style.display = 'block';
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
      } else {
        errEl.textContent = res.message || 'Failed to delete.';
        errEl.style.display = 'block';
      }
    });
  }

  function loadUnitManageList(kind) {
    var errEl = document.getElementById(kind + 'UnitError');
    errEl.style.display = 'none';
    unitRequest(kind, 'list').then(function (res) {
      if (res.ok) renderUnitManageList(kind, document.getElementById(kind + 'UnitList'), res.units);
    });
  }

  function refreshUnitSelects() {
    loadUnits('primary', logPrimaryUnit, recalcPackaging);
    loadUnits('secondary', logSecondaryUnit, recalcPackaging);
  }

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
    var weight = document.getElementById('primaryUnitNewWeight').value;
    if (!label || weight === '') return;
    unitRequest('primary', 'add', { label: label, weight_kg: weight }).then(function (res) {
      if (res.ok) {
        document.getElementById('primaryUnitNewLabel').value = '';
        document.getElementById('primaryUnitNewWeight').value = '';
        renderUnitManageList('primary', document.getElementById('primaryUnitList'), res.units);
        refreshUnitSelects();
      } else {
        errEl.textContent = res.message || 'Failed to add.';
        errEl.style.display = 'block';
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
    var ratio = document.getElementById('secondaryUnitNewRatio').value;
    var weight = document.getElementById('secondaryUnitNewWeight').value;
    if (!label || ratio === '' || weight === '') return;
    unitRequest('secondary', 'add', { label: label, ratio_per_primary: ratio, weight_kg: weight }).then(function (res) {
      if (res.ok) {
        document.getElementById('secondaryUnitNewLabel').value = '';
        document.getElementById('secondaryUnitNewRatio').value = '';
        document.getElementById('secondaryUnitNewWeight').value = '';
        renderUnitManageList('secondary', document.getElementById('secondaryUnitList'), res.units);
        refreshUnitSelects();
      } else {
        errEl.textContent = res.message || 'Failed to add.';
        errEl.style.display = 'block';
      }
    });
  });

  // ---------- Save ----------
  var logCreateError = document.getElementById('logCreateError');

  function resetCreateLogisticForm() {
    selectedActivity = null;
    logActivityCodeList.querySelectorAll('.log-code-option').forEach(function (el) { el.classList.remove('selected'); });
    document.getElementById('logIncomingDate').value = '';
    logDocName.value = ''; logDocDate.value = ''; logDocFile.value = '';
    logDocUploadedList.innerHTML = '';
    logDocOpen = false; logDocChevron.style.transform = 'rotate(0deg)'; logDocBody.style.maxHeight = '0px';
    logPrimaryQty.value = ''; logPrimaryUnit.value = ''; logPrimaryWeight.value = '';
    logSecondaryUnit.value = ''; logSecondaryQty.value = ''; logSecondaryWeight.value = '';
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
    var payload = new URLSearchParams({
      activity_id: selectedActivity.id,
      incoming_date: document.getElementById('logIncomingDate').value,
      primary_qty: logPrimaryQty.value,
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
