<?php
// lisani_aos/transaction_content.php
// Included from index.php inside:
// <div class="menu-section" data-section="transactions" ...> ... </div>
// Replace that section's inner content with: include 'transaction_content.php';

$departmentsFile = __DIR__ . '/departments.json';
$departmentsData = json_decode(file_get_contents($departmentsFile), true) ?: ['departments' => []];
$departments     = $departmentsData['departments'];
$currentYear     = date('Y');
?>

<!-- ============================================================
     Transactions section wrapper — REQUIRED so footer.php's generic
     [data-target] switcher (which toggles by data-section) can find and
     show/hide this section like every other menu-section.
     ============================================================ -->
<div class="menu-section" data-section="transactions" style="display:none;">

<!-- Default state: shown right after the fly window is dismissed / before
     any action has produced content. -->
<div class="card" id="viewTransactionsEmpty" style="width:100%;">
  <div class="empty-state">
    <i class="ti ti-arrows-exchange"></i>
    <div class="empty-title">Transactions</div>
    <div class="empty-sub">Choose an action to get started.</div>
  </div>
</div>

<!-- Create Activity Code — tabbed: Preview (existing codes) + Create Activity Code (form). Nothing here is a modal. -->
<div class="card" id="viewCreateActivityCode" style="width:100%; display:none;">
  <div class="panel-header">
    <div class="panel-title">Activity Code</div>
    <button type="button" class="btn btn-secondary" id="btnBackToEntryFromForm">
      Back
    </button>
  </div>

  <style>
    #acTabGroup {
      display: flex;
      width: 100%;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      background: none;
      padding: 0;
    }
    #acTabGroup .tab {
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
    #acTabGroup .tab:hover { color: var(--text-primary); }
    #acTabGroup .tab.active {
      background: var(--bg-surface);
      color: var(--accent);
      box-shadow: 5px 5px 10px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
    }
  </style>
  <div class="tab-group" id="acTabGroup">
    <div class="tab active" data-ac-tab="preview">Preview</div>
    <div class="tab" data-ac-tab="create">Create Activity Code</div>
  </div>

  <!-- Tab 1: Preview — list of activity codes already created -->
  <div id="acTabPanelPreview">
    <!-- Closed by default; header shows Activity Name only. Opening one
         collapses any other open item (see toggleAccordionItem() below). -->
    <div class="accordion-list" id="acPreviewList"></div>
    <div class="empty-state" id="acPreviewEmpty" style="display:none;">
      <div class="empty-title">No activity codes yet</div>
      <div class="empty-sub">Switch to the Create Activity Code tab to add one.</div>
    </div>
  </div>

  <!-- Tab 2: Create Activity Code — the form itself -->
  <div id="acTabPanelCreate" style="display:none;">
    <div class="form-group">
      <div class="label">Year</div>
      <input type="number" class="input" id="acYear" value="<?= htmlspecialchars($currentYear) ?>" min="2000" max="2100">
    </div>

    <div class="form-group">
      <div class="label">Department</div>
      <div style="display:flex; gap:var(--space-2);">
        <select class="select" id="acDepartment" style="flex:1;">
          <?php foreach ($departments as $dept): ?>
            <option value="<?= htmlspecialchars($dept['key']) ?>"><?= htmlspecialchars($dept['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-secondary" id="btnManageDepartments">
          Edit
        </button>
      </div>
    </div>

    <div class="form-group">
      <div class="label">Activity Name</div>
      <input type="text" class="input" id="acActivityName" maxlength="150" placeholder="e.g. MAZAFATI I" style="text-transform:uppercase;">
      <div class="empty-sub" id="acActivityNameError" style="display:none; color:var(--danger);">
        This activity name is already used.
      </div>
    </div>

    <div class="form-group">
      <div class="label">Activity Code</div>
      <input type="text" class="input" id="acCodePreview" value="…" disabled>
    </div>

    <div class="form-group">
      <div class="label">Cashflow</div>
      <select class="select" id="acCashflow">
        <option value="inflow">Cash Inflows</option>
        <option value="outflow">Cash Outflows</option>
        <option value="in-out">Cash In-Out</option>
      </select>
    </div>

    <div class="form-group">
      <div class="label">Relative Path (preview)</div>
      <input type="text" class="input" id="acPathPreview" disabled>
    </div>

    <div class="empty-sub" id="txnCreateCodeError" style="display:none; color:var(--danger);"></div>

    <div style="display:flex; gap:var(--space-3); justify-content:flex-end; margin-top:var(--space-5);">
      <button type="button" class="btn btn-primary" id="btnCreateCodeSubmit">Save</button>
    </div>
  </div>
</div>

<!-- Result — also inline, not a modal -->
<div class="card" id="viewActivityCodeResult" style="width:100%; display:none;">
  <div class="panel-header">
    <div class="panel-title">Activity Code Created</div>
  </div>
  <div class="form-group">
    <div class="label">Activity Code</div>
    <input type="text" class="input" id="resActivityCode" disabled>
  </div>
  <div class="form-group">
    <div class="label">Relative Path</div>
    <input type="text" class="input" id="resRelativePath" disabled>
  </div>
  <div style="display:flex; gap:var(--space-3); justify-content:flex-end; margin-top:var(--space-5);">
    <button type="button" class="btn btn-secondary" id="btnResultCreateAnother">Create Another</button>
    <button type="button" class="btn btn-primary" id="btnResultDone">Done</button>
  </div>
</div>

<!-- Customer List — tabbed: Customer List (preview) + New Customer (form). Nothing here is a modal. -->
<div class="card" id="viewCustomerList" style="width:100%; display:none;">
  <div class="panel-header">
    <div class="panel-title">Customer List</div>
    <button type="button" class="btn btn-secondary" id="btnBackToEntryFromCustomer">
      Back
    </button>
  </div>

  <style>
    #clTabGroup {
      display: flex;
      width: 100%;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      background: none;
      padding: 0;
    }
    #clTabGroup .tab {
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
    #clTabGroup .tab:hover { color: var(--text-primary); }
    #clTabGroup .tab.active {
      background: var(--bg-surface);
      color: var(--accent);
      box-shadow: 5px 5px 10px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
    }
  </style>
  <div class="tab-group" id="clTabGroup">
    <div class="tab active" data-cl-tab="preview">Customer List</div>
    <div class="tab" data-cl-tab="create">New Customer</div>
  </div>

  <!-- Tab 1: Customer List — list of customers already created -->
  <div id="clTabPanelPreview">
    <div class="accordion-list" id="clPreviewList"></div>
    <div class="empty-state" id="clPreviewEmpty" style="display:none;">
      <div class="empty-title">No customers yet</div>
      <div class="empty-sub">Switch to the New Customer tab to add one.</div>
    </div>
  </div>

  <!-- Tab 2: New Customer / Edit Customer — same form, reused for both via
       editingCustomerId (null = creating, set = editing that row). -->
  <div id="clTabPanelCreate" style="display:none;">
    <div class="empty-sub" id="clFormModeLabel" style="display:none; color:var(--accent); margin-bottom:var(--space-3);"></div>
    <div class="form-group">
      <div class="label">Year</div>
      <input type="number" class="input" id="clYear" value="<?= htmlspecialchars($currentYear) ?>" min="2000" max="2100">
    </div>

    <div class="form-group">
      <div class="label">Customer Name</div>
      <input type="text" class="input" id="clCustomerName" maxlength="150" placeholder="e.g. JOHN DOE" style="text-transform:uppercase;">
      <div class="empty-sub" id="clCustomerNameError" style="display:none; color:var(--danger);">
        This customer already exists for the selected year.
      </div>
    </div>

    <div class="form-group">
      <div class="label">Phone Number (WA)</div>
      <input type="text" class="input" id="clPhoneNumber" maxlength="24" inputmode="numeric" autocomplete="off">
    </div>

    <div class="empty-sub" id="clCreateError" style="display:none; color:var(--danger);"></div>

    <div style="display:flex; gap:var(--space-3); justify-content:flex-end; margin-top:var(--space-5);">
      <button type="button" class="btn btn-primary" id="btnCreateCustomerSubmit">Save</button>
    </div>
  </div>
