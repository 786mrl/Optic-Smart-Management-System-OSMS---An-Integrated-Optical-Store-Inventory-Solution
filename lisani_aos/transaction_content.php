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

  <!-- Tab 2: Create Activity Code / Edit Activity Code — same form, reused
       for both via editingActivityId (null = creating, set = editing). -->
  <div id="acTabPanelCreate" style="display:none;">
    <div class="empty-sub" id="acFormModeLabel" style="display:none; color:var(--accent); margin-bottom:var(--space-3);"></div>
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

<!-- Disbursement — Document capture. Same placement pattern as
     viewCreateActivityCode/viewCustomerList: a plain .card, shown/hidden
     via showOnlyView(), not a modal. -->
<div class="card" id="viewDisbursementCapture" style="width:100%; display:none;">
  <div class="panel-header">
    <div class="panel-title">Disbursement — Document &amp; Details</div>
    <button type="button" class="btn btn-secondary" id="btnDisbCaptureBack">Back</button>
  </div>

  <style>
    /* Distinct from .btn-secondary on purpose — that one blends into the
       card background (neomorphic, same bg-surface). This is an outlined
       accent button, small, and hidden until the user focuses its field to
       type manually (every field has one; JS toggles .visible). */
    .btn-cap-scan {
      display: none;
      flex-shrink: 0;
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-md);
      background: transparent;
      border: 1.5px solid var(--accent);
      color: var(--accent);
      font-weight: 600;
      font-size: var(--text-sm);
      cursor: pointer;
      transition: all .15s ease;
    }
    .btn-cap-scan:hover { background: var(--accent-soft); }
    .btn-cap-scan.visible { display: inline-flex; align-items: center; justify-content: center; }
  </style>

  <div class="form-group">
    <div class="label">Bank Slip (PDF or Image)</div>
    <input type="file" class="input" id="capFileInput" accept="application/pdf,image/*">
    <button type="button" class="btn btn-secondary" id="btnCapOpenViewer" style="display:none; margin-top:var(--space-2);">View Document</button>
  </div>

  <!-- Fullscreen document viewer (moved to <body> by JS so no ancestor can
       clip it). Shown after upload, when re-scanning a field, or via "View Document". -->
  <div id="capViewerWrap" class="cap-viewer" style="display:none;">
    <div class="cap-viewer-bar">
      <div class="cap-viewer-info" id="capPageInfo"></div>
      <div class="cap-viewer-controls">
        <button type="button" class="btn btn-secondary" id="btnCapPrevPage" style="display:none;">Prev</button>
        <button type="button" class="btn btn-secondary" id="btnCapNextPage" style="display:none;">Next</button>
        <button type="button" class="btn btn-secondary" id="btnCapZoomOut" aria-label="Zoom out">&minus;</button>
        <button type="button" class="btn btn-secondary" id="btnCapZoomFit" aria-label="Reset zoom" style="min-width:64px;"><span id="capZoomLabel">100%</span></button>
        <button type="button" class="btn btn-secondary" id="btnCapZoomIn" aria-label="Zoom in">+</button>
        <button type="button" class="btn btn-secondary" id="btnCapFields">Fields</button>
        <button type="button" class="btn btn-primary" id="btnCapViewerClose">Close</button>
      </div>
    </div>
    <div class="cap-viewer-hint" id="capBlockHint" style="display:none;"></div>
    <div class="cap-viewer-hint" id="capOcrBusy" style="display:none;">Reading selection…</div>
    <div class="cap-viewer-stage" id="capStage">
      <canvas id="capCanvas"></canvas>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Date</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="date" class="input" id="capDate" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capDate" data-scan-label="Date">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Source Bank</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capSourceBank" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capSourceBank" data-scan-label="Source Bank">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Destination Bank</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capDestBank" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capDestBank" data-scan-label="Destination Bank">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Source Account Number</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capSourceAccountNumber" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capSourceAccountNumber" data-scan-label="Source Account Number">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Source Account Name</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capSourceAccountName" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capSourceAccountName" data-scan-label="Source Account Name">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Destination Account Number</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capDestAccountNumber" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capDestAccountNumber" data-scan-label="Destination Account Number">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Destination Account Name</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capDestAccountName" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capDestAccountName" data-scan-label="Destination Account Name">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Notes (as written on the slip)</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capNotes" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capNotes" data-scan-label="Notes">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Currency</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" class="input input-uppercase" id="capCurrency" value="IDR" maxlength="10" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capCurrency" data-scan-label="Currency">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Transaction Amount</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" inputmode="decimal" class="input input-number-comma" id="capAmount" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capAmount" data-scan-label="Transaction Amount">Scan</button>
    </div>
  </div>

  <div class="form-group" id="capExchangeRateGroup" style="display:none;">
    <div class="label">Exchange Rate</div>
    <div style="display:flex; gap:var(--space-2);">
      <input type="text" inputmode="decimal" class="input input-number-comma" id="capExchangeRate" style="flex:1;">
      <button type="button" class="btn btn-secondary btn-cap-scan" data-scan-field="capExchangeRate" data-scan-label="Exchange Rate">Scan</button>
    </div>
  </div>

  <div class="form-group">
    <div class="label">Final Amount (IDR)</div>
    <input type="text" inputmode="decimal" class="input input-number-comma" id="capFinalAmountIdr">
    <div class="empty-sub">Auto-calculated from Amount &times; Exchange Rate when Currency isn't IDR — still editable.</div>
  </div>

  <div class="empty-sub" id="capError" style="display:none; color:var(--danger);"></div>

  <div style="display:flex; gap:var(--space-3); justify-content:flex-end; margin-top:var(--space-5);">
    <button type="button" class="btn btn-primary" id="btnCapSave">Save Transaction</button>
  </div>
</div>


<!-- Sales Transaction — tabbed: New Order (paste the WhatsApp message) + Customers
     (per-customer totals and order history per invoice). Same placement pattern as
     viewCustomerList: a plain .card shown/hidden via showOnlyView(), not a modal. -->
