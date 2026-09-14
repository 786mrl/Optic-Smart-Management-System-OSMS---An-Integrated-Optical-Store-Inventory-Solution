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

<!-- Create Activity Code — the actual form lives on the page, not in a modal -->
<div class="card" id="viewCreateActivityCode" style="width:100%; display:none;">
  <div class="panel-header">
    <div class="panel-title">Create Activity Code</div>
    <button type="button" class="btn btn-secondary" id="btnBackToEntryFromForm">
      Back
    </button>
  </div>

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
  </div>

  <div class="form-group">
    <div class="label">Activity Code</div>
    <input type="text" class="input" value="Auto-generated" disabled>
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

  var entryOverlay      = document.getElementById('txnEntryOverlay');
  var passwordOverlay   = document.getElementById('txnPasswordOverlay');
  var manageDeptOverlay = document.getElementById('txnManageDeptOverlay');

  var viewEmpty  = document.getElementById('viewTransactionsEmpty');
  var viewForm   = document.getElementById('viewCreateActivityCode');
  var viewResult = document.getElementById('viewActivityCodeResult');

  function show(el) { el.style.display = (el === entryOverlay || el === passwordOverlay || el === manageDeptOverlay) ? 'flex' : 'block'; }
  function hide(el) { el.style.display = 'none'; }

  function showOnlyView(target) {
    [viewEmpty, viewForm, viewResult].forEach(hide);
    show(target);
  }

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
      }
      wasVisible = isVisible;
    });
    observer.observe(section, { attributes: true, attributeFilter: ['style'] });
  }

  // --- Entry fly window ---
  document.getElementById('btnOpenCreateActivityCode').addEventListener('click', function () {
    hide(entryOverlay);
    document.getElementById('txnPasswordInput').value = '';
    document.getElementById('txnPasswordError').style.display = 'none';
    show(passwordOverlay);
  });

  document.getElementById('btnOpenInputTransaction').addEventListener('click', function () {
    alert('Input Transaction: coming soon.');
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
          resetCreateCodeForm();
          showOnlyView(viewForm);
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

  function updatePathPreview() {
    var y = acYear.value || 'YYYY';
    var d = acDepartment.value || 'dept';
    acPathPreview.value = 'input/' + y + '/' + d + '/???/';
  }
  acYear.addEventListener('input', updatePathPreview);
  acDepartment.addEventListener('change', updatePathPreview);

  var acActivityName = document.getElementById('acActivityName');
  acActivityName.addEventListener('input', function () {
    // text-transform:uppercase is visual only — force the actual value too,
    // since that's what gets sent to the server.
    var pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
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

  function resetCreateCodeForm() {
    document.getElementById('acActivityName').value = '';
    document.getElementById('acCashflow').value = 'inflow';
    document.getElementById('txnCreateCodeError').style.display = 'none';
    updatePathPreview();
  }

  document.getElementById('btnBackToEntryFromForm').addEventListener('click', function () {
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });

  document.getElementById('btnCreateCodeSubmit').addEventListener('click', function () {
    var errBox = document.getElementById('txnCreateCodeError');
    errBox.style.display = 'none';

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
    showOnlyView(viewEmpty);
    document.getElementById('txnPasswordInput').value = '';
    document.getElementById('txnPasswordError').style.display = 'none';
    show(passwordOverlay);
  });

  document.getElementById('btnResultDone').addEventListener('click', function () {
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });
})();
</script>