</div>

</div> <!-- /.menu-section[data-section="transactions"] -->

<!-- ============================================================
     Fly windows (modals) — deliberately OUTSIDE .menu-section above.
     If nested inside it, they'd stay unrenderable whenever the section
     itself is display:none (an ancestor's display:none hides descendants
     no matter what the child's own inline style says).
     ============================================================ -->

<div class="modal-overlay" id="txnEntryOverlay" style="display:none;">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between;">
      <div class="modal-title">Transactions</div>
      <button type="button" class="btn-icon" id="btnCloseEntryOverlay" aria-label="Close" style="font-size:18px; line-height:1; font-weight:700;">
        &times;
      </button>
    </div>
    <div class="modal-body">
      <button type="button" class="btn btn-primary" id="btnOpenCreateActivityCode">
        Create Activity Code
      </button>
      <button type="button" class="btn btn-secondary" id="btnOpenInputTransaction">
        Input Transaction
      </button>
      <button type="button" class="btn btn-secondary" id="btnOpenCustomerList">
        Customer List
      </button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="txnPasswordOverlay" style="display:none;">
  <div class="modal" style="max-width:360px;">
    <div class="modal-header">
      <div class="modal-title">Confirm Password</div>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Enter your password to continue</div>
        <input type="password" class="input" id="txnPasswordInput" autocomplete="current-password">
      </div>
      <div class="empty-sub" id="txnPasswordError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnPasswordCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnPasswordConfirm">Confirm</button>
    </div>
  </div>
</div>

<!-- Delete Customer — confirmation + data-loss warning + password re-check.
     Separate from txnPasswordOverlay on purpose: that one gates ENTRY into a
     flow (password checked once, then the whole form is usable), this one
     gates a single destructive action, so the password is checked directly
     against ajax/delete_customer.php at the moment of deletion. -->
<div class="modal-overlay" id="txnDeleteCustomerOverlay" style="display:none;">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <div class="modal-title">Delete Customer</div>
    </div>
    <div class="modal-body">
      <div class="empty-sub" style="color:var(--danger); margin-bottom:var(--space-3);">
        You're about to permanently delete <strong id="delCustomerName">this customer</strong>.
        This cannot be undone and all associated data will be lost.
      </div>
      <div class="form-group">
        <div class="label">Enter your password to confirm</div>
        <input type="password" class="input" id="delCustomerPasswordInput" autocomplete="current-password">
      </div>
      <div class="empty-sub" id="delCustomerError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnDeleteCustomerCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="btnDeleteCustomerConfirm">Delete</button>
    </div>
  </div>
</div>

<!-- Manage Departments — add / edit / delete department options -->
<div class="modal-overlay" id="txnManageDeptOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between;">
      <div class="modal-title">Manage Departments</div>
      <button type="button" class="btn-icon" id="btnCloseManageDept" aria-label="Close" style="font-size:18px; line-height:1; font-weight:700;">
        &times;
      </button>
    </div>
    <div class="modal-body">
      <div id="deptList" style="display:flex; flex-direction:column; gap:var(--space-2);">
        <!-- rows injected by JS -->
      </div>

      <div class="empty-sub" id="deptManageError" style="display:none; color:var(--danger);"></div>

      <div class="form-group" style="margin-top:var(--space-3);">
        <div class="label">Add New Department</div>
        <div style="display:flex; gap:var(--space-2);">
          <input type="text" class="input" id="deptNewLabel" maxlength="50" placeholder="e.g. PACKING" style="flex:1; text-transform:uppercase;">
          <button type="button" class="btn btn-primary" id="btnDeptAdd">Add</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var section = document.querySelector('.menu-section[data-section="transactions"]');

  var entryOverlay          = document.getElementById('txnEntryOverlay');
  var passwordOverlay       = document.getElementById('txnPasswordOverlay');
  var manageDeptOverlay     = document.getElementById('txnManageDeptOverlay');
  var deleteCustomerOverlay = document.getElementById('txnDeleteCustomerOverlay');

  var viewEmpty        = document.getElementById('viewTransactionsEmpty');
  var viewForm         = document.getElementById('viewCreateActivityCode');
  var viewResult       = document.getElementById('viewActivityCodeResult');
  var viewCustomerList = document.getElementById('viewCustomerList');

  function show(el) {
    el.style.display = (el === entryOverlay || el === passwordOverlay || el === manageDeptOverlay || el === deleteCustomerOverlay) ? 'flex' : 'block';
  }
  function hide(el) { el.style.display = 'none'; }

  function showOnlyView(target) {
    [viewEmpty, viewForm, viewResult, viewCustomerList].forEach(hide);
    show(target);
  }

  // Which flow the password overlay was opened for, so btnPasswordConfirm
  // knows where to land afterwards. Both Create Activity Code and Customer
  // List share the same password overlay/endpoint.
  var pendingPasswordTarget = 'activity_code'; // 'activity_code' | 'customer_list'

  // Only the entry fly window should ever appear unprompted, and only when
  // the Transactions section itself is actually opened (not on initial
  // page load with Dashboard active). We detect that by watching the
  // section's own style attribute instead of hooking footer.php's generic
  // [data-target] switcher, so this file stays self-contained.
  if (section) {
    var wasVisible = section.style.display !== 'none';

    var observer = new MutationObserver(function () {
      var isVisible = section.style.display !== 'none';
      if (isVisible && !wasVisible) {
        // Just switched INTO Transactions -> reset to the chooser fly window.
        showOnlyView(viewEmpty);
        hide(passwordOverlay);
        show(entryOverlay);
      } else if (!isVisible) {
        // Left the section -> close any open fly window.
        hide(entryOverlay);
        hide(passwordOverlay);
        hide(manageDeptOverlay);
        hide(deleteCustomerOverlay);
      }
      wasVisible = isVisible;
    });
    observer.observe(section, { attributes: true, attributeFilter: ['style'] });
  }

  // --- Entry fly window ---
  document.getElementById('btnOpenCreateActivityCode').addEventListener('click', function () {
    pendingPasswordTarget = 'activity_code';
    hide(entryOverlay);
    document.getElementById('txnPasswordInput').value = '';
    document.getElementById('txnPasswordError').style.display = 'none';
    show(passwordOverlay);
  });

  document.getElementById('btnOpenInputTransaction').addEventListener('click', function () {
    alert('Input Transaction: coming soon.');
  });

  document.getElementById('btnOpenCustomerList').addEventListener('click', function () {
    pendingPasswordTarget = 'customer_list';
    hide(entryOverlay);
    document.getElementById('txnPasswordInput').value = '';
    document.getElementById('txnPasswordError').style.display = 'none';
    show(passwordOverlay);
  });

  // X on the entry fly window -> back to Dashboard. We reuse footer.php's
  // own [data-target] click handling (instead of duplicating setActive
  // logic here) so the sidebar/bottom-nav active state stays in sync.
  document.getElementById('btnCloseEntryOverlay').addEventListener('click', function () {
    hide(entryOverlay);
    var dashboardTrigger = document.querySelector('[data-target="dashboard"]');
    if (dashboardTrigger) dashboardTrigger.click();
  });

  // --- Password fly window ---
  document.getElementById('btnPasswordCancel').addEventListener('click', function () {
    hide(passwordOverlay);
    show(entryOverlay);
  });

  document.getElementById('btnPasswordConfirm').addEventListener('click', function () {
    var pwd = document.getElementById('txnPasswordInput').value;
    var errBox = document.getElementById('txnPasswordError');
    errBox.style.display = 'none';

    fetch('ajax/verify_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'password=' + encodeURIComponent(pwd)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          hide(passwordOverlay);
          if (pendingPasswordTarget === 'customer_list') {
            resetCreateCustomerForm();
            showOnlyView(viewCustomerList);
          } else {
            resetCreateCodeForm();
            showOnlyView(viewForm);
          }
        } else {
          errBox.textContent = res.message || 'Wrong password.';
          errBox.style.display = 'block';
        }
      })
      .catch(function () {
        errBox.textContent = 'Connection error.';
        errBox.style.display = 'block';
      });
  });

  // --- Create Activity Code (in-page view) ---
  var acYear        = document.getElementById('acYear');
  var acDepartment  = document.getElementById('acDepartment');
  var acPathPreview = document.getElementById('acPathPreview');
  var acCodePreview = document.getElementById('acCodePreview');

  // Live preview of the actual next code/path, fetched from the server so
  // it always matches what create_activity_code.php would generate.
  // Debounced so typing/changing Year doesn't spam requests.
  var previewDebounceTimer = null;
  function updatePathPreview() {
    var y = acYear.value;
    var d = acDepartment.value;

    if (!/^\d{4}$/.test(y) || !d) {
      acCodePreview.value = '…';
      acPathPreview.value = '';
      return;
    }

    clearTimeout(previewDebounceTimer);
    previewDebounceTimer = setTimeout(function () {
      var params = new URLSearchParams({ year: y, departement: d });
      fetch('ajax/preview_activity_code.php?' + params.toString(), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) {
            acCodePreview.value = res.data.activity_code;
            acPathPreview.value = res.data.relative_path;
          } else {
            acCodePreview.value = '…';
            acPathPreview.value = '';
          }
        })
        .catch(function () {
          acCodePreview.value = '…';
          acPathPreview.value = '';
        });
    }, 250);
  }
  acYear.addEventListener('input', updatePathPreview);
  acDepartment.addEventListener('change', updatePathPreview);

  var acActivityName      = document.getElementById('acActivityName');
  var acActivityNameError = document.getElementById('acActivityNameError');
  var btnCreateCodeSubmit = document.getElementById('btnCreateCodeSubmit');

  // Existing activity codes, loaded from ajax/list_activity_codes.php.
  // Reused both for the Preview tab table and for the client-side
  // duplicate-name check below (no extra request per keystroke).
  var activityListCache = [];

  function checkDuplicateName() {
    var name = acActivityName.value.trim();
    var isDuplicate = name !== '' && activityListCache.some(function (a) {
      return a.activity_name === name;
    });
    acActivityNameError.style.display = isDuplicate ? 'block' : 'none';
    btnCreateCodeSubmit.disabled = isDuplicate;
    return isDuplicate;
  }

  acActivityName.addEventListener('input', function () {
    // text-transform:uppercase is visual only — force the actual value too,
    // since that's what gets sent to the server.
    var pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
    checkDuplicateName();
  });

  // Rebuilds the <select> options in place, keeping the current selection
  // when it still exists (falls back to the first option otherwise).
  function renderDepartmentSelect(list) {
    var keep = acDepartment.value;
    acDepartment.innerHTML = '';
    list.forEach(function (d) {
      var opt = document.createElement('option');
      opt.value = d.key;
      opt.textContent = d.label;
      acDepartment.appendChild(opt);
    });
    if (list.some(function (d) { return d.key === keep; })) {
      acDepartment.value = keep;
    }
    updatePathPreview();
  }

  // --- Manage Departments (fly window opened from the Department field) ---
  var deptList        = document.getElementById('deptList');
  var deptManageError  = document.getElementById('deptManageError');
  var deptNewLabel     = document.getElementById('deptNewLabel');

  function deptRequest(action, extra) {
    var payload = Object.assign({ action: action }, extra || {});
    return fetch('ajax/manage_departments.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(payload).toString()
    }).then(function (r) { return r.json(); });
  }

  function renderDeptList(list) {
    deptList.innerHTML = '';
    list.forEach(function (d) {
      var row = document.createElement('div');
      row.className = 'card';
      row.style.cssText = 'padding:var(--space-3); display:flex; align-items:center; gap:var(--space-2);';

      var labelSpan = document.createElement('div');
      labelSpan.textContent = d.label;
      labelSpan.style.flex = '1';
      row.appendChild(labelSpan);

      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-secondary';
      editBtn.style.cssText = 'padding:var(--space-2) var(--space-3); font-size:var(--text-sm);';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', function () { startEditRow(row, d); });
      row.appendChild(editBtn);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn btn-danger';
      delBtn.style.cssText = 'padding:var(--space-2) var(--space-3); font-size:var(--text-sm);';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', function () { deleteDept(d); });
      row.appendChild(delBtn);

      deptList.appendChild(row);
    });
  }

  function startEditRow(row, d) {
    row.innerHTML = '';
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'input';
    input.value = d.label;
    input.maxLength = 50;
    input.style.cssText = 'flex:1; text-transform:uppercase;';
    row.appendChild(input);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn-primary';
    saveBtn.textContent = 'Save';
    saveBtn.addEventListener('click', function () {
      var newLabel = input.value.toUpperCase().trim();
      if (!newLabel) return;
      deptManageError.style.display = 'none';
      deptRequest('edit', { key: d.key, label: newLabel }).then(function (res) {
        if (res.ok) {
          renderDeptList(res.departments);
          renderDepartmentSelect(res.departments);
        } else {
          deptManageError.textContent = res.message || 'Failed to save.';
          deptManageError.style.display = 'block';
        }
      });
    });
    row.appendChild(saveBtn);

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn btn-secondary';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', function () { loadDeptList(); });
    row.appendChild(cancelBtn);
  }

  function deleteDept(d) {
    if (!confirm('Delete department "' + d.label + '"?')) return;
    deptManageError.style.display = 'none';
    deptRequest('delete', { key: d.key }).then(function (res) {
      if (res.ok) {
        renderDeptList(res.departments);
        renderDepartmentSelect(res.departments);
      } else {
        deptManageError.textContent = res.message || 'Failed to delete.';
        deptManageError.style.display = 'block';
      }
    });
  }

  function loadDeptList() {
    deptManageError.style.display = 'none';
    deptRequest('list').then(function (res) {
      if (res.ok) renderDeptList(res.departments);
    });
  }

  document.getElementById('btnManageDepartments').addEventListener('click', function () {
    loadDeptList();
    deptNewLabel.value = '';
    show(manageDeptOverlay);
  });

  document.getElementById('btnCloseManageDept').addEventListener('click', function () {
    hide(manageDeptOverlay);
  });

  document.getElementById('btnDeptAdd').addEventListener('click', function () {
    var label = deptNewLabel.value.toUpperCase().trim();
    if (!label) return;
    deptManageError.style.display = 'none';
    deptRequest('add', { label: label }).then(function (res) {
      if (res.ok) {
        deptNewLabel.value = '';
        renderDeptList(res.departments);
        renderDepartmentSelect(res.departments);
      } else {
        deptManageError.textContent = res.message || 'Failed to add.';
        deptManageError.style.display = 'block';
      }
    });
  });

  // --- Preview tab: list of existing activity codes, as an accordion ---
  // (collapsed by default, header = Activity Name only, one item open at a
  // time). Replaced the old table-that-collapses-into-cards-on-mobile
  // approach entirely — this is now the only rendering, at every width.
  var acPreviewList  = document.getElementById('acPreviewList');
  var acPreviewEmpty = document.getElementById('acPreviewEmpty');

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
    // Opening one always collapses whatever else is open, but only within
    // the SAME list as the clicked item (Activity Code Preview and Customer
    // List each have their own .accordion-list) — otherwise closing was
    // hardcoded to acPreviewList, so items inside clPreviewList could never
    // be closed at all.
    var ownList = item.closest('.accordion-list');
    if (ownList) {
      ownList.querySelectorAll('.accordion-item.open').forEach(closeAccordionItem);
    }
    if (!isOpen) openAccordionItem(item);
  }

  function renderActivityList(list) {
    acPreviewList.innerHTML = '';
    acPreviewEmpty.style.display = list.length ? 'none' : 'block';

    list.forEach(function (a) {
      var item = document.createElement('div');
      item.className = 'accordion-item';

      var header = document.createElement('div');
      header.className = 'accordion-header';

      var title = document.createElement('span');
      title.className = 'accordion-title';
      title.textContent = a.activity_name; // the only thing visible while collapsed

      var chevron = document.createElement('i');
      chevron.className = 'ti ti-chevron-down accordion-chevron';

      header.appendChild(title);
      header.appendChild(chevron);
      header.addEventListener('click', function () { toggleAccordionItem(item); });

      var body = document.createElement('div');
      body.className = 'accordion-body';

      var bodyInner = document.createElement('div');
      bodyInner.className = 'accordion-body-inner';

      [
        ['Activity Code', a.activity_code],
        ['Department', a.department],
        ['Cashflow', a.cashflow],
        ['Relative Path', a.relative_path],
        ['Created', a.created_at]
      ].forEach(function (pair) {
        var row = document.createElement('div');
        row.className = 'accordion-row';

        var label = document.createElement('div');
        label.className = 'accordion-row-label';
        label.textContent = pair[0];

        var value = document.createElement('div');
        value.className = 'accordion-row-value';
        value.textContent = pair[1];

        row.appendChild(label);
        row.appendChild(value);
        bodyInner.appendChild(row);
      });

      body.appendChild(bodyInner);
      item.appendChild(header);
      item.appendChild(body);
      acPreviewList.appendChild(item);
    });
  }

  function loadActivityList() {
    return fetch('ajax/list_activity_codes.php', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) {
          // e.g. 401 (session invalid) -> r.json() below would still parse
          // fine since list_activity_codes.php always emits JSON, but log
          // the HTTP status too so this is easy to diagnose from DevTools.
          console.warn('list_activity_codes.php responded with status', r.status);
        }
        return r.json();
      })
      .then(function (res) {
        if (res.ok) {
          activityListCache = res.data;
          acPreviewEmpty.querySelector('.empty-title').textContent = 'No activity codes yet';
          acPreviewEmpty.querySelector('.empty-sub').textContent = 'Switch to the Create Activity Code tab to add one.';
          renderActivityList(activityListCache);
        } else {
          activityListCache = [];
          renderActivityList([]);
          acPreviewEmpty.querySelector('.empty-title').textContent = 'Failed to load';
          acPreviewEmpty.querySelector('.empty-sub').textContent = res.message || 'Could not load activity codes.';
        }
      })
      .catch(function (err) {
        console.error('loadActivityList failed:', err);
        activityListCache = [];
        renderActivityList([]);
        acPreviewEmpty.querySelector('.empty-title').textContent = 'Failed to load';
        acPreviewEmpty.querySelector('.empty-sub').textContent = 'Connection error.';
      });
  }

  // --- Tabs: Preview <-> Create Activity Code ---
  var acTabs         = document.querySelectorAll('#acTabGroup .tab');
  var acTabPanelMap   = {
    preview: document.getElementById('acTabPanelPreview'),
    create:  document.getElementById('acTabPanelCreate')
  };

  function setActiveAcTab(name) {
    acTabs.forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-ac-tab') === name);
    });
    Object.keys(acTabPanelMap).forEach(function (key) {
      acTabPanelMap[key].style.display = (key === name) ? 'block' : 'none';
    });
  }

  acTabs.forEach(function (t) {
    t.addEventListener('click', function () {
      var name = t.getAttribute('data-ac-tab');
      setActiveAcTab(name);
      if (name === 'preview') {
        // Refresh on every visit to Preview, not just the first time the
        // form is opened, so newly created / edited codes always show up.
        loadActivityList();
      }
    });
  });

  function resetCreateCodeForm() {
    document.getElementById('acActivityName').value = '';
    document.getElementById('acCashflow').value = 'inflow';
    document.getElementById('txnCreateCodeError').style.display = 'none';
    acActivityNameError.style.display = 'none';
    btnCreateCodeSubmit.disabled = false;
    setActiveAcTab('preview'); // land on Preview first, per spec
    loadActivityList();
    updatePathPreview();
  }

  document.getElementById('btnBackToEntryFromForm').addEventListener('click', function () {
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });

  document.getElementById('btnCreateCodeSubmit').addEventListener('click', function () {
    var errBox = document.getElementById('txnCreateCodeError');
    errBox.style.display = 'none';

    if (checkDuplicateName()) return; // don't even hit the server on a known duplicate

    var payload = new URLSearchParams({
      year: acYear.value,
      departement: acDepartment.value,
      activity_name: document.getElementById('acActivityName').value,
      cashflow: document.getElementById('acCashflow').value
    });

    fetch('ajax/create_activity_code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          document.getElementById('resActivityCode').value = res.data.activity_code;
          document.getElementById('resRelativePath').value = res.data.relative_path;
          showOnlyView(viewResult);
        } else {
          errBox.textContent = res.message || 'Failed to create activity code.';
          errBox.style.display = 'block';
        }
      })
      .catch(function () {
        errBox.textContent = 'Connection error.';
        errBox.style.display = 'block';
      });
  });

  // --- Result (in-page view) ---
  document.getElementById('btnResultCreateAnother').addEventListener('click', function () {
    // The password re-verify token is single-use server-side, so creating
    // another code needs a fresh confirmation.
    pendingPasswordTarget = 'activity_code';
    showOnlyView(viewEmpty);
    document.getElementById('txnPasswordInput').value = '';
    document.getElementById('txnPasswordError').style.display = 'none';
    show(passwordOverlay);
  });

  document.getElementById('btnResultDone').addEventListener('click', function () {
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });

  // ================================================================
  // Customer List — same accordion pattern as the Activity Code
  // Preview tab (collapsed by default, one item open at a time).
  // ================================================================
  var clPreviewList  = document.getElementById('clPreviewList');
  var clPreviewEmpty = document.getElementById('clPreviewEmpty');
  var customerListCache = [];

  // null = New Customer form is in "create" mode. Set to a customer's id
  // while editing that row (see openEditCustomerForm / btnCreateCustomerSubmit).
  var editingCustomerId = null;
  // Customer object currently pending deletion, set by openDeleteCustomerConfirm.
  var pendingDeleteCustomer = null;

  document.getElementById('btnBackToEntryFromCustomer').addEventListener('click', function () {
    editingCustomerId = null; // don't carry edit state into the next visit
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });

  function renderCustomerList(list) {
    clPreviewList.innerHTML = '';
    clPreviewEmpty.style.display = list.length ? 'none' : 'block';

    list.forEach(function (c) {
      var item = document.createElement('div');
      item.className = 'accordion-item';

      var header = document.createElement('div');
      header.className = 'accordion-header';

      var title = document.createElement('span');
      title.className = 'accordion-title';
      title.textContent = c.customer_name; // only thing visible while collapsed

      var chevron = document.createElement('i');
      chevron.className = 'ti ti-chevron-down accordion-chevron';

      header.appendChild(title);
      header.appendChild(chevron);
      header.addEventListener('click', function () { toggleAccordionItem(item); });

      var body = document.createElement('div');
      body.className = 'accordion-body';

      var bodyInner = document.createElement('div');
      bodyInner.className = 'accordion-body-inner';

      [
        ['Year', c.year],
        ['Phone Number', c.phone_number],
        ['Total Inflow', c.total_inflow],
        ['Total Outflow', c.total_outflow],
        ['Profit', c.profit],
        ['Created', c.created_at]
      ].forEach(function (pair) {
        var row = document.createElement('div');
        row.className = 'accordion-row';

        var label = document.createElement('div');
        label.className = 'accordion-row-label';
        label.textContent = pair[0];

        var value = document.createElement('div');
        value.className = 'accordion-row-value';
        value.textContent = pair[1];

        row.appendChild(label);
        row.appendChild(value);
        bodyInner.appendChild(row);
      });

      // Edit / Delete actions — clicks here must not bubble to the header
      // (which toggles open/close), so each handler stops propagation.
      var actions = document.createElement('div');
      actions.className = 'accordion-row';
      actions.style.justifyContent = 'flex-end';
      actions.style.gap = 'var(--space-2)';
      actions.style.marginTop = 'var(--space-2)';

      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-secondary';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openEditCustomerForm(c);
      });

      var deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'btn btn-danger';
      deleteBtn.textContent = 'Delete';
      deleteBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openDeleteCustomerConfirm(c);
      });

      actions.appendChild(editBtn);
      actions.appendChild(deleteBtn);
      bodyInner.appendChild(actions);

      body.appendChild(bodyInner);
      item.appendChild(header);
      item.appendChild(body);
      clPreviewList.appendChild(item);
    });
  }

  // toggleAccordionItem()/openAccordionItem()/closeAccordionItem() are
  // shared with the Activity Code Preview tab (defined above) — they only
  // touch whatever .accordion-item is passed in, so they work unchanged
  // for this list too.

  function loadCustomerList() {
    return fetch('ajax/list_customers.php', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) console.warn('list_customers.php responded with status', r.status);
        return r.json();
      })
      .then(function (res) {
        if (res.ok) {
          customerListCache = res.data;
          clPreviewEmpty.querySelector('.empty-title').textContent = 'No customers yet';
          clPreviewEmpty.querySelector('.empty-sub').textContent = 'Switch to the New Customer tab to add one.';
          renderCustomerList(customerListCache);
        } else {
          customerListCache = [];
          renderCustomerList([]);
          clPreviewEmpty.querySelector('.empty-title').textContent = 'Failed to load';
          clPreviewEmpty.querySelector('.empty-sub').textContent = res.message || 'Could not load customers.';
        }
      })
      .catch(function (err) {
        console.error('loadCustomerList failed:', err);
        customerListCache = [];
        renderCustomerList([]);
        clPreviewEmpty.querySelector('.empty-title').textContent = 'Failed to load';
        clPreviewEmpty.querySelector('.empty-sub').textContent = 'Connection error.';
      });
  }

  // --- Tabs: Customer List <-> New Customer ---
  var clTabs        = document.querySelectorAll('#clTabGroup .tab');
  var clTabPanelMap  = {
    preview: document.getElementById('clTabPanelPreview'),
    create:  document.getElementById('clTabPanelCreate')
  };

  function setActiveClTab(name) {
    clTabs.forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-cl-tab') === name);
    });
    Object.keys(clTabPanelMap).forEach(function (key) {
      clTabPanelMap[key].style.display = (key === name) ? 'block' : 'none';
    });
  }

  clTabs.forEach(function (t) {
    t.addEventListener('click', function () {
      var name = t.getAttribute('data-cl-tab');
      setActiveClTab(name);
      if (name === 'preview') {
        // Refresh every visit, so newly added customers always show up.
        loadCustomerList();
      } else if (name === 'create' && editingCustomerId === null) {
        // Clicked the tab directly (not via an Edit button, which sets
        // editingCustomerId itself before switching tabs) -> make sure the
        // form is in blank "new customer" state, not leftover edit data.
        clFormModeLabel.style.display = 'none';
        btnCreateCustomerSubmit.textContent = 'Save';
      }
    });
  });

  var clYear            = document.getElementById('clYear');
  var clCustomerName     = document.getElementById('clCustomerName');
  var clCustomerNameError = document.getElementById('clCustomerNameError');
  var clPhoneNumber      = document.getElementById('clPhoneNumber');
  var btnCreateCustomerSubmit = document.getElementById('btnCreateCustomerSubmit');

  function checkDuplicateCustomer() {
    var name = clCustomerName.value.trim();
    var year = clYear.value;
    var isDuplicate = name !== '' && customerListCache.some(function (c) {
      if (editingCustomerId !== null && c.id === editingCustomerId) return false; // ignore self while editing
      return c.customer_name === name && String(c.year) === String(year);
    });
    clCustomerNameError.style.display = isDuplicate ? 'block' : 'none';
    btnCreateCustomerSubmit.disabled = isDuplicate;
    return isDuplicate;
  }

  clCustomerName.addEventListener('input', function () {
    // text-transform:uppercase is visual only — force the actual value too,
    // since that's what gets sent to the server.
    var pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
    checkDuplicateCustomer();
  });
  clYear.addEventListener('input', checkDuplicateCustomer);

  // --- Phone Number: fixed "+62 8" prefix, rest grouped 4-4 realtime ---
  var PHONE_PREFIX = '+62 8';
  var phoneDigits = ''; // raw digits typed AFTER the fixed "8", max 11 (total incl. 8 = 12)

  function formatPhoneGroups(digits) {
    var groups = [];
    for (var i = 0; i < digits.length; i += 4) {
      groups.push(digits.substr(i, 4));
    }
    return groups.join(' ');
  }

  function renderPhoneValue() {
    var groups = formatPhoneGroups(phoneDigits);
    clPhoneNumber.value = groups ? (PHONE_PREFIX + ' ' + groups) : PHONE_PREFIX;
  }

  function placeCaretAtEnd() {
    var len = clPhoneNumber.value.length;
    clPhoneNumber.setSelectionRange(len, len);
  }

  clPhoneNumber.addEventListener('focus', function () {
    if (this.value === '') renderPhoneValue();
    placeCaretAtEnd();
  });

  clPhoneNumber.addEventListener('click', function () {
    // Never let the caret land inside the fixed "+62 8" prefix.
    if (this.selectionStart < PHONE_PREFIX.length) placeCaretAtEnd();
  });

  clPhoneNumber.addEventListener('input', function () {
    // Re-derive digits from whatever the user ended up typing: strip
    // everything, drop the fixed "628" if it's still leading, cap length.
    var digitsOnly = this.value.replace(/\D/g, '');
    if (digitsOnly.indexOf('628') === 0) {
      digitsOnly = digitsOnly.substring(3);
    } else if (digitsOnly.indexOf('62') === 0) {
      digitsOnly = digitsOnly.substring(2).replace(/^8/, '');
    } else if (digitsOnly.indexOf('8') === 0 && digitsOnly.length && phoneDigits.length === 0) {
      digitsOnly = digitsOnly.substring(1);
    }
    phoneDigits = digitsOnly.substring(0, 11); // 8 + 11 digits = 12 total, typical ID mobile length
    renderPhoneValue();
    placeCaretAtEnd();
  });

  function resetPhoneField() {
    phoneDigits = '';
    renderPhoneValue();
  }
  renderPhoneValue(); // show "+62 8" immediately on load

  // "+628xxxxxxxxxx" (as stored/returned by the server) -> the digits
  // that go after the fixed "+62 8" prefix, same thing phoneDigits holds
  // while the user is typing.
  function digitsFromStoredPhone(stored) {
    var digits = String(stored || '').replace(/\D/g, '');
    if (digits.indexOf('628') === 0) digits = digits.substring(3);
    return digits.substring(0, 11);
  }

  var clFormModeLabel = document.getElementById('clFormModeLabel');

  function resetCreateCustomerForm() {
    editingCustomerId = null;
    clFormModeLabel.style.display = 'none';
    btnCreateCustomerSubmit.textContent = 'Save';
    clCustomerName.value = '';
    resetPhoneField();
    clYear.value = '<?= htmlspecialchars($currentYear) ?>';
    document.getElementById('clCreateError').style.display = 'none';
    clCustomerNameError.style.display = 'none';
    btnCreateCustomerSubmit.disabled = false;
    setActiveClTab('preview'); // land on Customer List first, per spec
    loadCustomerList();
  }

  // Switches the New Customer tab into "editing" mode, pre-filled with an
  // existing customer's data. Save (btnCreateCustomerSubmit) then routes to
  // update_customer.php instead of create_customer.php while this is set.
  function openEditCustomerForm(c) {
    editingCustomerId = c.id;
    clFormModeLabel.textContent = 'Editing ' + c.customer_name;
    clFormModeLabel.style.display = 'block';
    btnCreateCustomerSubmit.textContent = 'Update';
    document.getElementById('clCreateError').style.display = 'none';
    clCustomerNameError.style.display = 'none';
    btnCreateCustomerSubmit.disabled = false;

    clYear.value = c.year;
    clCustomerName.value = c.customer_name;
    phoneDigits = digitsFromStoredPhone(c.phone_number);
    renderPhoneValue();

    setActiveClTab('create');
  }

  btnCreateCustomerSubmit.addEventListener('click', function () {
    var errBox = document.getElementById('clCreateError');
    errBox.style.display = 'none';

    if (checkDuplicateCustomer()) return; // don't hit the server on a known duplicate

    var isEditing = editingCustomerId !== null;
    var payloadFields = {
      year: clYear.value,
      customer_name: clCustomerName.value,
      phone_number: clPhoneNumber.value
    };
    if (isEditing) payloadFields.id = editingCustomerId;
    var payload = new URLSearchParams(payloadFields);

    fetch(isEditing ? 'ajax/update_customer.php' : 'ajax/create_customer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          // No generated artifact to show (unlike Activity Code), so just
          // land back on the refreshed Customer List.
          resetCreateCustomerForm();
        } else {
          errBox.textContent = res.message || (isEditing ? 'Failed to update customer.' : 'Failed to save customer.');
          errBox.style.display = 'block';
        }
      })
      .catch(function () {
        errBox.textContent = 'Connection error.';
        errBox.style.display = 'block';
      });
  });

  // --- Delete Customer: confirmation + data-loss warning + password ---
  var delCustomerName            = document.getElementById('delCustomerName');
  var delCustomerPasswordInput   = document.getElementById('delCustomerPasswordInput');
  var delCustomerError           = document.getElementById('delCustomerError');
  var btnDeleteCustomerConfirm   = document.getElementById('btnDeleteCustomerConfirm');

  function openDeleteCustomerConfirm(c) {
    pendingDeleteCustomer = c;
    delCustomerName.textContent = c.customer_name;
    delCustomerPasswordInput.value = '';
    delCustomerError.style.display = 'none';
    show(deleteCustomerOverlay);
  }

  document.getElementById('btnDeleteCustomerCancel').addEventListener('click', function () {
    pendingDeleteCustomer = null;
    hide(deleteCustomerOverlay);
  });

  btnDeleteCustomerConfirm.addEventListener('click', function () {
    if (!pendingDeleteCustomer) return;
    var pwd = delCustomerPasswordInput.value;
    delCustomerError.style.display = 'none';

    if (pwd === '') {
      delCustomerError.textContent = 'Password is required.';
      delCustomerError.style.display = 'block';
      return;
    }

    var payload = new URLSearchParams({
      id: pendingDeleteCustomer.id,
      password: pwd
    });

    fetch('ajax/delete_customer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          // If the deleted row was mid-edit, drop back to create mode.
          if (editingCustomerId === pendingDeleteCustomer.id) resetCreateCustomerForm();
          pendingDeleteCustomer = null;
          hide(deleteCustomerOverlay);
          loadCustomerList();
        } else {
          delCustomerError.textContent = res.message || 'Failed to delete customer.';
          delCustomerError.style.display = 'block';
        }
      })
      .catch(function () {
        delCustomerError.textContent = 'Connection error.';
        delCustomerError.style.display = 'block';
      });
  });
})();
</script>