<div class="card" id="viewSalesTransaction" style="width:100%; display:none;">
  <div class="panel-header">
    <div class="panel-title">Sales Transaction</div>
    <button type="button" class="btn btn-secondary" id="btnBackToEntryFromSales">Back</button>
  </div>

  <style>
    #stTabGroup {
      display: flex;
      width: 100%;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      background: none;
      padding: 0;
    }
    #stTabGroup .tab {
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
    #stTabGroup .tab:hover { color: var(--text-primary); }
    #stTabGroup .tab.active {
      background: var(--bg-surface);
      color: var(--accent);
      box-shadow: 5px 5px 10px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
    }
    #viewSalesTransaction textarea.input { resize: vertical; min-height: 120px; line-height: 1.4; }
    #viewSalesTransaction .st-item-row {
      padding: var(--space-3) 0;
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    #viewSalesTransaction .st-item-row:last-child { border-bottom: none; }
    #viewSalesTransaction .st-item-line {
      font-size: var(--text-xs);
      color: var(--text-muted);
      margin-bottom: var(--space-1);
      word-break: break-word;
    }
    #viewSalesTransaction .st-item-main {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: var(--space-2);
    }
    #viewSalesTransaction .st-item-name {
      flex: 1 1 150px;
      font-size: var(--text-sm);
      font-weight: 600;
    }
    #viewSalesTransaction .st-item-name.unresolved { color: var(--warning); }
    #viewSalesTransaction .st-item-qty { width: 84px; flex: 0 0 auto; text-align: right; }
    #viewSalesTransaction .st-item-unit {
      min-width: 46px;
      font-size: var(--text-sm);
      color: var(--text-secondary);
    }
    #viewSalesTransaction .st-mini-btn { padding: var(--space-2) var(--space-3); }
    #viewSalesTransaction .st-section-label {
      margin-top: var(--space-3);
      padding-top: var(--space-3);
      border-top: 1px solid rgba(255,255,255,0.08);
      font-size: var(--text-sm);
      color: var(--text-muted);
    }
    #viewSalesTransaction .st-mov {
      padding: var(--space-2) 0;
      border-bottom: 1px solid rgba(255,255,255,0.03);
      font-size: var(--text-sm);
    }
    #viewSalesTransaction .st-mov:last-child { border-bottom: none; }
    #viewSalesTransaction .st-mov-top {
      display: flex;
      justify-content: space-between;
      gap: var(--space-3);
    }
    #viewSalesTransaction .st-mov-sub {
      font-size: var(--text-xs);
      color: var(--text-muted);
      margin-top: 2px;
      word-break: break-word;
    }
    #viewSalesTransaction .st-value { word-break: normal; }

    .st-order-pick-card {
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: var(--radius-md, 10px);
      padding: var(--space-3);
      margin-bottom: var(--space-3);
      cursor: pointer;
      transition: border-color 0.15s ease, background 0.15s ease;
    }
    .st-order-pick-card:hover,
    .st-order-pick-card:focus-visible {
      border-color: var(--accent, #6b8afd);
      background: rgba(255,255,255,0.03);
    }
    .st-order-pick-top {
      display: flex;
      justify-content: space-between;
      gap: var(--space-2);
      font-size: var(--text-sm);
      color: var(--text-secondary);
      margin-bottom: var(--space-2);
    }
  </style>
  <div class="tab-group" id="stTabGroup">
    <div class="tab active" data-st-tab="order">New Order</div>
    <div class="tab" data-st-tab="customers">Customers</div>
  </div>

  <!-- Tab 1: New Order -->
  <div id="stTabPanelOrder">
    <div class="empty-sub" id="stSuccessBox" style="display:none; color:var(--success); margin-bottom:var(--space-3);"></div>

    <div class="form-group">
      <div class="label">Customer</div>
      <select class="select" id="stCustomer">
        <option value="">-- select customer --</option>
      </select>
    </div>

    <div class="form-group">
      <div class="label">Order Message (WhatsApp)</div>
      <textarea class="input" id="stMessage" rows="6" placeholder="Paste the order message here"></textarea>
    </div>
    <div class="empty-sub" id="stReadError" style="display:none; color:var(--danger); white-space:pre-line;"></div>
    <div style="display:flex; justify-content:flex-end; margin-bottom:var(--space-2);">
      <button type="button" class="btn btn-secondary" id="btnStRead">Read Message</button>
    </div>

    <div id="stParsedBox" style="display:none;">
      <div class="form-group">
        <div class="label">Driver</div>
        <input type="text" class="input input-uppercase" id="stDriver" maxlength="150" placeholder="e.g. PAK FADLUN" autocomplete="off">
      </div>
      <div class="form-group">
        <div class="label">Police Number</div>
        <input type="text" class="input input-uppercase" id="stPolice" maxlength="30" placeholder="e.g. BL 8392 N" autocomplete="off">
      </div>

      <div class="label">Products</div>
      <div id="stItemList"></div>
      <div class="empty-sub" id="stItemEmpty" style="display:none;">No product lines were found in this message.</div>
      <div class="empty-sub" id="stIgnoredBox" style="display:none; margin-top:var(--space-2);"></div>

      <div class="form-group" style="margin-top:var(--space-4);">
        <div class="label">Order Date</div>
        <input type="date" class="input" id="stOrderDate">
      </div>

      <div class="empty-sub" id="stReviewError" style="display:none; color:var(--danger); white-space:pre-line;"></div>
      <div style="display:flex; justify-content:flex-end; margin-top:var(--space-4);">
        <button type="button" class="btn btn-primary" id="btnStReview">Review Order</button>
      </div>
    </div>
  </div>

  <!-- Tab 2: Customers — one collapsible card per customer, loaded when opened -->
  <div id="stTabPanelCustomers" style="display:none;">
    <div class="accordion-list" id="stCustList"></div>
    <div class="empty-state" id="stCustEmpty" style="display:none;">
      <div class="empty-title">No customers yet</div>
      <div class="empty-sub">Add customers from Customer List first.</div>
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

<!-- Itemized Pricing — Add Price form. No reverify needed here (same as
     Create New Logistic): only Edit/Delete of an existing entry below are
     reverify-gated. Product dropdown lists every registered logistic
     (ajax/list_priceable_products.php), Unit auto-fills from the selected
     product and is NOT user-editable (snapshotted server-side too). -->
<div class="modal-overlay" id="itemPriceAddOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Add Itemized Price</div></div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Customer</div>
        <input type="text" class="input" id="itemPriceAddCustomerLabel" disabled>
      </div>
      <div class="form-group">
        <div class="label">Product</div>
        <select class="select" id="itemPriceAddProduct">
          <option value="">-- select product --</option>
        </select>
      </div>
      <div class="form-group">
        <div class="label">Price</div>
        <input type="text" inputmode="decimal" class="input input-number-comma" id="itemPriceAddPrice" placeholder="Price">
      </div>
      <div class="form-group">
        <div class="label">Date</div>
        <input type="date" class="input" id="itemPriceAddDate">
      </div>
      <div class="form-group">
        <div class="label">Unit</div>
        <input type="text" class="input" id="itemPriceAddUnit" disabled placeholder="-- select a product first --">
      </div>
      <div class="empty-sub" id="itemPriceAddError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnItemPriceAddCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnItemPriceAddSave">Save</button>
    </div>
  </div>
</div>

<!-- Itemized Pricing — re-verify password: shared gate before Edit or
     Delete on a price entry, mirrors logReverifyOverlay in
     logistic_content.php (aos_require_recent_reverify(), 120s window). -->
<div class="modal-overlay" id="itemPriceReverifyOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Confirm Password</div></div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Enter your password to continue</div>
        <input type="password" class="input" id="itemPriceReverifyPassword" placeholder="Password">
      </div>
      <div class="empty-sub" id="itemPriceReverifyError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnItemPriceReverifyCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnItemPriceReverifyConfirm">Confirm</button>
    </div>
  </div>
</div>

<!-- Itemized Pricing — Edit: opens only after itemPriceReverifyOverlay
     succeeds. Product/Unit are fixed (see update_customer_item_price.php),
     only Price and Date can change. -->
<div class="modal-overlay" id="itemPriceEditOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Edit Itemized Price</div></div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Product</div>
        <input type="text" class="input" id="itemPriceEditProductLabel" disabled>
      </div>
      <div class="form-group">
        <div class="label">Price</div>
        <input type="text" inputmode="decimal" class="input input-number-comma" id="itemPriceEditPrice" placeholder="Price">
      </div>
      <div class="form-group">
        <div class="label">Date</div>
        <input type="date" class="input" id="itemPriceEditDate">
      </div>
      <div class="empty-sub" id="itemPriceEditError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnItemPriceEditCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnItemPriceEditSave">Save</button>
    </div>
  </div>
</div>

<!-- Itemized Pricing — Delete confirmation: opens only after
     itemPriceReverifyOverlay succeeds. -->
<div class="modal-overlay" id="itemPriceDeleteOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Delete Itemized Price</div></div>
    <div class="modal-body">
      <div class="empty-sub" id="itemPriceDeleteWarning" style="color:var(--danger);"></div>
      <div class="empty-sub" id="itemPriceDeleteError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnItemPriceDeleteCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="btnItemPriceDeleteConfirm">Delete</button>
    </div>
  </div>
</div>

<!-- Delete Activity Code — confirmation + data-loss warning + password
     re-check. Same pattern as txnDeleteCustomerOverlay above. -->
<div class="modal-overlay" id="txnDeleteActivityCodeOverlay" style="display:none;">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <div class="modal-title">Delete Activity Code</div>
    </div>
    <div class="modal-body">
      <div class="empty-sub" style="color:var(--danger); margin-bottom:var(--space-3);">
        You're about to permanently delete <strong id="delActivityName">this activity code</strong>.
        This cannot be undone and all associated data will be lost.
      </div>
      <div class="form-group">
        <div class="label">Enter your password to confirm</div>
        <input type="password" class="input" id="delActivityPasswordInput" autocomplete="current-password">
      </div>
      <div class="empty-sub" id="delActivityError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnDeleteActivityCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="btnDeleteActivityConfirm">Delete</button>
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

<!-- ============================================================
     Transaction Logging — category chooser + Disbursement wizard.
     Sales Transaction / Other flows are not built yet; their buttons
     below intentionally show a "coming soon" placeholder for now. -->
<div class="modal-overlay" id="txnCategoryOverlay" style="display:none;">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between;">
      <div class="modal-title">Input Transaction</div>
      <button type="button" class="btn-icon" id="btnCloseTxnCategoryOverlay" aria-label="Close" style="font-size:18px; line-height:1; font-weight:700;">
        &times;
      </button>
    </div>
    <div class="modal-body">
      <button type="button" class="btn btn-primary" id="btnCategoryDisbursement">
        Disbursement
      </button>
      <button type="button" class="btn btn-secondary" id="btnCategorySales">
        Sales Transaction
      </button>
      <button type="button" class="btn btn-secondary" id="btnCategoryOther">
        Other
      </button>
    </div>
  </div>
</div>

<!-- Disbursement step 1: Department -->
<div class="modal-overlay" id="disbDepartmentOverlay" style="display:none;">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <div class="modal-title">Disbursement — Department</div>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Department</div>
        <select class="select" id="disbDepartment">
          <?php foreach ($departments as $dept): ?>
            <option value="<?= htmlspecialchars($dept['key']) ?>"><?= htmlspecialchars($dept['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="empty-sub" id="disbDepartmentError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnDisbDepartmentBack">Back</button>
      <button type="button" class="btn btn-primary" id="btnDisbDepartmentNext">Next</button>
    </div>
  </div>
</div>

<!-- Disbursement step 2: Activity Code (filtered by chosen department) -->
<div class="modal-overlay" id="disbActivityOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <div class="modal-title">Disbursement — Activity Code</div>
    </div>
    <div class="modal-body">
      <div class="form-group" id="disbActivityGroup">
        <div class="label">Activity Code</div>
        <select class="select" id="disbActivitySelect">
          <option value="">-- select activity code --</option>
        </select>
      </div>
      <div class="empty-state" id="disbActivityEmpty" style="display:none;">
        <div class="empty-title">No activity codes</div>
        <div class="empty-sub">This department has no activity codes yet. Create one first.</div>
      </div>
      <div class="empty-sub" id="disbActivityError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnDisbActivityBack">Back</button>
      <button type="button" class="btn btn-primary" id="btnDisbActivityNext">Next</button>
    </div>
  </div>
</div>

<!-- Disbursement step 3: Cashflow type (defaulted from activity) + Transaction Purpose -->
<div class="modal-overlay" id="disbDetailsOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <div class="modal-title">Disbursement — Details</div>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <div class="label">Activity Code</div>
        <input type="text" class="input" id="disbSelectedActivityLabel" disabled>
      </div>
      <div class="form-group">
        <div class="label">Cashflow Type</div>
        <select class="select" id="disbCashflow">
          <option value="inflow">Cash Inflows</option>
          <option value="outflow">Cash Outflows</option>
          <option value="in-out">Cash In-Out</option>
        </select>
        <div class="empty-sub">Defaults from the Activity Code, but you can change it.</div>
      </div>
      <div class="form-group">
        <div class="label">Transaction Purpose</div>
        <textarea class="input input-uppercase" id="disbPurpose" rows="3" maxlength="255" placeholder="What is this disbursement actually for?"></textarea>
        <div class="empty-sub">This is the real purpose — separate from whatever notes end up on the bank slip.</div>
      </div>
      <div class="empty-sub" id="disbDetailsError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnDisbDetailsBack">Back</button>
      <button type="button" class="btn btn-primary" id="btnDisbDetailsNext">Continue</button>
    </div>
  </div>
</div>

<!-- Disbursement — Field Picker: opens automatically right after a document
     is uploaded/rendered, and again after each field is scanned, so the
     user works through the field list one at a time without hunting for
     the right "Scan" button. Left = field name, right = current value
     (empty until scanned or typed). Click a row -> picker closes, canvas
     becomes draggable for that field -> OCR runs -> picker reopens. -->
<div class="modal-overlay" id="capFieldPickerOverlay" style="display:none;">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header">
      <div class="modal-title">Select a field to scan</div>
    </div>
    <div class="modal-body">
      <div class="accordion-list" id="capFieldPickerList" style="max-height:420px; overflow-y:auto;"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-primary" id="btnCapFieldPickerDone">Done</button>
    </div>
  </div>
</div>

<!-- Sales Transaction — "Which product is this?" for a line the parser did not
     recognize. Saving with "Remember" adds the wording to
     json_file/order_patterns/{activity_id}.json (ajax/save_order_alias.php). -->
<div class="modal-overlay" id="stProductOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <div class="modal-title">Which product is this?</div>
    </div>
    <div class="modal-body">
      <div class="empty-sub" id="stProductLine" style="word-break:break-word; margin-bottom:var(--space-3);"></div>
      <div class="form-group">
        <div class="label">Product</div>
        <select class="select" id="stProductSelect">
          <option value="">-- select product --</option>
        </select>
      </div>
      <div class="form-group" id="stProductRememberBox">
        <label style="display:flex; align-items:center; gap:var(--space-2); font-size:var(--text-sm); color:var(--text-secondary);">
          <input type="checkbox" id="stProductRemember" checked>
          <span>Remember &ldquo;<span id="stProductWording"></span>&rdquo; as this product</span>
        </label>
      </div>
      <div class="empty-sub" id="stProductError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnStProductSkip">Skip</button>
      <button type="button" class="btn btn-primary" id="btnStProductSave">Save</button>
    </div>
  </div>
</div>

<!-- Sales Transaction — price for products this customer has no price for yet.
     Saved to customer_item_prices (price_date = order date) together with the order. -->
<div class="modal-overlay" id="stPriceOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <div class="modal-title">Set Price</div>
    </div>
    <div class="modal-body">
      <div class="empty-sub" id="stPriceIntro" style="margin-bottom:var(--space-3);"></div>
      <div id="stPriceRows"></div>
      <div class="empty-sub" id="stPriceError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnStPriceCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnStPriceContinue">Continue</button>
    </div>
  </div>
</div>

<!-- Sales Transaction — order details to confirm. Nothing is saved until
     "Confirm & Save" (the preview comes from create_order.php with dry_run=1). -->
<div class="modal-overlay" id="stConfirmOverlay" style="display:none;">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <div class="modal-title">Confirm Order</div>
    </div>
    <div class="modal-body">
      <div id="stConfirmBody"></div>
      <div class="empty-sub" id="stConfirmError" style="display:none; color:var(--danger); white-space:pre-line; margin-top:var(--space-3);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnStConfirmCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnStConfirmSave">Confirm &amp; Save</button>
    </div>
  </div>
</div>

<!-- Sales Transaction — shown after Read Message ONLY when the selected
     customer already has other order(s) on the same order date. Lets the
     user say whether this message is a standalone New Order, or should be
     merged into (Update) one of those existing orders — whether a given
     line ends up being a revision (qty replaced) or an addition (new line)
     is auto-detected per line once the target order is picked, so there is
     no separate "Add" vs "Revise" choice here. -->
<div class="modal-overlay" id="stOrderModeOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <div class="modal-title">This customer already has order(s) today</div>
    </div>
    <div class="modal-body">
      <div class="empty-sub" style="margin-bottom:var(--space-3);">
        Is this message a new, separate order — or does it add to / revise one already entered?
      </div>
      <div class="empty-sub" id="stOrderModeError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer" style="flex-wrap:wrap; gap:var(--space-2);">
      <button type="button" class="btn btn-secondary" id="btnStModeNew">New Order</button>
      <button type="button" class="btn btn-primary" id="btnStModeUpdate">Update Existing Order</button>
    </div>
  </div>
</div>

<!-- Sales Transaction — pick WHICH existing order (of possibly several today)
     to merge this message into. Newest order first. Each card shows full
     detail (time, driver, police number, every product line) so the user
     can tell them apart. -->
<div class="modal-overlay" id="stOrderPickOverlay" style="display:none;">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <div class="modal-title">Which order?</div>
    </div>
    <div class="modal-body">
      <div id="stOrderPickList"></div>
      <div class="empty-sub" id="stOrderPickError" style="display:none; color:var(--danger);"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnStOrderPickBack">Back</button>
    </div>
  </div>
</div>

<!-- Sales Transaction — blocking warning shown before Confirm Order when the
     driver and/or police number on this update differs from the target
     order's current value. Purely informational (the new value is still the
     one saved), but the user must acknowledge it before Confirm Order opens. -->
<div class="modal-overlay" id="stDriverWarnOverlay" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <div class="modal-title">Driver / Police Number Changed</div>
    </div>
    <div class="modal-body">
      <div class="empty-sub" style="color:var(--warning); margin-bottom:var(--space-3);">
        This update changes the driver and/or police number on the existing order.
        The new value below will be saved for this order.
      </div>
      <div id="stDriverWarnBody"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnStDriverWarnCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnStDriverWarnOk">OK, Continue</button>
    </div>
  </div>
</div>

<style>
  /* Visual side of the uppercase rule; the value itself is forced by JS + server. */
  .input-uppercase { text-transform: uppercase; }

  /* Fullscreen slip viewer. JS toggles display between none and flex. */
  .cap-viewer {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    z-index: 2000;
    flex-direction: column;
    background: var(--bg-recessed);
  }
  .cap-viewer-bar {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    padding-top: calc(var(--space-2) + env(safe-area-inset-top, 0px));
    background: var(--bg-surface);
  }
  .cap-viewer-info { font-weight: 600; font-size: var(--text-sm); }
  .cap-viewer-controls { display: flex; flex-wrap: wrap; gap: var(--space-2); }
  .cap-viewer-controls .btn {
    padding: var(--space-2) var(--space-3);
    font-size: var(--text-sm);
    justify-content: center;
  }
  .cap-viewer-hint {
    padding: var(--space-2) var(--space-3);
    font-size: var(--text-sm);
    color: var(--accent);
    background: var(--bg-surface);
  }
  .cap-viewer-stage {
    flex: 1 1 auto; min-height: 0;
    overflow: auto; overscroll-behavior: contain;
    padding: 8px;
  }
  .cap-viewer-stage canvas {
    display: block; margin: 0 auto; max-width: none;
    cursor: crosshair; touch-action: manipulation;
  }
  /* The field picker must sit above the fullscreen viewer. */
  #capFieldPickerOverlay { z-index: 2100 !important; }
</style>

<script>
(function () {
  var section = document.querySelector('.menu-section[data-section="transactions"]');

  var entryOverlay             = document.getElementById('txnEntryOverlay');
  var passwordOverlay          = document.getElementById('txnPasswordOverlay');
  var manageDeptOverlay        = document.getElementById('txnManageDeptOverlay');
  var deleteCustomerOverlay    = document.getElementById('txnDeleteCustomerOverlay');
  var deleteActivityCodeOverlay = document.getElementById('txnDeleteActivityCodeOverlay');
  var itemPriceAddOverlay       = document.getElementById('itemPriceAddOverlay');
  var itemPriceReverifyOverlay  = document.getElementById('itemPriceReverifyOverlay');
  var itemPriceEditOverlay      = document.getElementById('itemPriceEditOverlay');
  var itemPriceDeleteOverlay    = document.getElementById('itemPriceDeleteOverlay');

  // Transaction Logging — category chooser + Disbursement wizard overlays.
  var txnCategoryOverlay   = document.getElementById('txnCategoryOverlay');
  var disbDepartmentOverlay = document.getElementById('disbDepartmentOverlay');
  var disbActivityOverlay   = document.getElementById('disbActivityOverlay');
  var disbDetailsOverlay    = document.getElementById('disbDetailsOverlay');
  var capFieldPickerOverlay = document.getElementById('capFieldPickerOverlay');

  // Sales Transaction fly windows.
  var stProductOverlay   = document.getElementById('stProductOverlay');
  var stPriceOverlay     = document.getElementById('stPriceOverlay');
  var stConfirmOverlay   = document.getElementById('stConfirmOverlay');
  var stOrderModeOverlay = document.getElementById('stOrderModeOverlay');
  var stOrderPickOverlay = document.getElementById('stOrderPickOverlay');
  var stDriverWarnOverlay = document.getElementById('stDriverWarnOverlay');

  var viewEmpty        = document.getElementById('viewTransactionsEmpty');
  var viewForm         = document.getElementById('viewCreateActivityCode');
  var viewResult       = document.getElementById('viewActivityCodeResult');
  var viewCustomerList = document.getElementById('viewCustomerList');
  var viewDisbursementCapture = document.getElementById('viewDisbursementCapture');
  var viewSales        = document.getElementById('viewSalesTransaction');

  var flexOverlays = [entryOverlay, passwordOverlay, manageDeptOverlay, deleteCustomerOverlay,
    deleteActivityCodeOverlay, itemPriceAddOverlay, itemPriceReverifyOverlay,
    itemPriceEditOverlay, itemPriceDeleteOverlay,
    txnCategoryOverlay, disbDepartmentOverlay, disbActivityOverlay, disbDetailsOverlay,
    capFieldPickerOverlay, stProductOverlay, stPriceOverlay, stConfirmOverlay,
    stOrderModeOverlay, stOrderPickOverlay, stDriverWarnOverlay];

  function show(el) {
    el.style.display = (flexOverlays.indexOf(el) !== -1) ? 'flex' : 'block';
  }
  function hide(el) { el.style.display = 'none'; }

  // ---------- Number inputs: thousand-comma formatting while typing ----------
  // Same helper as logistic_content.php (Price field in Itemized Pricing
  // reuses the .input-number-comma pattern) — duplicated here rather than
  // shared, since each *_content.php is its own self-contained IIFE.
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

  // ---------- Uppercase-while-typing for free-text fields ----------
  // Same pattern as logistic_content.php's .input-uppercase handling —
  // duplicated here since each *_content.php stays self-contained.
  document.querySelectorAll('.input-uppercase').forEach(function (el) {
    el.addEventListener('input', function () {
      var pos = el.selectionStart;
      el.value = el.value.toUpperCase();
      el.setSelectionRange(pos, pos);
    });
  });

  function showOnlyView(target) {
    [viewEmpty, viewForm, viewResult, viewCustomerList, viewDisbursementCapture, viewSales].forEach(hide);
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
        hide(deleteActivityCodeOverlay);
        hide(itemPriceAddOverlay);
        hide(itemPriceReverifyOverlay);
        hide(itemPriceEditOverlay);
        hide(itemPriceDeleteOverlay);
        hide(txnCategoryOverlay);
        hide(disbDepartmentOverlay);
        hide(disbActivityOverlay);
        hide(disbDetailsOverlay);
        hide(capFieldPickerOverlay);
        if (typeof resetSalesForm === 'function') resetSalesForm();
        disbState = { department_key: null, department_label: null, activity_id: null,
          activity_name: null, activity_code: null, cashflow: null, purpose: '' };
        if (typeof resetCaptureForm === 'function') resetCaptureForm();
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
    hide(entryOverlay);
    show(txnCategoryOverlay);
  });

  // --- Transaction Category chooser ---
  document.getElementById('btnCloseTxnCategoryOverlay').addEventListener('click', function () {
    hide(txnCategoryOverlay);
    show(entryOverlay);
  });

  document.getElementById('btnCategorySales').addEventListener('click', function () {
    openSalesView();
  });

  document.getElementById('btnCategoryOther').addEventListener('click', function () {
    alert('Other: coming soon.');
  });

  // --- Disbursement wizard state (reset whenever the wizard is abandoned,
  // see the "left the Transactions section" branch above too) ---
  var disbState = { department_key: null, department_label: null, activity_id: null,
    activity_name: null, activity_code: null, cashflow: null, purpose: '' };

  document.getElementById('btnCategoryDisbursement').addEventListener('click', function () {
    hide(txnCategoryOverlay);
    document.getElementById('disbDepartmentError').style.display = 'none';
    // Default the select to whatever was picked last time, if anything.
    if (disbState.department_key) {
      document.getElementById('disbDepartment').value = disbState.department_key;
    }
    show(disbDepartmentOverlay);
  });

  // --- Disbursement step 1: Department ---
  document.getElementById('btnDisbDepartmentBack').addEventListener('click', function () {
    hide(disbDepartmentOverlay);
    show(txnCategoryOverlay);
  });

  document.getElementById('btnDisbDepartmentNext').addEventListener('click', function () {
    var sel = document.getElementById('disbDepartment');
    disbState.department_key = sel.value;
    disbState.department_label = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : sel.value;
    hide(disbDepartmentOverlay);
    renderDisbActivityList();
    show(disbActivityOverlay);
  });

  // --- Disbursement step 2: Activity Code list, filtered by department ---
  // Reuses loadActivityList()/activityListCache from the Create Activity
  // Code tab (same ajax/list_activity_codes.php, same row shape: id,
  // activity_name, activity_code, department, department_key, cashflow,
  // relative_path) instead of hitting a separate endpoint.
  function renderDisbActivityList() {
    var selectEl = document.getElementById('disbActivitySelect');
    var groupEl  = document.getElementById('disbActivityGroup');
    var emptyEl  = document.getElementById('disbActivityEmpty');
    var errEl    = document.getElementById('disbActivityError');
    var nextBtn  = document.getElementById('btnDisbActivityNext');
    errEl.style.display = 'none';
    selectEl.innerHTML = '<option value="">-- select activity code --</option>';
    disbActivityById = {};

    function paint(rows) {
      var filtered = (rows || []).filter(function (a) {
        return a.department_key === disbState.department_key;
      });
      if (filtered.length === 0) {
        groupEl.style.display = 'none';
        emptyEl.style.display = 'block';
        nextBtn.disabled = true;
        return;
      }
      groupEl.style.display = 'block';
      emptyEl.style.display = 'none';
      nextBtn.disabled = false;
      filtered.forEach(function (a) {
        disbActivityById[a.id] = a;
        var opt = document.createElement('option');
        opt.value = a.id;
        opt.textContent = a.activity_code + ' \u2014 ' + a.activity_name + ' (' + a.cashflow + ')';
        selectEl.appendChild(opt);
      });
      // Coming back from the next step -> keep what was chosen.
      if (disbState.activity_id && disbActivityById[disbState.activity_id]) {
        selectEl.value = String(disbState.activity_id);
      }
    }

    if (typeof activityListCache !== 'undefined' && activityListCache && activityListCache.length) {
      paint(activityListCache);
    } else {
      loadActivityList().then(function () { paint(activityListCache || []); });
    }
  }
  var disbActivityById = {};

  document.getElementById('btnDisbActivityNext').addEventListener('click', function () {
    var errEl = document.getElementById('disbActivityError');
    var a = disbActivityById[document.getElementById('disbActivitySelect').value];
    if (!a) {
      errEl.textContent = 'Please select an Activity Code.';
      errEl.style.display = 'block';
      return;
    }
    errEl.style.display = 'none';
    disbState.activity_id = a.id;
    disbState.activity_name = a.activity_name;
    disbState.activity_code = a.activity_code;
    disbState.cashflow = a.cashflow; // default, still editable in the next step
    hide(disbActivityOverlay);
    document.getElementById('disbSelectedActivityLabel').value = a.activity_code + ' \u2014 ' + a.activity_name;
    document.getElementById('disbCashflow').value = a.cashflow;
    document.getElementById('disbPurpose').value = disbState.purpose || '';
    document.getElementById('disbDetailsError').style.display = 'none';
    show(disbDetailsOverlay);
  });

  document.getElementById('btnDisbActivityBack').addEventListener('click', function () {
    hide(disbActivityOverlay);
    show(disbDepartmentOverlay);
  });

  // --- Disbursement step 3: Cashflow + Purpose ---
  document.getElementById('btnDisbDetailsBack').addEventListener('click', function () {
    hide(disbDetailsOverlay);
    renderDisbActivityList();
    show(disbActivityOverlay);
  });

  document.getElementById('btnDisbDetailsNext').addEventListener('click', function () {
    var errBox = document.getElementById('disbDetailsError');
    var purpose = document.getElementById('disbPurpose').value.trim();
    if (!purpose) {
      errBox.textContent = 'Transaction Purpose is required.';
      errBox.style.display = 'block';
      return;
    }
    disbState.cashflow = document.getElementById('disbCashflow').value;
    disbState.purpose = purpose.toUpperCase();
    hide(disbDetailsOverlay);
    resetCaptureForm();
    showOnlyView(viewDisbursementCapture);
  });

  document.getElementById('btnDisbCaptureBack').addEventListener('click', function () {
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });

  // ==========================================================
  // Disbursement — Document capture (upload, viewer, semi-automatic
  // block-to-OCR via Tesseract.js, manual entry, save).
  // ==========================================================

  // pdf.js / Tesseract.js are loaded lazily (only once, only when this
  // section is actually used) rather than unconditionally in <head>, so
  // pages that never touch Transactions don't pay for them.
  var libLoadPromise = null;
  function ensureCaptureLibs() {
    if (libLoadPromise) return libLoadPromise;
    libLoadPromise = new Promise(function (resolve, reject) {
      var pending = 2;
      function done() { pending--; if (pending === 0) resolve(); }
      function fail(src) { return function () { reject(new Error('Failed to load ' + src)); }; }

      if (window.pdfjsLib) {
        done();
      } else {
        var s1 = document.createElement('script');
        s1.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        s1.onload = function () {
          window.pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
          done();
        };
        s1.onerror = fail('pdf.js');
        document.head.appendChild(s1);
      }

      if (window.Tesseract) {
        done();
      } else {
        var s2 = document.createElement('script');
        s2.src = 'https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.0.4/tesseract.min.js';
        s2.onload = done;
        s2.onerror = fail('tesseract.js');
        document.head.appendChild(s2);
      }
    });
    return libLoadPromise;
  }

  var capFileInput   = document.getElementById('capFileInput');
  var capViewerWrap  = document.getElementById('capViewerWrap');
  document.body.appendChild(capViewerWrap); // fullscreen: keep it out of any clipped/transformed ancestor
  var capStage       = document.getElementById('capStage');
  var capZoomLabel   = document.getElementById('capZoomLabel');
  var btnCapOpenViewer = document.getElementById('btnCapOpenViewer');
  var capCanvas      = document.getElementById('capCanvas');
  var capCtx         = capCanvas.getContext('2d');
  var capPageInfo    = document.getElementById('capPageInfo');
  var btnCapPrevPage = document.getElementById('btnCapPrevPage');
  var btnCapNextPage = document.getElementById('btnCapNextPage');
  var capBlockHint   = document.getElementById('capBlockHint');
  var capOcrBusy     = document.getElementById('capOcrBusy');
  var capExchangeRateGroup = document.getElementById('capExchangeRateGroup');
  var capCurrency    = document.getElementById('capCurrency');
  var capAmount      = document.getElementById('capAmount');
  var capExchangeRate = document.getElementById('capExchangeRate');
  var capFinalAmountIdr = document.getElementById('capFinalAmountIdr');

  var capDoc = null;       // pdf.js document instance, null if a plain image
  var capNumPages = 1;
  var capCurrentPage = 1;
  var capUploadedFile = null; // the raw File, sent as-is on Save
  var armedScanField = null;  // field id currently waiting for a drag-box, or null
  var scanTriggerSource = null; // 'picker' | 'manual' — decides what happens after OCR finishes
  var blockModeArmed = false; // false = user can still scroll/pan freely; true = next drag on canvas draws the crop box

  function capBlockHintArmingText(label) {
    return 'Field: "' + label + '". Scroll/zoom to find it, then double-tap (or double-click) the spot to start blocking. Triple-tap to skip this field.';
  }
  function capBlockHintDraggingText(label) {
    return 'Field: "' + label + '". Now drag a box around the value.';
  }

  function resetCaptureForm() {
    capFileInput.value = '';
    capUploadedFile = null;
    capDoc = null;
    capNumPages = 1;
    capCurrentPage = 1;
    armedScanField = null;
    scanTriggerSource = null;
    blockModeArmed = false;
    capCanvas.style.cursor = '';
    tapCount = 0;
    if (tapTimer) clearTimeout(tapTimer);
    capViewerWrap.style.display = 'none';
    capBlockHint.style.display = 'none';
    capZoom = 1;
    btnCapOpenViewer.style.display = 'none';
    hide(capFieldPickerOverlay);
    capCtx.clearRect(0, 0, capCanvas.width, capCanvas.height);
    ['capDate', 'capSourceBank', 'capDestBank', 'capSourceAccountNumber',
     'capSourceAccountName', 'capDestAccountNumber', 'capDestAccountName',
     'capNotes', 'capAmount', 'capExchangeRate'].forEach(function (id) {
      document.getElementById(id).value = '';
    });
    capFields.forEach(function (f) { updateScanButtonVisibility(f.id); });
    capCurrency.value = 'IDR';
    capFinalAmountIdr.value = '';
    capExchangeRateGroup.style.display = 'none';
    document.getElementById('capError').style.display = 'none';
  }

  capFileInput.addEventListener('change', function () {
    var file = capFileInput.files[0];
    if (!file) return;
    capUploadedFile = file;
    capZoom = 1;
    btnCapOpenViewer.style.display = 'inline-flex';
    document.getElementById('capError').style.display = 'none';

    ensureCaptureLibs().then(function () {
      if (file.type === 'application/pdf') {
        var reader = new FileReader();
        reader.onload = function () {
          window.pdfjsLib.getDocument({ data: new Uint8Array(reader.result) }).promise
            .then(function (pdf) {
              capDoc = pdf;
              capNumPages = pdf.numPages;
              capCurrentPage = 1;
              renderCapPage(true);
            })
            .catch(function () {
              document.getElementById('capError').textContent = 'Could not read this PDF.';
              document.getElementById('capError').style.display = 'block';
            });
        };
        reader.readAsArrayBuffer(file);
      } else {
        capDoc = null;
        capNumPages = 1;
        capCurrentPage = 1;
        var img = new Image();
        img.onload = function () {
          capCanvas.width = img.naturalWidth;
          capCanvas.height = img.naturalHeight;
          capCtx.drawImage(img, 0, 0);
          openCapViewer();
          updateCapPageControls();
          openFieldPicker();
        };
        img.src = URL.createObjectURL(file);
      }
    }).catch(function (err) {
      document.getElementById('capError').textContent = 'Could not load the document viewer (' + err.message + ').';
      document.getElementById('capError').style.display = 'block';
    });
  });

  // ---- Fullscreen viewer: open / zoom / close ----
  var capZoom = 1; // 1 = fit to viewer width
  var CAP_ZOOM_MIN = 0.5, CAP_ZOOM_MAX = 4, CAP_ZOOM_STEP = 0.25;

  function applyCapZoom() {
    var stageW = capStage.clientWidth;
    if (!stageW) return; // viewer is hidden, nothing to size yet
    var fitW = Math.max(200, stageW - 16);
    capCanvas.style.width = Math.round(fitW * capZoom) + 'px';
    capCanvas.style.height = 'auto';
    capZoomLabel.textContent = Math.round(capZoom * 100) + '%';
  }

  function openCapViewer() {
    capViewerWrap.style.display = 'flex';
    applyCapZoom();
  }

  function setCapZoom(next) {
    next = Math.min(CAP_ZOOM_MAX, Math.max(CAP_ZOOM_MIN, next));
    if (next === capZoom) return;
    // Keep whatever is at the centre of the view roughly in place.
    var cx = (capStage.scrollLeft + capStage.clientWidth / 2) / Math.max(1, capStage.scrollWidth);
    var cy = (capStage.scrollTop + capStage.clientHeight / 2) / Math.max(1, capStage.scrollHeight);
    capZoom = next;
    applyCapZoom();
    capStage.scrollLeft = cx * capStage.scrollWidth - capStage.clientWidth / 2;
    capStage.scrollTop = cy * capStage.scrollHeight - capStage.clientHeight / 2;
  }

  document.getElementById('btnCapZoomIn').addEventListener('click', function () { setCapZoom(capZoom + CAP_ZOOM_STEP); });
  document.getElementById('btnCapZoomOut').addEventListener('click', function () { setCapZoom(capZoom - CAP_ZOOM_STEP); });
  document.getElementById('btnCapZoomFit').addEventListener('click', function () { setCapZoom(1); });
  window.addEventListener('resize', function () {
    if (capViewerWrap.style.display === 'flex') applyCapZoom();
  });

  // Closes the viewer and cancels any half-finished "arm a field" state.
  function closeCapViewer() {
    hide(capFieldPickerOverlay);
    capViewerWrap.style.display = 'none';
    armedScanField = null;
    scanTriggerSource = null;
    blockModeArmed = false;
    capCanvas.style.cursor = '';
    tapCount = 0;
    if (tapTimer) clearTimeout(tapTimer);
    capBlockHint.style.display = 'none';
  }
  document.getElementById('btnCapViewerClose').addEventListener('click', closeCapViewer);
  btnCapOpenViewer.addEventListener('click', function () { if (capUploadedFile) openCapViewer(); });
  document.getElementById('btnCapFields').addEventListener('click', function () {
    if (!armedScanField) openFieldPicker();
  });

  function renderCapPage(openPickerAfter) {
    if (!capDoc) return;
    capDoc.getPage(capCurrentPage).then(function (page) {
      var viewport = page.getViewport({ scale: 2 }); // higher native resolution: sharper zoom + better OCR crops
      capCanvas.width = viewport.width;
      capCanvas.height = viewport.height;
      page.render({ canvasContext: capCtx, viewport: viewport }).promise.then(function () {
        openCapViewer();
        updateCapPageControls();
        if (openPickerAfter) openFieldPicker();
      });
    });
  }

  function updateCapPageControls() {
    if (capNumPages > 1) {
      capPageInfo.textContent = 'Page ' + capCurrentPage + ' of ' + capNumPages;
      btnCapPrevPage.style.display = capCurrentPage > 1 ? 'inline-block' : 'none';
      btnCapNextPage.style.display = capCurrentPage < capNumPages ? 'inline-block' : 'none';
    } else {
      capPageInfo.textContent = '';
      btnCapPrevPage.style.display = 'none';
      btnCapNextPage.style.display = 'none';
    }
  }

  btnCapPrevPage.addEventListener('click', function () {
    if (capCurrentPage > 1) { capCurrentPage--; renderCapPage(); }
  });
  btnCapNextPage.addEventListener('click', function () {
    if (capCurrentPage < capNumPages) { capCurrentPage++; renderCapPage(); }
  });

  // --- Field metadata, built from the Scan buttons' own data attributes so
  // there's a single source of truth (HTML) instead of a duplicate JS list.
  var capFields = Array.prototype.map.call(document.querySelectorAll('.btn-cap-scan'), function (btn) {
    return { id: btn.getAttribute('data-scan-field'), label: btn.getAttribute('data-scan-label'), btn: btn };
  });

  function scanButtonFor(fieldId) {
    var f = capFields.filter(function (f) { return f.id === fieldId; })[0];
    return f ? f.btn : null;
  }

  // Scan buttons are no longer tied to "field has a value". Every field has
  // one, but it stays hidden until the user focuses that field to type
  // manually (see focus/blur handlers below). This function now just hides it.
  function updateScanButtonVisibility(fieldId) {
    var btn = scanButtonFor(fieldId);
    if (btn) btn.classList.remove('visible');
  }

  // Reveal each field's Scan button the moment it has a value — whether
  // typed manually or filled by OCR — and keep it hidden while empty
  // (nothing to re-block yet).
  capFields.forEach(function (f) {
    var el = document.getElementById(f.id);
    el.addEventListener('focus', function () {
      capFields.forEach(function (o) { if (o.id !== f.id) o.btn.classList.remove('visible'); });
      delete f.btn.dataset.keep;
      f.btn.classList.add('visible');
    });
    el.addEventListener('blur', function () {
      // Small delay + "keep" flag so tapping the Scan button itself (which
      // blurs the input first) doesn't make the button vanish before the click lands.
      setTimeout(function () {
        if (!f.btn.dataset.keep && document.activeElement !== el) f.btn.classList.remove('visible');
      }, 250);
    });
    f.btn.addEventListener('pointerdown', function () { f.btn.dataset.keep = '1'; });
  });

  // --- Field Picker: opens right after upload, and again after each scan,
  // so the whole field list can be worked through without hunting for
  // individual Scan buttons. ---
  function openFieldPicker() {
    var listEl = document.getElementById('capFieldPickerList');
    listEl.innerHTML = '';
    capFields.forEach(function (f) {
      var val = document.getElementById(f.id).value;
      var row = document.createElement('div');
      row.className = 'accordion-row';
      row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:var(--space-3); padding:var(--space-3) 0; cursor:pointer; border-bottom:1px solid var(--shadow-dark);';

      var label = document.createElement('div');
      label.style.fontWeight = '600';
      label.textContent = f.label;

      var value = document.createElement('div');
      value.className = 'empty-sub';
      value.style.cssText = 'max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:right;';
      value.textContent = val ? val : '— empty —';

      row.appendChild(label);
      row.appendChild(value);
      row.addEventListener('click', function () {
        hide(capFieldPickerOverlay);
        scanTriggerSource = 'picker';
        armedScanField = f.id;
        blockModeArmed = false;
        capCanvas.style.cursor = '';
        capBlockHint.textContent = capBlockHintArmingText(f.label);
        capBlockHint.style.display = 'block';
      });
      listEl.appendChild(row);
    });
    show(capFieldPickerOverlay);
  }

  document.getElementById('btnCapFieldPickerDone').addEventListener('click', closeCapViewer);

  // --- Scan buttons next to each field: a targeted re-scan outside the
  // picker flow (e.g. fixing one field that OCR misread). Only visible
  // once that field has a value (see updateScanButtonVisibility above). ---
  capFields.forEach(function (f) {
    f.btn.addEventListener('click', function () {
      delete f.btn.dataset.keep;
      f.btn.classList.remove('visible');
      if (!capUploadedFile) {
        document.getElementById('capError').textContent = 'Upload a document first.';
        document.getElementById('capError').style.display = 'block';
        return;
      }
      scanTriggerSource = 'manual';
      armedScanField = f.id;
      blockModeArmed = false;
      capCanvas.style.cursor = '';
      capBlockHint.textContent = capBlockHintArmingText(f.label);
      capBlockHint.style.display = 'block';
      openCapViewer(); // re-open the document for reblocking
    });
  });

  // --- Tap/click counting: arms the actual block-drag mode after a
  // double-tap (so a single tap/scroll while hunting for the right spot
  // doesn't accidentally start drawing a box), and skips the current field
  // after a triple-tap (nothing found here, move on). Counted only while a
  // field is armed but block mode isn't ready yet — once dragging starts,
  // this is inert.
  var tapCount = 0;
  var tapTimer = null;
  var TAP_WINDOW_MS = 400;

  capCanvas.addEventListener('click', function () {
    if (!armedScanField || blockModeArmed) return;
    tapCount++;
    if (tapTimer) clearTimeout(tapTimer);
    tapTimer = setTimeout(function () {
      if (tapCount === 2) {
        blockModeArmed = true;
        capCanvas.style.cursor = 'crosshair';
        capBlockHint.textContent = capBlockHintDraggingText(currentArmedFieldLabel());
      } else if (tapCount >= 3) {
        skipCurrentField();
      }
      tapCount = 0;
    }, TAP_WINDOW_MS);
  });

  function currentArmedFieldLabel() {
    var f = capFields.filter(function (f) { return f.id === armedScanField; })[0];
    return f ? f.label : '';
  }

  function skipCurrentField() {
    var source = scanTriggerSource;
    armedScanField = null;
    scanTriggerSource = null;
    blockModeArmed = false;
    capCanvas.style.cursor = '';
    capBlockHint.style.display = 'none';
    afterScanCompleted(source);
  }

  // Rubber-band selection directly on the canvas, in canvas pixel space
  // (accounting for CSS scaling since the canvas is displayed at
  // max-width:100% but keeps its native pixel dimensions).
  var dragStart = null;
  var dragBox = null; // floating div drawn over the canvas while dragging

  function canvasPointFromEvent(e) {
    var rect = capCanvas.getBoundingClientRect();
    var scaleX = capCanvas.width / rect.width;
    var scaleY = capCanvas.height / rect.height;
    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top) * scaleY,
      clientX: e.clientX,
      clientY: e.clientY
    };
  }

  // Shared drag logic: mouse and touch both feed beginDrag/moveDrag/endDrag,
  // so the rubber-band box behaves the same on desktop and on a phone.
  function beginDrag(pt) {
    dragStart = pt;
    dragBox = document.createElement('div');
    dragBox.style.cssText = 'position:fixed; border:2px dashed var(--accent); background:rgba(0,120,255,0.15); pointer-events:none; z-index:9999;';
    document.body.appendChild(dragBox);
    positionDragBox(pt.clientX, pt.clientY, pt.clientX, pt.clientY);
  }

  function moveDrag(clientX, clientY) {
    if (!dragStart || !dragBox) return;
    positionDragBox(dragStart.clientX, dragStart.clientY, clientX, clientY);
  }

  function cancelDrag() {
    if (dragBox && dragBox.parentNode) dragBox.parentNode.removeChild(dragBox);
    dragBox = null;
    dragStart = null;
  }

  function endDrag(end) {
    if (!dragStart || !dragBox) return;
    document.body.removeChild(dragBox);
    dragBox = null;

    var x = Math.max(0, Math.min(dragStart.x, end.x));
    var y = Math.max(0, Math.min(dragStart.y, end.y));
    var w = Math.abs(end.x - dragStart.x);
    var h = Math.abs(end.y - dragStart.y);
    dragStart = null;

    if (w < 6 || h < 6) return; // treat as an accidental click/tap, not a real box
    runOcrOnRegion(x, y, w, h);
  }

  function positionDragBox(x1, y1, x2, y2) {
    var left = Math.min(x1, x2), top = Math.min(y1, y2);
    dragBox.style.left = left + 'px';
    dragBox.style.top = top + 'px';
    dragBox.style.width = Math.abs(x2 - x1) + 'px';
    dragBox.style.height = Math.abs(y2 - y1) + 'px';
  }

  // --- Mouse ---
  capCanvas.addEventListener('mousedown', function (e) {
    if (!armedScanField || !blockModeArmed) return; // still scrolling/hunting, not ready to draw yet
    beginDrag(canvasPointFromEvent(e));
    e.preventDefault();
  });
  document.addEventListener('mousemove', function (e) { moveDrag(e.clientX, e.clientY); });
  document.addEventListener('mouseup', function (e) {
    if (!dragStart || !dragBox) return;
    endDrag(canvasPointFromEvent(e));
  });

  // --- Touch ---
  // Before block mode is armed (double-tap), touches are left alone so the
  // page/viewer can still scroll and pinch-zoom. Once armed, touchstart is
  // preventDefault()'d (needs passive:false) so the drag draws a box instead
  // of scrolling the page.
  capCanvas.addEventListener('touchstart', function (e) {
    if (!armedScanField || !blockModeArmed || e.touches.length !== 1) return;
    beginDrag(canvasPointFromEvent(e.touches[0]));
    e.preventDefault();
  }, { passive: false });

  document.addEventListener('touchmove', function (e) {
    if (!dragStart || !dragBox || e.touches.length < 1) return;
    moveDrag(e.touches[0].clientX, e.touches[0].clientY);
    e.preventDefault(); // stop the page from scrolling while a box is being drawn
  }, { passive: false });

  document.addEventListener('touchend', function (e) {
    if (!dragStart || !dragBox) return;
    var t = e.changedTouches && e.changedTouches[0];
    if (!t) { cancelDrag(); return; }
    endDrag(canvasPointFromEvent(t));
  });

  document.addEventListener('touchcancel', cancelDrag);

  function runOcrOnRegion(x, y, w, h) {
    var fieldId = armedScanField;
    var source = scanTriggerSource;
    armedScanField = null;
    scanTriggerSource = null;
    blockModeArmed = false;
    capCanvas.style.cursor = '';
    capBlockHint.style.display = 'none';

    var crop = document.createElement('canvas');
    crop.width = w;
    crop.height = h;
    crop.getContext('2d').drawImage(capCanvas, x, y, w, h, 0, 0, w, h);

    capOcrBusy.style.display = 'block';
    ensureCaptureLibs().then(function () {
      return window.Tesseract.recognize(crop, 'eng');
    }).then(function (result) {
      capOcrBusy.style.display = 'none';
      applyOcrResult(fieldId, (result.data.text || '').trim());
      afterScanCompleted(source);
    }).catch(function () {
      capOcrBusy.style.display = 'none';
      document.getElementById('capError').textContent = 'OCR failed on that selection — try again or type it manually.';
      document.getElementById('capError').style.display = 'block';
      afterScanCompleted(source);
    });
  }

  // Picker flow -> reopen the picker so the user can continue through the
  // rest of the list. Manual re-scan flow -> just close the document back
  // up, nothing else changes.
  function afterScanCompleted(source) {
    if (source === 'picker') {
      openFieldPicker();
    } else {
      capViewerWrap.style.display = 'none';
    }
  }

  // ---- Date parsing for OCR text -------------------------------------------
  // Banks print dates in many shapes: 15-06-2026, 15-Jun-2026, 15 June 2026,
  // September 25, 2026, 2026/06/15, 15JUN2026 ... Returns "YYYY-MM-DD" or ''.
  // Month names are matched on their first 3 letters, English + Indonesian +
  // Malay (Agu/Ags/Ogos, Mei, Mac, Okt, Des, ...).
  var MONTH_BY_PREFIX = {
    jan: 1, feb: 2, mar: 3, mac: 3, apr: 4, may: 5, mei: 5, jun: 6, jul: 7,
    aug: 8, agu: 8, ags: 8, ogo: 8, sep: 9, oct: 10, okt: 10, nov: 11, dec: 12, des: 12
  };

  function parseOcrDate(raw) {
    var t = String(raw || '');

    // OCR often reads digits as letters inside number-like tokens (2O26, 1l).
    t = t.replace(/\b(?=[0-9OolI]*\d)[0-9OolI]+\b/g, function (tok) {
      return tok.replace(/[Oo]/g, '0').replace(/[lI]/g, '1');
    });
    t = t.toLowerCase()
         .replace(/(\d)(st|nd|rd|th)\b/g, '$1') // 25th -> 25
         .replace(/[\r\n,]+/g, ' ');

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function build(y, m, d) {
      y = Number(y); m = Number(m); d = Number(d);
      if (y < 100) y += 2000;
      if (y < 1990 || y > 2100 || m < 1 || m > 12 || d < 1) return '';
      if (d > new Date(y, m, 0).getDate()) return '';
      return y + '-' + pad(m) + '-' + pad(d);
    }

    // 1) Month written as a word.
    var month = 0;
    var words = t.match(/[a-z]{3,}/g) || [];
    for (var i = 0; i < words.length && !month; i++) {
      month = MONTH_BY_PREFIX[words[i].slice(0, 3)] || 0;
    }
    if (month) {
      // Also splits glued forms like 15jun2026 because \d+ ignores the letters.
      var nums = t.match(/\d+/g) || [];
      if (nums.length >= 2) {
        var first = nums[0], second = nums[1];
        var res = (first.length === 4 || Number(first) > 31)
          ? build(first, month, second)   // 2026 Jun 15
          : build(second, month, first);  // 15 Jun 2026 / Sep 25 2026
        if (res) return res;
      }
    }

    // 2) All numeric with separators. Day-first unless the numbers say otherwise.
    var m = t.match(/(\d{1,4})\s*[\/\-. ]\s*(\d{1,2})\s*[\/\-. ]\s*(\d{2,4})/);
    if (m) {
      var a = m[1], b = m[2], c = m[3], out = '';
      if (a.length === 4) {
        out = build(a, b, c);                                  // 2026-06-15
      } else if (c.length === 4 || c.length === 2) {
        out = (Number(b) > 12 && Number(a) <= 12)
          ? build(c, a, b)                                     // 06/25/2026 (month-first)
          : build(c, b, a);                                    // 15/06/2026 (day-first)
      }
      if (out) return out;
    }

    // 3) Compact 8 digits: 20260615 or 15062026.
    var compact = t.match(/\b(\d{8})\b/);
    if (compact) {
      var s = compact[1];
      return /^(19|20)/.test(s)
        ? build(s.slice(0, 4), s.slice(4, 6), s.slice(6, 8))
        : build(s.slice(4, 8), s.slice(2, 4), s.slice(0, 2));
    }
    return '';
  }

  // Best-effort cleanup per field type. The result is ALWAYS left editable
  // afterwards — this just saves typing when OCR reads cleanly.
  function applyOcrResult(fieldId, rawText) {
    var el = document.getElementById(fieldId);
    if (!el) return;

    if (fieldId === 'capDate') {
      var iso = parseOcrDate(rawText);
      var errEl = document.getElementById('capError');
      if (iso) {
        el.value = iso;
        if (errEl.textContent.indexOf('Could not read the date') === 0) errEl.style.display = 'none';
      } else {
        // A <input type="date"> silently drops invalid text, so say what happened.
        el.value = '';
        errEl.textContent = 'Could not read the date from "' + rawText.replace(/\s+/g, ' ').slice(0, 40) + '" — please pick it manually.';
        errEl.style.display = 'block';
      }
    } else if (fieldId === 'capAmount' || fieldId === 'capExchangeRate') {
      var numMatch = rawText.replace(/[^0-9.,]/g, '');
      var parsed = parseNumberInput(numMatch);
      el.value = isNaN(parsed) ? '' : formatNumberInput(String(parsed));
      recalcFinalAmount();
    } else if (fieldId === 'capCurrency') {
      el.value = normalizeCurrency(rawText);
      toggleExchangeRateVisibility();
    } else {
      el.value = rawText.replace(/\s+/g, ' ').trim().toUpperCase();
    }
    updateScanButtonVisibility(fieldId);
  }

  // --- Currency / amount / final amount interplay ---
  // Slips print local symbols (RM, Rp); we always store the ISO 4217 code.
  var CURRENCY_ALIASES = {
    'RM': 'MYR', 'RINGGIT': 'MYR', 'MYR': 'MYR',
    'RP': 'IDR', 'RUPIAH': 'IDR', 'IDR': 'IDR',
    'US$': 'USD', 'USD': 'USD'
  };
  function normalizeCurrency(raw) {
    var key = String(raw || '').toUpperCase().replace(/[\s.]/g, '');
    if (CURRENCY_ALIASES[key]) return CURRENCY_ALIASES[key];
    return key.replace(/[^A-Z]/g, '').slice(0, 10);
  }
  function toggleExchangeRateVisibility() {
    var isIdr = normalizeCurrency(capCurrency.value) === 'IDR';
    capExchangeRateGroup.style.display = isIdr ? 'none' : 'block';
    recalcFinalAmount();
  }

  function recalcFinalAmount() {
    var amount = parseNumberInput(capAmount.value);
    if (isNaN(amount)) return;
    var isIdr = normalizeCurrency(capCurrency.value) === 'IDR';
    if (isIdr) {
      capFinalAmountIdr.value = formatNumberInput(String(amount));
    } else {
      var rate = parseNumberInput(capExchangeRate.value);
      if (!isNaN(rate)) {
        capFinalAmountIdr.value = formatNumberInput(String(amount * rate));
      }
    }
  }

  capCurrency.addEventListener('input', toggleExchangeRateVisibility);
  // Manual typing: convert RM -> MYR, Rp -> IDR once the user leaves the field
  // (not per keystroke, so e.g. typing "RMB" isn't hijacked at "RM").
  capCurrency.addEventListener('change', function () {
    capCurrency.value = normalizeCurrency(capCurrency.value);
    toggleExchangeRateVisibility();
  });
  capAmount.addEventListener('input', recalcFinalAmount);
  capExchangeRate.addEventListener('input', recalcFinalAmount);

  // --- Save ---
  document.getElementById('btnCapSave').addEventListener('click', function () {
    var errBox = document.getElementById('capError');
    errBox.style.display = 'none';

    if (!capUploadedFile) {
      errBox.textContent = 'Upload the bank slip first.';
      errBox.style.display = 'block';
      return;
    }
    if (!document.getElementById('capDate').value) {
      errBox.textContent = 'Date is required.';
      errBox.style.display = 'block';
      return;
    }
    var amount = parseNumberInput(capAmount.value);
    if (isNaN(amount) || amount <= 0) {
      errBox.textContent = 'Transaction Amount is not valid.';
      errBox.style.display = 'block';
      return;
    }
    var finalAmount = parseNumberInput(capFinalAmountIdr.value);
    if (isNaN(finalAmount) || finalAmount <= 0) {
      errBox.textContent = 'Final Amount (IDR) is not valid.';
      errBox.style.display = 'block';
      return;
    }

    var payload = new FormData();
    payload.append('activity_id', disbState.activity_id);
    payload.append('cashflow_type', disbState.cashflow);
    payload.append('transaction_purpose', disbState.purpose);
    payload.append('transaction_date', document.getElementById('capDate').value);
    payload.append('source_bank', document.getElementById('capSourceBank').value);
    payload.append('destination_bank', document.getElementById('capDestBank').value);
    payload.append('source_account_number', document.getElementById('capSourceAccountNumber').value);
    payload.append('source_account_name', document.getElementById('capSourceAccountName').value);
    payload.append('destination_account_number', document.getElementById('capDestAccountNumber').value);
    payload.append('destination_account_name', document.getElementById('capDestAccountName').value);
    payload.append('notes', document.getElementById('capNotes').value);
    payload.append('currency', normalizeCurrency(capCurrency.value) || 'IDR');
    payload.append('amount', String(amount));
    var rate = parseNumberInput(capExchangeRate.value);
    payload.append('exchange_rate', isNaN(rate) ? '' : String(rate));
    payload.append('final_amount_idr', String(finalAmount));
    payload.append('document', capUploadedFile);

    var saveBtn = document.getElementById('btnCapSave');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    fetch('ajax/create_disbursement.php', { method: 'POST', body: payload })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Transaction';
        if (!res.ok) {
          errBox.textContent = res.message || 'Failed to save the transaction.';
          errBox.style.display = 'block';
          return;
        }
        alert('Disbursement saved.');
        showOnlyView(viewEmpty);
        show(entryOverlay);
      })
      .catch(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Transaction';
        errBox.textContent = 'Connection error while saving.';
        errBox.style.display = 'block';
      });
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
      if (editingActivityId !== null && a.id === editingActivityId) return false; // ignore self while editing
      return a.activity_name === name;
    });
    acActivityNameError.style.display = isDuplicate ? 'block' : 'none';
    btnCreateCodeSubmit.disabled = isDuplicate;
    return isDuplicate;
  }

  // null = Create Activity Code form is in "create" mode. Set to an
  // activity code's id while editing that row (see openEditActivityForm /
  // btnCreateCodeSubmit). Activity object currently pending deletion, set
  // by openDeleteActivityConfirm.
  var editingActivityId = null;
  var pendingDeleteActivity = null;

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

  // An item's OWN body — must be a direct child, not just the first
  // descendant, now that accordions are nested (customer row > price group
  // > price entry). A plain querySelector('.accordion-body') would still
  // find the right one today, but only by accident of DOM order.
  function ownAccordionBody(item) {
    return item.querySelector(':scope > .accordion-body');
  }

  function measureAccordionItem(item) {
    var body = ownAccordionBody(item);
    if (body) body.style.maxHeight = body.scrollHeight + 'px';
  }

  // Every open ancestor gets re-measured whenever a nested item opens,
  // closes or has content injected into it. Without this the parent keeps
  // the max-height it had when IT was opened, so anything the child adds
  // below that line is clipped — which is what made expanded price cards
  // look cut off. The measurement is repeated a few times because
  // max-height is animated (.2s): an immediate read returns a mid-transition
  // height, so the last pass is the one that lands on the final value.
  function refreshAncestorHeights(item) {
    function pass() {
      var node = item.parentElement;
      while (node) {
        var anc = node.closest('.accordion-item');
        if (!anc) break;
        if (anc.classList.contains('open')) measureAccordionItem(anc);
        node = anc.parentElement;
      }
    }
    pass();
    [60, 160, 260].forEach(function (ms) { setTimeout(pass, ms); });
  }

  function openAccordionItem(item) {
    item.classList.add('open');
    measureAccordionItem(item);
    refreshAncestorHeights(item);
  }

  function closeAccordionItem(item) {
    item.classList.remove('open');
    var body = ownAccordionBody(item);
    if (body) body.style.maxHeight = '0px';
    refreshAncestorHeights(item);
  }

  // Re-measure an already-open item's max-height. Needed after content is
  // added into it asynchronously (e.g. the Itemized Pricing list loading
  // after the customer row was already opened) — openAccordionItem() only
  // measures scrollHeight once, at the moment it's called.
  function refreshOpenHeight(item) {
    if (!item.classList.contains('open')) return;
    measureAccordionItem(item);
    refreshAncestorHeights(item);
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
        ['Relative Path', a.relative_path]
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
        openEditActivityForm(a);
      });

      var deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'btn btn-danger';
      deleteBtn.textContent = 'Delete';
      deleteBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openDeleteActivityConfirm(a);
      });

      actions.appendChild(editBtn);
      actions.appendChild(deleteBtn);
      bodyInner.appendChild(actions);

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
      } else if (name === 'create' && editingActivityId === null) {
        // Clicked the tab directly (not via an Edit button, which sets
        // editingActivityId itself before switching tabs) -> make sure the
        // form is in blank "new code" state, not leftover edit data.
        acFormModeLabel.style.display = 'none';
        btnCreateCodeSubmit.textContent = 'Save';
      }
    });
  });

  var acFormModeLabel = document.getElementById('acFormModeLabel');

  function resetCreateCodeForm() {
    editingActivityId = null;
    acFormModeLabel.style.display = 'none';
    btnCreateCodeSubmit.textContent = 'Save';
    acYear.value = '<?= htmlspecialchars($currentYear) ?>';
    document.getElementById('acActivityName').value = '';
    document.getElementById('acCashflow').value = 'inflow';
    document.getElementById('txnCreateCodeError').style.display = 'none';
    acActivityNameError.style.display = 'none';
    btnCreateCodeSubmit.disabled = false;
    setActiveAcTab('preview'); // land on Preview first, per spec
    loadActivityList();
    updatePathPreview();
  }

  // Switches the Create Activity Code tab into "editing" mode, pre-filled
  // with an existing activity code's data. Save (btnCreateCodeSubmit) then
  // routes to update_activity_code.php instead of create_activity_code.php
  // while this is set.
  function openEditActivityForm(a) {
    editingActivityId = a.id;
    acFormModeLabel.textContent = 'Editing ' + a.activity_name;
    acFormModeLabel.style.display = 'block';
    btnCreateCodeSubmit.textContent = 'Update';
    document.getElementById('txnCreateCodeError').style.display = 'none';
    acActivityNameError.style.display = 'none';
    btnCreateCodeSubmit.disabled = false;

    acYear.value = a.year;
    acDepartment.value = a.department_key;
    document.getElementById('acActivityName').value = a.activity_name;
    document.getElementById('acCashflow').value = a.cashflow;
    // Editing keeps the SAME code number — show the real (non-regenerated)
    // code/path here instead of calling updatePathPreview(), which would
    // hit preview_activity_code.php and compute the NEXT free number.
    acCodePreview.value = a.activity_code;
    acPathPreview.value = a.relative_path;

    setActiveAcTab('create');
  }

  document.getElementById('btnBackToEntryFromForm').addEventListener('click', function () {
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });

  document.getElementById('btnCreateCodeSubmit').addEventListener('click', function () {
    var errBox = document.getElementById('txnCreateCodeError');
    errBox.style.display = 'none';

    if (checkDuplicateName()) return; // don't even hit the server on a known duplicate

    var isEditing = editingActivityId !== null;
    var payloadFields = {
      year: acYear.value,
      departement: acDepartment.value,
      activity_name: document.getElementById('acActivityName').value,
      cashflow: document.getElementById('acCashflow').value
    };
    if (isEditing) payloadFields.id = editingActivityId;
    var payload = new URLSearchParams(payloadFields);

    fetch(isEditing ? 'ajax/update_activity_code.php' : 'ajax/create_activity_code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          if (isEditing) {
            // No generated-result view for edits (unlike a fresh create) —
            // just land back on the refreshed Preview tab.
            resetCreateCodeForm();
          } else {
            document.getElementById('resActivityCode').value = res.data.activity_code;
            document.getElementById('resRelativePath').value = res.data.relative_path;
            showOnlyView(viewResult);
          }
        } else {
          errBox.textContent = res.message || (isEditing ? 'Failed to update activity code.' : 'Failed to create activity code.');
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
      header.addEventListener('click', function () {
        toggleAccordionItem(item);
        // Lazy-load this customer's price history the first time their
        // row is opened (not on every render of clPreviewList), then just
        // recalculate the open max-height on subsequent opens since the
        // list content is already there.
        if (item.classList.contains('open')) {
          if (!item.dataset.pricesLoaded) {
            item.dataset.pricesLoaded = '1';
            loadItemPrices(c.id, itemPriceList, item);
          } else {
            refreshOpenHeight(item);
          }
        }
      });

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

      // --- Itemized Pricing: nested collapsible price list, still inside
      // this same customer card (not a separate tab/section). Loaded lazily
      // the first time this row is opened (see header click handler above).
      var pricingHeaderRow = document.createElement('div');
      pricingHeaderRow.className = 'accordion-row';
      pricingHeaderRow.style.marginTop = 'var(--space-3)';
      pricingHeaderRow.style.paddingTop = 'var(--space-3)';
      pricingHeaderRow.style.borderTop = '1px solid var(--border, rgba(255,255,255,0.08))';
      pricingHeaderRow.style.justifyContent = 'space-between';
      pricingHeaderRow.style.alignItems = 'center';

      var pricingLabel = document.createElement('div');
      pricingLabel.className = 'accordion-row-label';
      pricingLabel.textContent = 'Itemized Pricing';

      var addPriceBtn = document.createElement('button');
      addPriceBtn.type = 'button';
      addPriceBtn.className = 'btn btn-secondary';
      addPriceBtn.textContent = 'Add Price';
      addPriceBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openAddItemPrice(c, item);
      });

      pricingHeaderRow.appendChild(pricingLabel);
      pricingHeaderRow.appendChild(addPriceBtn);
      bodyInner.appendChild(pricingHeaderRow);

      var itemPriceList = document.createElement('div');
      itemPriceList.className = 'accordion-list';
      itemPriceList.style.marginTop = 'var(--space-2)';
      bodyInner.appendChild(itemPriceList);

      var itemPriceEmpty = document.createElement('div');
      itemPriceEmpty.className = 'empty-sub';
      itemPriceEmpty.textContent = 'No prices yet.';
      itemPriceEmpty.style.display = 'none';
      bodyInner.appendChild(itemPriceEmpty);
      itemPriceList.itemPriceEmptyEl = itemPriceEmpty; // stash for loadItemPrices()

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

  // --- Delete Activity Code: confirmation + data-loss warning + password ---
  var delActivityName          = document.getElementById('delActivityName');
  var delActivityPasswordInput = document.getElementById('delActivityPasswordInput');
  var delActivityError         = document.getElementById('delActivityError');
  var btnDeleteActivityConfirm = document.getElementById('btnDeleteActivityConfirm');

  function openDeleteActivityConfirm(a) {
    pendingDeleteActivity = a;
    delActivityName.textContent = a.activity_name;
    delActivityPasswordInput.value = '';
    delActivityError.style.display = 'none';
    show(deleteActivityCodeOverlay);
  }

  document.getElementById('btnDeleteActivityCancel').addEventListener('click', function () {
    pendingDeleteActivity = null;
    hide(deleteActivityCodeOverlay);
  });

  btnDeleteActivityConfirm.addEventListener('click', function () {
    if (!pendingDeleteActivity) return;
    var pwd = delActivityPasswordInput.value;
    delActivityError.style.display = 'none';

    if (pwd === '') {
      delActivityError.textContent = 'Password is required.';
      delActivityError.style.display = 'block';
      return;
    }

    var payload = new URLSearchParams({
      id: pendingDeleteActivity.id,
      password: pwd
    });

    fetch('ajax/delete_activity_code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          // If the deleted row was mid-edit, drop back to create mode.
          if (editingActivityId === pendingDeleteActivity.id) resetCreateCodeForm();
          pendingDeleteActivity = null;
          hide(deleteActivityCodeOverlay);
          loadActivityList();
        } else {
          delActivityError.textContent = res.message || 'Failed to delete activity code.';
          delActivityError.style.display = 'block';
        }
      })
      .catch(function () {
        delActivityError.textContent = 'Connection error.';
        delActivityError.style.display = 'block';
      });
  });

  // ---------- Itemized Pricing (nested inside each Customer List row) ----------
  var itemPriceAddCustomerLabel = document.getElementById('itemPriceAddCustomerLabel');
  var itemPriceAddProduct   = document.getElementById('itemPriceAddProduct');
  var itemPriceAddPrice     = document.getElementById('itemPriceAddPrice');
  var itemPriceAddDate      = document.getElementById('itemPriceAddDate');
  var itemPriceAddUnit      = document.getElementById('itemPriceAddUnit');
  var itemPriceAddError     = document.getElementById('itemPriceAddError');

  var itemPriceReverifyPassword = document.getElementById('itemPriceReverifyPassword');
  var itemPriceReverifyError    = document.getElementById('itemPriceReverifyError');

  var itemPriceEditProductLabel = document.getElementById('itemPriceEditProductLabel');
  var itemPriceEditPrice    = document.getElementById('itemPriceEditPrice');
  var itemPriceEditDate     = document.getElementById('itemPriceEditDate');
  var itemPriceEditError    = document.getElementById('itemPriceEditError');

  var itemPriceDeleteWarning = document.getElementById('itemPriceDeleteWarning');
  var itemPriceDeleteError   = document.getElementById('itemPriceDeleteError');

  initNumberCommaInput(itemPriceAddPrice);
  initNumberCommaInput(itemPriceEditPrice);

  var priceableProductsCache = null; // fetched once, reused for every Add Price open

  function loadPriceableProducts(callback) {
    if (priceableProductsCache) { callback(priceableProductsCache); return; }
    fetch('ajax/list_priceable_products.php')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        priceableProductsCache = res.ok ? res.data : [];
        callback(priceableProductsCache);
      })
      .catch(function () { callback([]); });
  }

  // --- Add Price ---
  var pendingAddPriceCustomer = null; // {id, customer_name, ...}
  var pendingAddPriceItem     = null; // the .accordion-item DOM node, to refresh after save
  var pendingAddPriceListEl   = null; // that item's nested .accordion-list

  function openAddItemPrice(customer, item) {
    pendingAddPriceCustomer = customer;
    pendingAddPriceItem = item;
    pendingAddPriceListEl = item.querySelector('.accordion-list');

    itemPriceAddCustomerLabel.value = customer.customer_name;
    itemPriceAddPrice.value = '';
    itemPriceAddDate.value = todayISO(); // defaults to the day it's being entered

    itemPriceAddUnit.value = '';
    itemPriceAddUnit.placeholder = '-- select a product first --';
    itemPriceAddError.style.display = 'none';

    loadPriceableProducts(function (products) {
      itemPriceAddProduct.innerHTML = '<option value="">-- select product --</option>';
      products.forEach(function (p) {
        var opt = document.createElement('option');
        opt.value = p.logistic_id;
        opt.textContent = p.activity_name;
        opt.setAttribute('data-unit', p.primary_unit_label || '');
        itemPriceAddProduct.appendChild(opt);
      });
      show(itemPriceAddOverlay);
    });
  }

  itemPriceAddProduct.addEventListener('change', function () {
    var opt = itemPriceAddProduct.selectedOptions[0];
    itemPriceAddUnit.value = opt ? (opt.getAttribute('data-unit') || '') : '';
  });

  document.getElementById('btnItemPriceAddCancel').addEventListener('click', function () {
    hide(itemPriceAddOverlay);
    pendingAddPriceCustomer = null;
    pendingAddPriceItem = null;
  });

  document.getElementById('btnItemPriceAddSave').addEventListener('click', function () {
    itemPriceAddError.style.display = 'none';
    if (!pendingAddPriceCustomer) return;

    var priceClean = parseNumberInput(itemPriceAddPrice.value);
    if (!itemPriceAddProduct.value) {
      itemPriceAddError.textContent = 'Product wajib dipilih.';
      itemPriceAddError.style.display = 'block';
      return;
    }
    if (isNaN(priceClean) || priceClean < 0) {
      itemPriceAddError.textContent = 'Price tidak valid.';
      itemPriceAddError.style.display = 'block';
      return;
    }
    if (!itemPriceAddDate.value) {
      itemPriceAddError.textContent = 'Date wajib diisi.';
      itemPriceAddError.style.display = 'block';
      return;
    }

    var payload = new URLSearchParams({
      customer_id: pendingAddPriceCustomer.id,
      logistic_id: itemPriceAddProduct.value,
      price: String(priceClean),
      price_date: itemPriceAddDate.value
    });

    fetch('ajax/create_customer_item_price.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) {
          itemPriceAddError.textContent = res.message || 'Gagal menyimpan price.';
          itemPriceAddError.style.display = 'block';
          return;
        }
        hide(itemPriceAddOverlay);
        var item = pendingAddPriceItem;
        var listEl = pendingAddPriceListEl;
        var customerId = pendingAddPriceCustomer.id;
        pendingAddPriceCustomer = null;
        pendingAddPriceItem = null;
        pendingAddPriceListEl = null;
        if (item && listEl) loadItemPrices(customerId, listEl, item); // re-fetch + re-render this row's list
      })
      .catch(function () {
        itemPriceAddError.textContent = 'Gagal menghubungi server.';
        itemPriceAddError.style.display = 'block';
      });
  });

  // --- Load + render the nested price list for one customer row ---
  function loadItemPrices(customerId, listEl, item) {
    fetch('ajax/list_customer_item_prices.php?customer_id=' + encodeURIComponent(customerId))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        renderItemPriceList(res.ok ? res.data : [], listEl);
        refreshOpenHeight(item);
      })
      .catch(function () {
        renderItemPriceList([], listEl);
        refreshOpenHeight(item);
      });
  }

  // Display helpers. The DB stores price as DECIMAL(15,2), so it arrives as
  // a string like "145000.00" — shown as "IDR 145,000" when there are no
  // cents and "IDR 145,000.50" when there are.
  function formatIDR(value) {
    var n = parseFloat(value);
    if (isNaN(n)) return String(value);
    var hasCents = Math.round(n * 100) % 100 !== 0;
    return 'IDR ' + n.toLocaleString('en-US', {
      minimumFractionDigits: hasCents ? 2 : 0,
      maximumFractionDigits: 2
    });
  }

  var MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function formatPriceDate(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
    if (!m) return String(iso || '');
    return m[3] + ' ' + MONTH_SHORT[parseInt(m[2], 10) - 1] + ' ' + m[1];
  }

  // Today in the browser's local timezone, as YYYY-MM-DD for <input type=date>.
  // toISOString() is deliberately NOT used — it converts to UTC first, which
  // rolls the date back a day for anyone east of Greenwich (WIB included).
  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-'
      + String(d.getMonth() + 1).padStart(2, '0') + '-'
      + String(d.getDate()).padStart(2, '0');
  }

  // Rows arrive already sorted newest-first by the endpoint. They are folded
  // into one group per product (logistic_id), each group keeping that order
  // internally, and the groups themselves ordered by their newest price —
  // so whatever was updated most recently sits on top.
  function groupItemPrices(list) {
    var groups = [];
    var byLogistic = {};
    list.forEach(function (p) {
      var key = String(p.logistic_id);
      if (!byLogistic[key]) {
        byLogistic[key] = { logistic_id: p.logistic_id, activity_name: p.activity_name, entries: [] };
        groups.push(byLogistic[key]);
      }
      byLogistic[key].entries.push(p);
    });
    groups.forEach(function (g) {
      g.entries.sort(function (a, b) {
        if (a.price_date !== b.price_date) return a.price_date < b.price_date ? 1 : -1;
        return String(b.created_at).localeCompare(String(a.created_at));
      });
      g.latest = g.entries[0];
    });
    groups.sort(function (a, b) {
      if (a.latest.price_date !== b.latest.price_date) return a.latest.price_date < b.latest.price_date ? 1 : -1;
      return String(b.latest.created_at).localeCompare(String(a.latest.created_at));
    });
    return groups;
  }

  function buildPriceEntryItem(p, listEl) {
    var pItem = document.createElement('div');
    pItem.className = 'accordion-item';

    var pHeader = document.createElement('div');
    pHeader.className = 'accordion-header';

    var pTitle = document.createElement('span');
    pTitle.className = 'accordion-title';
    // Collapsed state shows the date too, so a group's history can be read
    // without opening every entry.
    pTitle.textContent = formatPriceDate(p.price_date) + ' — ' + formatIDR(p.price) + ' / ' + p.unit_label;

    var pChevron = document.createElement('i');
    pChevron.className = 'ti ti-chevron-down accordion-chevron';

    pHeader.appendChild(pTitle);
    pHeader.appendChild(pChevron);
    pHeader.addEventListener('click', function () { toggleAccordionItem(pItem); });

    var pBody = document.createElement('div');
    pBody.className = 'accordion-body';
    var pBodyInner = document.createElement('div');
    pBodyInner.className = 'accordion-body-inner';

    [
      ['Product', p.activity_name],
      ['Price', formatIDR(p.price)],
      ['Date', formatPriceDate(p.price_date)],
      ['Unit', p.unit_label]
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
      pBodyInner.appendChild(row);
    });

    var pActions = document.createElement('div');
    pActions.className = 'accordion-row';
    pActions.style.justifyContent = 'flex-end';
    pActions.style.gap = 'var(--space-2)';
    pActions.style.marginTop = 'var(--space-2)';

    var pEditBtn = document.createElement('button');
    pEditBtn.type = 'button';
    pEditBtn.className = 'btn btn-secondary';
    pEditBtn.textContent = 'Edit';
    pEditBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      openItemPriceReverify('edit', p, listEl);
    });

    var pDeleteBtn = document.createElement('button');
    pDeleteBtn.type = 'button';
    pDeleteBtn.className = 'btn btn-danger';
    pDeleteBtn.textContent = 'Delete';
    pDeleteBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      openItemPriceReverify('delete', p, listEl);
    });

    pActions.appendChild(pEditBtn);
    pActions.appendChild(pDeleteBtn);
    pBodyInner.appendChild(pActions);

    pBody.appendChild(pBodyInner);
    pItem.appendChild(pHeader);
    pItem.appendChild(pBody);
    return pItem;
  }

  // listEl = the customer row's nested .accordion-list. It now holds one
  // accordion item per PRODUCT; each product's own body holds a further
  // nested list with that product's price history.
  function renderItemPriceList(list, listEl) {
    listEl.innerHTML = '';
    var emptyEl = listEl.itemPriceEmptyEl;
    if (emptyEl) emptyEl.style.display = list.length ? 'none' : 'block';

    groupItemPrices(list).forEach(function (g) {
      var gItem = document.createElement('div');
      gItem.className = 'accordion-item';

      var gHeader = document.createElement('div');
      gHeader.className = 'accordion-header';

      var gTitle = document.createElement('span');
      gTitle.className = 'accordion-title';
      gTitle.textContent = g.activity_name + ' — ' + formatIDR(g.latest.price) + ' / ' + g.latest.unit_label;

      var gChevron = document.createElement('i');
      gChevron.className = 'ti ti-chevron-down accordion-chevron';

      gHeader.appendChild(gTitle);
      gHeader.appendChild(gChevron);
      gHeader.addEventListener('click', function () { toggleAccordionItem(gItem); });

      var gBody = document.createElement('div');
      gBody.className = 'accordion-body';
      var gBodyInner = document.createElement('div');
      gBodyInner.className = 'accordion-body-inner';

      var entryList = document.createElement('div');
      entryList.className = 'accordion-list';
      entryList.style.marginTop = 'var(--space-2)';
      g.entries.forEach(function (p) {
        // The Edit/Delete handlers still get the CUSTOMER's list element, not
        // this inner one — that's what loadItemPrices() re-renders afterwards.
        entryList.appendChild(buildPriceEntryItem(p, listEl));
      });

      gBodyInner.appendChild(entryList);
      gBody.appendChild(gBodyInner);
      gItem.appendChild(gHeader);
      gItem.appendChild(gBody);
      listEl.appendChild(gItem);
    });
  }

  // --- Re-verify password: shared gate before Edit or Delete on a price
  // entry, same pattern as logReverifyOverlay in logistic_content.php. ---
  var pendingItemPriceAction = null; // 'edit' | 'delete'
  var pendingItemPriceRow    = null; // row object from list_customer_item_prices.php
  var pendingItemPriceListEl = null; // that row's parent .accordion-list, to refresh after save/delete

  function openItemPriceReverify(action, row, listEl) {
    pendingItemPriceAction = action;
    pendingItemPriceRow = row;
    pendingItemPriceListEl = listEl;
    itemPriceReverifyPassword.value = '';
    itemPriceReverifyError.style.display = 'none';
    show(itemPriceReverifyOverlay);
    itemPriceReverifyPassword.focus();
  }

  document.getElementById('btnItemPriceReverifyCancel').addEventListener('click', function () {
    hide(itemPriceReverifyOverlay);
    pendingItemPriceAction = null;
    pendingItemPriceRow = null;
  });

  function submitItemPriceReverify() {
    var pwd = itemPriceReverifyPassword.value;
    itemPriceReverifyError.style.display = 'none';
    if (!pwd) {
      itemPriceReverifyError.textContent = 'Password wajib diisi.';
      itemPriceReverifyError.style.display = 'block';
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
          itemPriceReverifyError.textContent = res.message || 'Password salah.';
          itemPriceReverifyError.style.display = 'block';
          return;
        }
        hide(itemPriceReverifyOverlay);
        if (pendingItemPriceAction === 'edit') {
          openEditItemPrice(pendingItemPriceRow);
        } else if (pendingItemPriceAction === 'delete') {
          openDeleteItemPrice(pendingItemPriceRow);
        }
      })
      .catch(function () {
        itemPriceReverifyError.textContent = 'Gagal menghubungi server.';
        itemPriceReverifyError.style.display = 'block';
      });
  }

  document.getElementById('btnItemPriceReverifyConfirm').addEventListener('click', submitItemPriceReverify);
  itemPriceReverifyPassword.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); submitItemPriceReverify(); }
  });

  // --- Edit Itemized Price ---
  function openEditItemPrice(row) {
    itemPriceEditError.style.display = 'none';
    itemPriceEditProductLabel.value = row.activity_name + ' (' + row.unit_label + ')';
    itemPriceEditPrice.value = formatNumberInput(String(row.price));
    itemPriceEditDate.value = row.price_date;
    show(itemPriceEditOverlay);
  }

  document.getElementById('btnItemPriceEditCancel').addEventListener('click', function () {
    hide(itemPriceEditOverlay);
    pendingItemPriceRow = null;
  });

  document.getElementById('btnItemPriceEditSave').addEventListener('click', function () {
    itemPriceEditError.style.display = 'none';
    if (!pendingItemPriceRow) return;

    var priceClean = parseNumberInput(itemPriceEditPrice.value);
    if (isNaN(priceClean) || priceClean < 0) {
      itemPriceEditError.textContent = 'Price tidak valid.';
      itemPriceEditError.style.display = 'block';
      return;
    }
    if (!itemPriceEditDate.value) {
      itemPriceEditError.textContent = 'Date wajib diisi.';
      itemPriceEditError.style.display = 'block';
      return;
    }

    var payload = new URLSearchParams({
      id: pendingItemPriceRow.id,
      price: String(priceClean),
      price_date: itemPriceEditDate.value
    });

    fetch('ajax/update_customer_item_price.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success === false) {
          // Reverify window expired server-side between opening this modal
          // and hitting Save — send the user back through the password gate.
          hide(itemPriceEditOverlay);
          openItemPriceReverify('edit', pendingItemPriceRow, pendingItemPriceListEl);
          return;
        }
        if (!res.ok) {
          itemPriceEditError.textContent = res.message || 'Gagal menyimpan perubahan.';
          itemPriceEditError.style.display = 'block';
          return;
        }
        hide(itemPriceEditOverlay);
        var listEl = pendingItemPriceListEl;
        var customerId = pendingItemPriceRow.customer_id;
        var item = listEl ? listEl.closest('.accordion-item') : null;
        pendingItemPriceRow = null;
        if (listEl && item) loadItemPrices(customerId, listEl, item);
      })
      .catch(function () {
        itemPriceEditError.textContent = 'Gagal menghubungi server.';
        itemPriceEditError.style.display = 'block';
      });
  });

  // --- Delete Itemized Price ---
  function openDeleteItemPrice(row) {
    itemPriceDeleteError.style.display = 'none';
    itemPriceDeleteWarning.textContent = 'Delete the price entry for "' + row.activity_name + '" ('
      + formatIDR(row.price) + ' / ' + row.unit_label + ', ' + formatPriceDate(row.price_date) + ')? This cannot be undone.';
    show(itemPriceDeleteOverlay);
  }

  document.getElementById('btnItemPriceDeleteCancel').addEventListener('click', function () {
    hide(itemPriceDeleteOverlay);
    pendingItemPriceRow = null;
  });

  document.getElementById('btnItemPriceDeleteConfirm').addEventListener('click', function () {
    itemPriceDeleteError.style.display = 'none';
    if (!pendingItemPriceRow) return;

    fetch('ajax/delete_customer_item_price.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id: pendingItemPriceRow.id }).toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success === false) {
          hide(itemPriceDeleteOverlay);
          openItemPriceReverify('delete', pendingItemPriceRow, pendingItemPriceListEl);
          return;
        }
        if (!res.ok) {
          itemPriceDeleteError.textContent = res.message || 'Gagal menghapus entry.';
          itemPriceDeleteError.style.display = 'block';
          return;
        }
        hide(itemPriceDeleteOverlay);
        var listEl = pendingItemPriceListEl;
        var customerId = pendingItemPriceRow.customer_id;
        var item = listEl ? listEl.closest('.accordion-item') : null;
        pendingItemPriceRow = null;
        if (listEl && item) loadItemPrices(customerId, listEl, item);
      })
      .catch(function () {
        itemPriceDeleteError.textContent = 'Gagal menghubungi server.';
        itemPriceDeleteError.style.display = 'block';
      });
  });
  // ==========================================================
  // Sales Transaction — New Order (paste WhatsApp message) + Customers.
  // Endpoints: parse_order_message.php, save_order_alias.php,
  // create_order.php (dry_run=1 preview -> dry_run=0 save),
  // list_customers.php, list_customer_orders.php.
  // ==========================================================
  var stCustomer    = document.getElementById('stCustomer');
  var stMessage     = document.getElementById('stMessage');
  var btnStRead     = document.getElementById('btnStRead');
  var stReadError   = document.getElementById('stReadError');
  var stParsedBox   = document.getElementById('stParsedBox');
  var stDriver      = document.getElementById('stDriver');
  var stPolice      = document.getElementById('stPolice');
  var stItemList    = document.getElementById('stItemList');
  var stItemEmpty   = document.getElementById('stItemEmpty');
  var stIgnoredBox  = document.getElementById('stIgnoredBox');
  var stOrderDate   = document.getElementById('stOrderDate');
  // Changing the date by hand invalidates any order the user already picked
  // to add to / revise (it was for the old date) — fall back to New Order
  // and let the user re-check via Read Message / re-pick if they meant to
  // keep merging.
  stOrderDate.addEventListener('change', function () {
    stTargetOrder = null;
    stMovementIdByLogistic = {};
    stDriverWarnAck = false;
  });
  var stReviewError = document.getElementById('stReviewError');
  var btnStReview   = document.getElementById('btnStReview');
  var stSuccessBox  = document.getElementById('stSuccessBox');

  var stProductLine        = document.getElementById('stProductLine');
  var stProductSelect      = document.getElementById('stProductSelect');
  var stProductRememberBox = document.getElementById('stProductRememberBox');
  var stProductRemember    = document.getElementById('stProductRemember');
  var stProductWording     = document.getElementById('stProductWording');
  var stProductError       = document.getElementById('stProductError');
  var btnStProductSave     = document.getElementById('btnStProductSave');

  var stPriceIntro = document.getElementById('stPriceIntro');
  var stPriceRows  = document.getElementById('stPriceRows');
  var stPriceError = document.getElementById('stPriceError');

  var stConfirmBody  = document.getElementById('stConfirmBody');
  var stConfirmError = document.getElementById('stConfirmError');
  var btnStConfirmSave = document.getElementById('btnStConfirmSave');

  var stCustList  = document.getElementById('stCustList');
  var stCustEmpty = document.getElementById('stCustEmpty');

  var stOrderModeError = document.getElementById('stOrderModeError');
  var btnStModeNew      = document.getElementById('btnStModeNew');
  var btnStModeUpdate   = document.getElementById('btnStModeUpdate');
  var stOrderPickList   = document.getElementById('stOrderPickList');
  var stOrderPickError  = document.getElementById('stOrderPickError');
  var btnStOrderPickBack = document.getElementById('btnStOrderPickBack');

  var stDriverWarnBody    = document.getElementById('stDriverWarnBody');
  var btnStDriverWarnCancel = document.getElementById('btnStDriverWarnCancel');
  var btnStDriverWarnOk     = document.getElementById('btnStDriverWarnOk');

  var stItems        = []; // [{line, qty, product_text, logistic_id, activity_id, product_name, unit_label, remaining_qty}]
  var stProducts     = []; // every orderable product, sent by parse_order_message.php
  var stNewPrices    = {}; // logistic_id -> price typed in the Set Price window
  var stUnknownQueue = []; // lines still waiting for "which product is this?"
  var stPickerItem   = null;
  var stPriceMissing = [];
  var stSaving       = false;

  // New Order / Add to Existing / Revise Existing — set after Read Message
  // when the customer already has other order(s) on the same date.
  // stExistingOrders: raw list from check_existing_orders.php (newest first).
  // stTargetOrder: the order card the user picked (Add/Revise), or null for
  // a plain New Order. stMovementIdByLogistic: logistic_id -> movement_id of
  // the existing row to UPDATE, built by merging stItems into stTargetOrder.
  var stExistingOrders     = [];
  var stTargetOrder        = null;
  var stMovementIdByLogistic = {};

  // Driver/police-change warning (blocking): shown once before Confirm Order
  // opens, only when a target order is picked AND the driver name and/or
  // police number now differs from that order's current value. Acknowledging
  // it ("OK, Continue") just lets the flow proceed to Confirm Order — the
  // new value was always going to be saved either way (existing rule:
  // replace if the follow-up message has a value, keep the old one if it
  // doesn't); this only makes the change visible before it's saved.
  // Reset whenever a (new) target order is picked, so editing driver/police
  // again after acknowledging re-triggers the warning against the latest
  // values.
  var stDriverWarnAck = false;
  var stPendingConfirmOrder = null;

  function stPost(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data).toString()
    }).then(function (r) { return r.json(); });
  }

  function stShowError(el, msg) { el.textContent = msg; el.style.display = 'block'; }
  function stHideError(el) { el.textContent = ''; el.style.display = 'none'; }

  // "30.00" -> "30", "30.50" -> "30.5"
  function fmtQty(v) {
    var n = parseFloat(v);
    return isNaN(n) ? String(v) : String(n);
  }

  function stIgnoredReasonText(reason) {
    if (reason === 'unrecognized_text') return 'not an order line';
    if (reason === 'no_quantity') return 'no quantity found';
    if (reason === 'duplicate_driver') return 'second driver name';
    if (reason === 'duplicate_police_number') return 'second police number';
    return 'skipped';
  }

  function resetSalesForm() {
    stCustomer.value = '';
    stMessage.value = '';
    stItems = [];
    stProducts = [];
    stNewPrices = {};
    stUnknownQueue = [];
    stPickerItem = null;
    stPriceMissing = [];
    stSaving = false;
    stExistingOrders = [];
    stTargetOrder = null;
    stMovementIdByLogistic = {};
    stDriverWarnAck = false;
    stPendingConfirmOrder = null;
    btnStReview.disabled = false;
    btnStRead.disabled = false;
    btnStConfirmSave.disabled = false;
    stParsedBox.style.display = 'none';
    stDriver.value = '';
    stPolice.value = '';
    stItemList.innerHTML = '';
    stItemEmpty.style.display = 'none';
    stIgnoredBox.innerHTML = '';
    stIgnoredBox.style.display = 'none';
    stOrderDate.value = todayISO();
    stHideError(stReadError);
    stHideError(stReviewError);
    stHideError(stConfirmError);
    stHideError(stPriceError);
    stHideError(stProductError);
    stHideError(stOrderModeError);
    stHideError(stOrderPickError);
    stSuccessBox.style.display = 'none';
    hide(stProductOverlay);
    hide(stPriceOverlay);
    hide(stConfirmOverlay);
    hide(stOrderModeOverlay);
    hide(stOrderPickOverlay);
    hide(stDriverWarnOverlay);
  }

  // ---------- Customers (used by the dropdown and by the Customers tab) ----------
  function loadStCustomers() {
    return fetch('ajax/list_customers.php', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var list = res.ok ? res.data : [];
        var keep = stCustomer.value;
        stCustomer.innerHTML = '<option value="">-- select customer --</option>';
        list.forEach(function (c) {
          var opt = document.createElement('option');
          opt.value = c.id;
          opt.textContent = c.customer_name + ' (' + c.year + ')';
          stCustomer.appendChild(opt);
        });
        stCustomer.value = keep;
        return list;
      })
      .catch(function () { return []; });
  }

  // A price typed for one customer must never leak into another customer's order.
  stCustomer.addEventListener('change', function () { stNewPrices = {}; });

  function openSalesView() {
    hide(txnCategoryOverlay);
    resetSalesForm();
    setActiveStTab('order');
    showOnlyView(viewSales);
    loadStCustomers();
  }

  document.getElementById('btnBackToEntryFromSales').addEventListener('click', function () {
    resetSalesForm();
    showOnlyView(viewEmpty);
    show(entryOverlay);
  });

  // ---------- Tab 1: read the message ----------
  btnStRead.addEventListener('click', function () {
    stHideError(stReadError);
    stHideError(stReviewError);
    stSuccessBox.style.display = 'none';

    if (!stCustomer.value) { stShowError(stReadError, 'Select a customer first.'); return; }
    if (!stMessage.value.trim()) { stShowError(stReadError, 'Paste the order message first.'); return; }

    btnStRead.disabled = true;
    stPost('ajax/parse_order_message.php', { message: stMessage.value })
      .then(function (res) {
        btnStRead.disabled = false;
        if (!res.ok) { stShowError(stReadError, res.message || 'Could not read the message.'); return; }
        applyParsedMessage(res);
      })
      .catch(function () {
        btnStRead.disabled = false;
        stShowError(stReadError, 'Connection error.');
      });
  });

  function applyParsedMessage(res) {
    stProducts = res.products || [];
    stNewPrices = {};
    stItems = (res.items || []).map(function (it) {
      return {
        line: it.line,
        qty: it.qty,
        product_text: it.product_text,
        logistic_id: it.logistic_id,
        activity_id: it.activity_id,
        product_name: it.product_name,
        unit_label: it.unit_label,
        remaining_qty: null
      };
    });
    stItems.forEach(function (it) {
      if (!it.logistic_id) return;
      var p = stProducts.filter(function (x) { return x.logistic_id === it.logistic_id; })[0];
      if (p) it.remaining_qty = p.remaining_qty;
    });

    stDriver.value = res.driver_name || '';
    stPolice.value = res.police_number || '';

    stIgnoredBox.innerHTML = '';
    var ignored = res.ignored || [];
    if (ignored.length) {
      var head = document.createElement('div');
      head.textContent = 'Skipped lines:';
      stIgnoredBox.appendChild(head);
      ignored.forEach(function (g) {
        var d = document.createElement('div');
        d.textContent = '\u2022 ' + g.line + ' (' + stIgnoredReasonText(g.reason) + ')';
        d.style.wordBreak = 'break-word';
        stIgnoredBox.appendChild(d);
      });
      stIgnoredBox.style.display = 'block';
    } else {
      stIgnoredBox.style.display = 'none';
    }

    stParsedBox.style.display = 'block';
    renderStItems();

    stUnknownQueue = stItems.filter(function (it) { return !it.logistic_id; });
    if (stUnknownQueue.length) {
      nextUnknown(); // checkExistingOrders() runs after the queue drains, see nextUnknown()
    } else {
      checkExistingOrders();
    }
  }

  // ---------- New Order / Add to Existing / Revise Existing ----------
  // Runs once every product line is resolved (no more "which product is
  // this?" prompts pending). Only asks the user anything if the customer
  // already has other order(s) on the chosen order_date.
  function checkExistingOrders() {
    stExistingOrders = [];
    stTargetOrder = null;
    stMovementIdByLogistic = {};
    if (!stCustomer.value || !stOrderDate.value) return;

    var qs = new URLSearchParams({ customer_id: stCustomer.value, order_date: stOrderDate.value }).toString();
    fetch('ajax/check_existing_orders.php?' + qs, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok || !res.data || !res.data.has_existing) return;
        stExistingOrders = res.data.orders || [];
        if (stExistingOrders.length) openStOrderMode();
      })
      .catch(function () { /* silently fall back to New Order behaviour */ });
  }

  function openStOrderMode() {
    stHideError(stOrderModeError);
    show(stOrderModeOverlay);
  }

  btnStModeNew.addEventListener('click', function () {
    stTargetOrder = null;
    stMovementIdByLogistic = {};
    stDriverWarnAck = false;
    hide(stOrderModeOverlay);
  });

  btnStModeUpdate.addEventListener('click', function () { openStOrderPick(); });
  // Which existing order gets merged into is decided by clicking a card in
  // the picker; per-line the merge itself auto-detects add vs revise (same
  // product -> qty replaced, new product -> line added), per the user's
  // 21 Sep 2026 explanation. There is deliberately no separate "Add" vs
  // "Revise" button — the distinction was cosmetic only, decided per line,
  // not per order (simplified 21 Sep 2026).

  function stFormatOrderTime(createdAt) {
    // "2026-09-21 14:32:07" -> "14:32"
    if (!createdAt) return '';
    var m = String(createdAt).match(/(\d{2}):(\d{2})/);
    return m ? (m[1] + ':' + m[2]) : String(createdAt);
  }

  function openStOrderPick() {
    hide(stOrderModeOverlay);
    stHideError(stOrderPickError);
    stOrderPickList.innerHTML = '';

    stExistingOrders.forEach(function (ord) {
      var card = document.createElement('div');
      card.className = 'st-order-pick-card';
      card.setAttribute('tabindex', '0');
      card.setAttribute('role', 'button');

      var top = document.createElement('div');
      top.className = 'st-order-pick-top';
      var left = document.createElement('div');
      left.textContent = stFormatOrderTime(ord.created_at)
        + ' \u00b7 ' + (ord.driver_name || 'No driver')
        + ' \u00b7 ' + (ord.police_number || 'No police number');
      top.appendChild(left);
      card.appendChild(top);

      ord.items.forEach(function (it) {
        var line = document.createElement('div');
        line.className = 'st-mov-sub';
        line.textContent = it.activity_name + ': ' + fmtQty(it.qty) + ' ' + (it.unit_label || '');
        card.appendChild(line);
      });

      card.addEventListener('click', function () { pickStOrder(ord); });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickStOrder(ord); }
      });

      stOrderPickList.appendChild(card);
    });

    show(stOrderPickOverlay);
  }

  btnStOrderPickBack.addEventListener('click', function () {
    hide(stOrderPickOverlay);
    openStOrderMode();
  });

  // Merge the just-parsed message into the picked order:
  //   - product already in the target order -> qty is REPLACED (a revision)
  //   - product not in the target order     -> line is ADDED
  // Driver/police number: use what was just read if non-empty, otherwise
  // keep the target order's own values.
  function pickStOrder(ord) {
    stTargetOrder = ord;
    stMovementIdByLogistic = {};
    stDriverWarnAck = false; // re-warn against this (possibly different) order's own driver/police

    var byLogistic = {}; // logistic_id -> target order item
    ord.items.forEach(function (it) { byLogistic[it.logistic_id] = it; });

    // Lines already in stItems (from the message just read) that match a
    // product already in the target order: mark them for UPDATE.
    stItems.forEach(function (it) {
      if (it.logistic_id && byLogistic[it.logistic_id]) {
        stMovementIdByLogistic[it.logistic_id] = byLogistic[it.logistic_id].movement_id;
      }
    });

    // Target-order lines that the new message did NOT mention: keep them in
    // stItems unchanged (still part of the same order), so the review list
    // shows the full, merged order rather than just the new lines.
    ord.items.forEach(function (it) {
      var already = stItems.some(function (x) { return x.logistic_id === it.logistic_id; });
      if (already) return;
      stItems.push({
        line: it.activity_name,
        qty: parseFloat(it.qty),
        product_text: null,
        logistic_id: it.logistic_id,
        activity_id: null,
        product_name: it.activity_name,
        unit_label: it.unit_label,
        remaining_qty: null
      });
      stMovementIdByLogistic[it.logistic_id] = it.movement_id;
    });

    if (!stDriver.value.trim() && ord.driver_name) stDriver.value = ord.driver_name;
    if (!stPolice.value.trim() && ord.police_number) stPolice.value = ord.police_number;

    renderStItems();
    hide(stOrderPickOverlay);
  }

  function assignProduct(item, p) {
    item.logistic_id = p.logistic_id;
    item.activity_id = p.activity_id;
    item.product_name = p.activity_name;
    item.unit_label = p.unit_label;
    item.remaining_qty = p.remaining_qty;
  }

  function renderStItems() {
    stItemList.innerHTML = '';
    stItemEmpty.style.display = stItems.length ? 'none' : 'block';

    stItems.forEach(function (it, idx) {
      var row = document.createElement('div');
      row.className = 'st-item-row';

      var lineEl = document.createElement('div');
      lineEl.className = 'st-item-line';
      lineEl.textContent = it.line;

      var main = document.createElement('div');
      main.className = 'st-item-main';

      var name = document.createElement('div');
      name.className = 'st-item-name' + (it.logistic_id ? '' : ' unresolved');
      name.textContent = it.logistic_id ? it.product_name : 'Product not recognized';

      var qty = document.createElement('input');
      qty.type = 'text';
      qty.setAttribute('inputmode', 'decimal');
      qty.className = 'input st-item-qty';
      qty.placeholder = 'Qty';
      qty.value = (it.qty === null || it.qty === undefined) ? '' : fmtQty(it.qty);
      qty.addEventListener('input', function () {
        qty.value = qty.value.replace(/[^0-9.]/g, '');
        var n = parseNumberInput(qty.value);
        it.qty = isNaN(n) ? null : n;
      });

      var unit = document.createElement('span');
      unit.className = 'st-item-unit';
      unit.textContent = it.unit_label || '';

      main.appendChild(name);
      main.appendChild(qty);
      main.appendChild(unit);

      if (!it.logistic_id) {
        var chooseBtn = document.createElement('button');
        chooseBtn.type = 'button';
        chooseBtn.className = 'btn btn-secondary st-mini-btn';
        chooseBtn.textContent = 'Choose';
        chooseBtn.addEventListener('click', function () { openProductPicker(it); });
        main.appendChild(chooseBtn);
      }

      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'btn-icon';
      rm.setAttribute('aria-label', 'Remove line');
      rm.style.fontSize = '18px';
      rm.innerHTML = '&times;';
      rm.addEventListener('click', function () {
        stItems.splice(idx, 1);
        renderStItems();
      });
      main.appendChild(rm);

      row.appendChild(lineEl);
      row.appendChild(main);
      stItemList.appendChild(row);
    });
  }

  // ---------- "Which product is this?" ----------
  function nextUnknown() {
    while (stUnknownQueue.length && stUnknownQueue[0].logistic_id) stUnknownQueue.shift();
    if (stUnknownQueue.length) { openProductPicker(stUnknownQueue[0]); return; }
    checkExistingOrders(); // every line is resolved now; safe to try merging
  }

  function openProductPicker(item) {
    stPickerItem = item;
    stHideError(stProductError);
    stProductLine.textContent = 'Line: ' + item.line;

    stProductSelect.innerHTML = '<option value="">-- select product --</option>';
    stProducts.forEach(function (p) {
      var opt = document.createElement('option');
      opt.value = p.logistic_id;
      opt.textContent = p.activity_name + (p.remaining_qty === null || p.remaining_qty === undefined
        ? '' : ' \u2014 ' + fmtQty(p.remaining_qty) + ' ' + (p.unit_label || '') + ' left');
      stProductSelect.appendChild(opt);
    });

    // Only a real wording can be remembered (a line like "30 dus" has none).
    stProductRememberBox.style.display = item.product_text ? 'block' : 'none';
    stProductRemember.checked = true;
    stProductWording.textContent = item.product_text || '';
    show(stProductOverlay);
  }

  document.getElementById('btnStProductSkip').addEventListener('click', function () {
    hide(stProductOverlay);
    if (stUnknownQueue.length && stUnknownQueue[0] === stPickerItem) stUnknownQueue.shift();
    stPickerItem = null;
    renderStItems();
    nextUnknown();
  });

  btnStProductSave.addEventListener('click', function () {
    stHideError(stProductError);
    var item = stPickerItem;
    if (!item) return;

    var lid = stProductSelect.value;
    if (!lid) { stShowError(stProductError, 'Select a product.'); return; }
    var p = stProducts.filter(function (x) { return String(x.logistic_id) === lid; })[0];
    if (!p) { stShowError(stProductError, 'Product was not found.'); return; }

    var remember = !!item.product_text && stProductRemember.checked;

    function apply() {
      var wording = item.product_text;
      assignProduct(item, p);
      if (remember) {
        // Same wording elsewhere in this message -> same product.
        stItems.forEach(function (o) {
          if (!o.logistic_id && o.product_text === wording) assignProduct(o, p);
        });
      }
      hide(stProductOverlay);
      stPickerItem = null;
      renderStItems();
      nextUnknown();
    }

    if (!remember) { apply(); return; }

    btnStProductSave.disabled = true;
    stPost('ajax/save_order_alias.php', { activity_id: p.activity_id, alias_text: item.product_text })
      .then(function (res) {
        btnStProductSave.disabled = false;
        if (!res.ok) { stShowError(stProductError, res.message || 'Could not save the wording.'); return; }
        apply();
      })
      .catch(function () {
        btnStProductSave.disabled = false;
        stShowError(stProductError, 'Connection error.');
      });
  });

  // ---------- Review -> (Set Price) -> Confirm -> Save ----------
  function stBuildPayload(dryRun) {
    if (!stCustomer.value) { stShowError(stReviewError, 'Select a customer.'); return null; }
    if (!stItems.length) { stShowError(stReviewError, 'There are no product lines in this order.'); return null; }

    var items = [];
    for (var i = 0; i < stItems.length; i++) {
      var it = stItems[i];
      if (!it.logistic_id) {
        stShowError(stReviewError, 'Choose a product for every line, or remove the line.');
        return null;
      }
      if (!it.qty || it.qty <= 0) {
        stShowError(stReviewError, 'Enter a quantity for every line.');
        return null;
      }
      items.push({ logistic_id: it.logistic_id, qty: it.qty });
    }
    if (!stOrderDate.value) { stShowError(stReviewError, 'Select the order date.'); return null; }

    var payload = {
      customer_id: stCustomer.value,
      order_date: stOrderDate.value,
      driver_name: stDriver.value.trim().toUpperCase(),
      police_number: stPolice.value.trim().toUpperCase(),
      items: JSON.stringify(items),
      new_prices: JSON.stringify(stNewPrices),
      dry_run: dryRun ? '1' : '0'
    };
    if (stTargetOrder) {
      payload.movement_ids = JSON.stringify(stMovementIdByLogistic);
    }
    return payload;
  }

  // Update Existing Order uses update_order.php (merges into the picked
  // order); a plain New Order keeps using create_order.php.
  function stOrderEndpoint() {
    return stTargetOrder ? 'ajax/update_order.php' : 'ajax/create_order.php';
  }

  // True when there's a target order AND the driver name and/or police
  // number currently on the form differs from that order's own value.
  // Compared the same way stBuildPayload() normalizes them (trimmed,
  // uppercased) so a case-only difference doesn't falsely trigger it.
  function stDriverPoliceChanged() {
    if (!stTargetOrder) return false;
    var newDriver = stDriver.value.trim().toUpperCase();
    var newPolice = stPolice.value.trim().toUpperCase();
    var oldDriver = (stTargetOrder.driver_name || '').trim().toUpperCase();
    var oldPolice = (stTargetOrder.police_number || '').trim().toUpperCase();
    return (newDriver !== oldDriver) || (newPolice !== oldPolice);
  }

  function openStDriverWarn(order) {
    stPendingConfirmOrder = order;
    stDriverWarnBody.innerHTML = '';
    var oldDriver = stTargetOrder.driver_name || '-';
    var oldPolice = stTargetOrder.police_number || '-';
    var newDriver = stDriver.value.trim().toUpperCase() || '-';
    var newPolice = stPolice.value.trim().toUpperCase() || '-';
    stAddRow(stDriverWarnBody, 'Driver', oldDriver + ' \u2192 ' + newDriver);
    stAddRow(stDriverWarnBody, 'Police Number', oldPolice + ' \u2192 ' + newPolice);
    show(stDriverWarnOverlay);
  }

  btnStDriverWarnCancel.addEventListener('click', function () {
    stPendingConfirmOrder = null;
    hide(stDriverWarnOverlay);
  });

  btnStDriverWarnOk.addEventListener('click', function () {
    stDriverWarnAck = true;
    hide(stDriverWarnOverlay);
    if (stPendingConfirmOrder) { showStConfirm(stPendingConfirmOrder); stPendingConfirmOrder = null; }
  });

  function stReview() {
    stHideError(stReviewError);
    stSuccessBox.style.display = 'none';
    var payload = stBuildPayload(true);
    if (!payload) return;

    btnStReview.disabled = true;
    stPost(stOrderEndpoint(), payload)
      .then(function (res) {
        btnStReview.disabled = false;
        if (res.ok) {
          if (!stDriverWarnAck && stDriverPoliceChanged()) { openStDriverWarn(res.order); return; }
          showStConfirm(res.order);
          return;
        }
        if (res.code === 'missing_prices') { openStPrice(res.missing || []); return; }
        stShowError(stReviewError, res.message || 'Could not review the order.');
      })
      .catch(function () {
        btnStReview.disabled = false;
        stShowError(stReviewError, 'Connection error.');
      });
  }

  btnStReview.addEventListener('click', stReview);

  // --- Set Price ---
  function openStPrice(missing) {
    stPriceMissing = missing;
    stHideError(stPriceError);
    stPriceIntro.textContent = 'No price is set for the products below on '
      + formatPriceDate(stOrderDate.value)
      + '. The price you enter is saved for this customer, effective that date.';
    stPriceRows.innerHTML = '';

    missing.forEach(function (m) {
      var g = document.createElement('div');
      g.className = 'form-group';

      var label = document.createElement('div');
      label.className = 'label';
      label.textContent = m.product_name + ' (per ' + (m.unit_label || 'unit') + ')';

      var inp = document.createElement('input');
      inp.type = 'text';
      inp.setAttribute('inputmode', 'decimal');
      inp.className = 'input input-number-comma';
      inp.placeholder = 'Price';
      inp.setAttribute('data-lid', m.logistic_id);
      if (stNewPrices[m.logistic_id]) inp.value = formatNumberInput(String(stNewPrices[m.logistic_id]));
      initNumberCommaInput(inp);

      g.appendChild(label);
      g.appendChild(inp);
      stPriceRows.appendChild(g);
    });
    show(stPriceOverlay);
  }

  document.getElementById('btnStPriceCancel').addEventListener('click', function () {
    hide(stPriceOverlay);
  });

  document.getElementById('btnStPriceContinue').addEventListener('click', function () {
    stHideError(stPriceError);
    var entered = {};
    var inputs = stPriceRows.querySelectorAll('input[data-lid]');
    for (var i = 0; i < inputs.length; i++) {
      var n = parseNumberInput(inputs[i].value);
      if (isNaN(n) || n <= 0) {
        stShowError(stPriceError, 'Enter a price above zero for every product.');
        return;
      }
      entered[inputs[i].getAttribute('data-lid')] = n;
    }
    Object.keys(entered).forEach(function (k) { stNewPrices[k] = entered[k]; });
    hide(stPriceOverlay);
    stReview(); // ask the server again, now with the prices
  });

  // --- Confirm ---
  function stAddRow(parent, label, value) {
    var row = document.createElement('div');
    row.className = 'accordion-row';
    var l = document.createElement('div');
    l.className = 'accordion-row-label';
    l.textContent = label;
    var v = document.createElement('div');
    v.className = 'accordion-row-value st-value';
    v.textContent = value;
    row.appendChild(l);
    row.appendChild(v);
    parent.appendChild(row);
  }

  function showStConfirm(order) {
    stHideError(stConfirmError);
    stConfirmBody.innerHTML = '';

    stAddRow(stConfirmBody, 'Customer', order.customer.name);
    stAddRow(stConfirmBody, 'Order Date', formatPriceDate(order.order_date));
    stAddRow(stConfirmBody, 'Driver', order.driver_name || '-');
    stAddRow(stConfirmBody, 'Police Number', order.police_number || '-');
    stAddRow(stConfirmBody, 'Invoice', order.invoice.number + (order.invoice.is_new ? ' (new)' : ''));

    var lbl = document.createElement('div');
    lbl.className = 'st-section-label';
    lbl.textContent = 'Products';
    stConfirmBody.appendChild(lbl);

    order.items.forEach(function (ln) {
      var box = document.createElement('div');
      box.className = 'st-mov';

      var top = document.createElement('div');
      top.className = 'st-mov-top';
      var nm = document.createElement('div');
      nm.style.fontWeight = '600';
      nm.textContent = ln.product_name + (ln.mode === 'updated' ? ' (revised)' : ln.mode === 'added' ? ' (added)' : '');
      var tot = document.createElement('div');
      tot.textContent = formatIDR(ln.total_price);
      top.appendChild(nm);
      top.appendChild(tot);

      var sub = document.createElement('div');
      sub.className = 'st-mov-sub';
      var qtyText = ln.mode === 'updated'
        ? (fmtQty(ln.qty_before) + ' \u2192 ' + fmtQty(ln.qty) + ' ' + (ln.unit_label || ''))
        : (fmtQty(ln.qty) + ' ' + (ln.unit_label || ''));
      sub.textContent = qtyText + ' \u00d7 ' + formatIDR(ln.price)
        + ' \u00b7 ' + (ln.price_source === 'new'
          ? 'new price, saved for this customer'
          : 'price of ' + formatPriceDate(ln.price_date))
        + ' \u00b7 stock ' + fmtQty(ln.remaining_before) + ' \u2192 ' + fmtQty(ln.remaining_after);

      box.appendChild(top);
      box.appendChild(sub);
      stConfirmBody.appendChild(box);
    });

    var sep = document.createElement('div');
    sep.className = 'st-section-label';
    sep.textContent = 'Total';
    stConfirmBody.appendChild(sep);
    stAddRow(stConfirmBody, order.mode === 'update' ? 'Net Change' : 'This Order', formatIDR(order.grand_total));
    stAddRow(stConfirmBody, 'Invoice After', formatIDR(order.invoice.total_after));

    show(stConfirmOverlay);
  }

  document.getElementById('btnStConfirmCancel').addEventListener('click', function () {
    hide(stConfirmOverlay);
  });

  btnStConfirmSave.addEventListener('click', function () {
    if (stSaving) return;
    stHideError(stConfirmError);
    var payload = stBuildPayload(false);
    if (!payload) { hide(stConfirmOverlay); return; }

    stSaving = true;
    btnStConfirmSave.disabled = true;
    stPost(stOrderEndpoint(), payload)
      .then(function (res) {
        stSaving = false;
        btnStConfirmSave.disabled = false;
        if (!res.ok) {
          if (res.code === 'missing_prices') { hide(stConfirmOverlay); openStPrice(res.missing || []); return; }
          stShowError(stConfirmError, res.message || 'Failed to save the order.');
          return;
        }
        var o = res.order;
        var summary = (o.mode === 'update' ? 'Order updated \u2014 ' : 'Order saved \u2014 ') + o.customer.name
          + ' \u00b7 Invoice ' + o.invoice.number
          + ' \u00b7 ' + (o.mode === 'update' ? 'net change ' : '') + formatIDR(o.grand_total);
        resetSalesForm(); // also closes the fly windows
        stSuccessBox.textContent = summary;
        stSuccessBox.style.display = 'block';
        loadStCustomers();
      })
      .catch(function () {
        stSaving = false;
        btnStConfirmSave.disabled = false;
        stShowError(stConfirmError, 'Connection error.');
      });
  });

  // ---------- Tabs: New Order <-> Customers ----------
  var stTabs = document.querySelectorAll('#stTabGroup .tab');
  var stTabPanelMap = {
    order:     document.getElementById('stTabPanelOrder'),
    customers: document.getElementById('stTabPanelCustomers')
  };

  function setActiveStTab(name) {
    stTabs.forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-st-tab') === name);
    });
    Object.keys(stTabPanelMap).forEach(function (key) {
      stTabPanelMap[key].style.display = (key === name) ? 'block' : 'none';
    });
  }

  stTabs.forEach(function (t) {
    t.addEventListener('click', function () {
      var name = t.getAttribute('data-st-tab');
      setActiveStTab(name);
      if (name === 'customers') loadStCustomerCards(); // refresh every visit
    });
  });

  // ---------- Tab 2: one collapsible card per customer ----------
  function loadStCustomerCards() {
    return loadStCustomers().then(renderStCustomerCards);
  }

  function renderStCustomerCards(list) {
    stCustList.innerHTML = '';
    stCustEmpty.style.display = list.length ? 'none' : 'block';

    list.forEach(function (c) {
      var item = document.createElement('div');
      item.className = 'accordion-item';

      var header = document.createElement('div');
      header.className = 'accordion-header';
      var title = document.createElement('span');
      title.className = 'accordion-title';
      title.textContent = c.customer_name + ' (' + c.year + ')';
      var chevron = document.createElement('i');
      chevron.className = 'ti ti-chevron-down accordion-chevron';
      header.appendChild(title);
      header.appendChild(chevron);

      var body = document.createElement('div');
      body.className = 'accordion-body';
      var inner = document.createElement('div');
      inner.className = 'accordion-body-inner';
      body.appendChild(inner);

      header.addEventListener('click', function () {
        toggleAccordionItem(item);
        if (!item.classList.contains('open')) return;
        // Loaded on every open so a just-saved order is always included.
        inner.innerHTML = '';
        var loading = document.createElement('div');
        loading.className = 'empty-sub';
        loading.textContent = 'Loading...';
        inner.appendChild(loading);
        refreshOpenHeight(item);

        fetch('ajax/list_customer_orders.php?customer_id=' + encodeURIComponent(c.id), { cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.ok) { loading.textContent = res.message || 'Could not load the orders.'; refreshOpenHeight(item); return; }
            renderStCustomerDetail(inner, res.data, item);
          })
          .catch(function () {
            loading.textContent = 'Connection error.';
            refreshOpenHeight(item);
          });
      });

      item.appendChild(header);
      item.appendChild(body);
      stCustList.appendChild(item);
    });
  }

  function renderStCustomerDetail(container, data, item) {
    container.innerHTML = '';

    stAddRow(container, 'Total Ordered', formatIDR(data.customer.total_ordered));
    stAddRow(container, 'Total Paid', formatIDR(data.customer.total_paid));

    var pl = document.createElement('div');
    pl.className = 'st-section-label';
    pl.textContent = 'Products';
    container.appendChild(pl);

    if (!data.products.length) {
      var none = document.createElement('div');
      none.className = 'empty-sub';
      none.textContent = 'No orders yet.';
      container.appendChild(none);
    }
    data.products.forEach(function (p) {
      stAddRow(container, p.activity_name,
        fmtQty(p.total_qty) + ' ' + (p.unit_label || '') + ' \u00b7 ' + formatIDR(p.total_value));
    });

    var il = document.createElement('div');
    il.className = 'st-section-label';
    il.textContent = 'Invoices';
    container.appendChild(il);

    var invList = document.createElement('div');
    invList.className = 'accordion-list';
    invList.style.marginTop = 'var(--space-2)';
    container.appendChild(invList);

    data.invoices.forEach(function (inv) { invList.appendChild(buildStInvoiceItem(inv)); });

    refreshOpenHeight(item);
  }

  function buildStInvoiceItem(inv) {
    var invItem = document.createElement('div');
    invItem.className = 'accordion-item';

    var h = document.createElement('div');
    h.className = 'accordion-header';
    var t = document.createElement('span');
    t.className = 'accordion-title';
    t.textContent = inv.invoice_number;
    h.appendChild(t);

    if (inv.status === 'open' || inv.status === 'paid') {
      var badge = document.createElement('span');
      badge.className = 'badge ' + (inv.status === 'paid' ? 'badge-success' : 'badge-warning');
      badge.style.flexShrink = '0';
      badge.textContent = inv.status.toUpperCase();
      h.appendChild(badge);
    }

    var chev = document.createElement('i');
    chev.className = 'ti ti-chevron-down accordion-chevron';
    h.appendChild(chev);
    h.addEventListener('click', function () { toggleAccordionItem(invItem); });

    var b = document.createElement('div');
    b.className = 'accordion-body';
    var bi = document.createElement('div');
    bi.className = 'accordion-body-inner';
    b.appendChild(bi);

    if (inv.total_amount !== null) {
      stAddRow(bi, 'Total', formatIDR(inv.total_amount));
      stAddRow(bi, 'Paid', formatIDR(inv.paid_amount));
    }

    inv.movements.forEach(function (m) {
      var box = document.createElement('div');
      box.className = 'st-mov';

      var top = document.createElement('div');
      top.className = 'st-mov-top';
      var left = document.createElement('div');
      left.textContent = formatPriceDate(m.movement_date) + ' \u00b7 ' + m.activity_name;
      var right = document.createElement('div');
      right.style.flexShrink = '0';
      right.textContent = formatIDR(m.total_price);
      top.appendChild(left);
      top.appendChild(right);

      var sub = document.createElement('div');
      sub.className = 'st-mov-sub';
      var parts = [fmtQty(m.qty) + ' ' + (m.unit_label || '') + ' \u00d7 ' + formatIDR(m.price)];
      if (m.driver_name) parts.push(m.driver_name);
      if (m.police_number) parts.push(m.police_number);
      sub.textContent = parts.join(' \u00b7 ');

      box.appendChild(top);
      box.appendChild(sub);
      bi.appendChild(box);
    });

    invItem.appendChild(h);
    invItem.appendChild(b);
    return invItem;
  }

})();
</script>