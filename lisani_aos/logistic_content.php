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

    /* ---- Tab 2: Movements — nested collapsible cards ----
       Generic collapsible mechanics, same class names/behaviour as
       .st-collapsible in transaction_content.php (single click = toggle +
       close siblings, double click within 220ms = toggle without closing
       siblings). Kept local to this file since *_content.php files don't
       share JS/CSS scope. Sibling-scoping is per parentNode, so nesting
       these cards automatically gives one independent accordion group per
       level (main card / year / month / day) with no extra logic needed. */
    .log-mov-collapsible .log-mov-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-2);
      cursor: pointer;
      user-select: none;
      -webkit-user-select: none;
    }
    .log-mov-collapsible .log-mov-title { flex: 1 1 auto; min-width: 0; }
    .log-mov-collapsible .log-mov-totals {
      flex-shrink: 0;
      font-size: var(--text-xs);
      color: var(--text-muted);
      text-align: right;
      white-space: nowrap;
    }
    .log-mov-collapsible .log-mov-totals .log-mov-out { color: var(--text-secondary); }
    .log-mov-collapsible .log-mov-totals .log-mov-in { color: var(--accent); }
    .log-mov-collapsible .log-mov-totals .log-mov-adj { color: var(--danger, #e5697b); }
    .log-mov-collapsible .log-mov-totals .log-mov-actual { color: var(--text-primary); font-weight: 600; }
    .log-mov-collapsible .log-mov-chevron { flex-shrink: 0; transition: transform 0.15s ease; }
    .log-mov-collapsible.log-mov-open .log-mov-chevron { transform: rotate(180deg); }
    /* Direct-child combinator (>) is essential here: each collapsible
       card's own .log-mov-body must be controlled only by its own
       .log-mov-open class, never by an ancestor's. Without ">" this was a
       plain descendant selector, so opening the outer (logistic) card
       also force-displayed every nested year/month/day body regardless of
       their own state, and they couldn't be closed while the ancestor was
       open (bug found 22 Sep 2026, fixed same day). */
    .log-mov-collapsible > .log-mov-body { display: none; margin-top: var(--space-3); }
    .log-mov-collapsible.log-mov-open > .log-mov-body { display: block; }

    .log-mov-logistic-card {
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: var(--radius-md, 10px);
      padding: var(--space-3);
      margin-bottom: var(--space-3);
    }
    .log-mov-logistic-card:last-child { margin-bottom: 0; }
    .log-mov-logistic-card.log-mov-open {
      border-color: var(--accent, #6b8afd);
      background: rgba(107,138,253,0.05);
    }
    .log-mov-logistic-card .log-mov-title { font-weight: 600; color: var(--text-primary); }
    .log-mov-logistic-card .log-mov-subtitle {
      font-size: var(--text-xs);
      color: var(--text-muted);
      margin-top: 2px;
    }

    /* Sub-cards nested inside each log-mov-logistic-card: Validation (on
       top) and Detail Movement (below it), both collapsible via the same
       .log-mov-collapsible mechanism as everything else. Slightly recessed
       compared to the year/month/day cards so it reads as "inside the
       activity code card" rather than another level of the same hierarchy. */
    .log-mov-sub-card {
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: var(--radius-sm, 8px);
      padding: var(--space-3);
      margin-bottom: var(--space-3);
      background: rgba(255,255,255,0.02);
    }
    .log-mov-sub-card:last-child { margin-bottom: 0; }
    .log-mov-sub-card.log-mov-open { border-color: rgba(107,138,253,0.4); }
    .log-mov-sub-card .log-mov-title { font-weight: 600; color: var(--text-primary); font-size: var(--text-sm); }

    .log-mov-customer-row {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: var(--space-2);
      flex-wrap: wrap;
      padding: var(--space-2) 0;
      border-top: 1px solid rgba(255,255,255,0.06);
    }
    .log-mov-customer-row:first-of-type { border-top: none; }
    .log-mov-customer-row .log-mov-customer-name {
      font-size: var(--text-sm);
      color: var(--text-primary);
      font-weight: 500;
    }
    /* Collapsible variant (only Invalid customers get one — see
       logMovByCustomerEl()): overrides the flex-row layout above so the
       .log-mov-head/.log-mov-body pair from the generic collapsible
       mechanics can stack normally instead of fighting the row's own
       flexbox. */
    .log-mov-customer-row.log-mov-collapsible {
      display: block;
      cursor: pointer;
    }
    .log-mov-customer-row.log-mov-collapsible > .log-mov-head {
      padding: 0;
    }
    .log-mov-customer-row.log-mov-collapsible > .log-mov-head > div:first-child {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      flex-wrap: wrap;
    }

    .log-mov-year-card,
    .log-mov-month-card,
    .log-mov-day-card {
      border-left: 2px solid rgba(255,255,255,0.08);
      padding: var(--space-2) 0 var(--space-2) var(--space-3);
      margin-bottom: var(--space-2);
    }
    .log-mov-year-card:last-child,
    .log-mov-month-card:last-child,
    .log-mov-day-card:last-child { margin-bottom: 0; }
    .log-mov-year-card.log-mov-open,
    .log-mov-month-card.log-mov-open { border-left-color: var(--accent, #6b8afd); }
    .log-mov-year-card .log-mov-body,
    .log-mov-month-card .log-mov-body { padding-left: var(--space-2); }

    /* Day card: full boxed block when open (not just the left accent
       line used by Year/Month), so the exact day being reviewed stands
       out clearly — same visual language as the main logistic card. */
    .log-mov-day-card.log-mov-open {
      border: 1px solid var(--accent, #6b8afd);
      border-left: 2px solid var(--accent, #6b8afd);
      border-radius: var(--radius-md, 10px);
      background: rgba(107,138,253,0.06);
      padding: var(--space-3);
    }
    .log-mov-day-card.log-mov-open > .log-mov-body { padding-left: 0; }

    .log-mov-line {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: var(--space-2);
      padding: var(--space-2) 0;
      border-bottom: 1px dashed rgba(255,255,255,0.06);
      font-size: var(--text-sm);
    }
    .log-mov-line:last-child { border-bottom: none; }
    .log-mov-line-left { flex: 1 1 auto; min-width: 0; }
    .log-mov-line-sub {
      font-size: var(--text-xs);
      color: var(--text-muted);
      margin-top: 2px;
      word-break: break-word;
    }
    .log-mov-line-right { flex-shrink: 0; text-align: right; }

    /* Small screens: the head row (title/totals/chevron side by side) is
       what overflowed and collided before — title text, the right-aligned
       totals block, and the chevron all fighting for width on a ~360px
       viewport. Below this breakpoint every collapsible head stacks
       vertically instead, and totals switch from right-aligned/nowrap to
       left-aligned/wrapping so long lines (e.g. "Actual Taken: 12 CTN ·
       Rp 1,200,000") wrap onto their own line rather than clipping or
       forcing horizontal scroll. */
    @media (max-width: 640px) {
      .log-mov-collapsible .log-mov-head {
        flex-wrap: wrap;
      }
      .log-mov-collapsible .log-mov-totals {
        text-align: left;
        white-space: normal;
        flex-basis: 100%;
      }
      .log-mov-customer-row.log-mov-collapsible > .log-mov-head > div:first-child {
        flex-basis: 100%;
      }
      .log-mov-customer-row .log-mov-totals {
        text-align: left;
        white-space: normal;
      }
      .log-mov-logistic-card,
      .log-mov-sub-card {
        padding: var(--space-2);
      }
      .log-mov-line {
        flex-wrap: wrap;
      }
      .log-mov-line-right {
        text-align: left;
        flex-basis: 100%;
      }
    }
    /* Defective Stock row (Logistic List tab) — clickable/"push"able row
       that expands into a return history list, same visual language as the
       accordion-row it sits next to. */
    .accordion-row-clickable { cursor: pointer; }
    .accordion-row-value-wrap {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }
    .accordion-row-expandable .log-mov-chevron {
      flex-shrink: 0;
      transition: transform 0.15s ease;
    }
    .accordion-row-expandable.open .log-mov-chevron { transform: rotate(180deg); }
    .defective-history {
      display: none;
      padding: var(--space-2) 0 var(--space-2) var(--space-3);
      border-bottom: 1px dashed rgba(255,255,255,0.06);
    }
    .accordion-row-expandable.open .defective-history { display: block; }
    .defective-history-empty {
      font-size: var(--text-xs);
      color: var(--text-muted);
    }
    .defective-history-line {
      display: flex;
      justify-content: space-between;
      gap: var(--space-2);
      font-size: var(--text-xs);
      padding: 2px 0;
    }
    .defective-history-who { min-width: 0; word-break: break-word; }
    .defective-history-meta { flex-shrink: 0; color: var(--text-muted); text-align: right; }

    /* Return History fly window's list (#logDefectiveHistoryList, both the
       Taken Out and Returned In tabs) — capped height + its own scrollbar,
       so a product with lots of history rows doesn't stretch the whole fly
       window taller; the tab buttons and the Repair form below stay put and
       visible without scrolling past a long list first. */
    #logDefectiveHistoryList {
      max-height: 260px;
      overflow-y: auto;
      padding-right: var(--space-1);
    }

    @media (max-width: 640px) {
      .defective-history-line { flex-direction: column; }
      .defective-history-meta { text-align: left; }
    }
  </style>

  <div class="tab-group" id="logTabGroup">
    <div class="tab active" data-log-tab="list">Logistic List</div>
    <div class="tab" data-log-tab="movements">Movements</div>
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

  <!-- Tab 2: Movements — logistic_movements history (in/out), grouped
       Year -> Month -> Day, nested collapsible cards, one main collapsible
       card per logistic/activity code. Collapse behaviour mirrors
       stBindCollapsible() in transaction_content.php (Returns / Customers
       history): single click = toggle + close siblings, double click
       within 220ms = toggle without closing siblings — reimplemented
       locally below since each *_content.php file is its own IIFE and
       doesn't share JS scope. -->
  <div id="logTabPanelMovements" style="display:none;">
    <div id="logMovementsList"></div>
    <div class="empty-state" id="logMovementsEmpty" style="display:none;">
      <div class="empty-title">No movements yet</div>
      <div class="empty-sub">Movements appear here once pickups or returns are recorded from Sales Transaction.</div>
    </div>
  </div>

  <!-- Tab 3: Create New Logistic -->
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
      <div class="label" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:var(--space-2);">
        <span>Products</span>
        <span style="display:flex; gap:var(--space-2);">
          <button type="button" class="btn btn-secondary" id="btnManagePrimaryUnits">Manage Primary Units</button>
          <button type="button" class="btn btn-secondary" id="btnManageSecondaryUnits">Manage Secondary Units</button>
        </span>
      </div>
      <div class="empty-sub">One activity code can hold several products — add one block per product. Incoming Date, Import Document and (later) payment are shared by all products below.</div>
    </div>

    <!-- One .logProductBlock per product; built by createProductBlock() in JS. -->
    <div id="logProductsContainer"></div>

    <template id="logProductBlockTemplate">
      <div class="neo-inset logProductBlock" style="border-radius:var(--radius-md); padding:var(--space-4); margin-bottom:var(--space-3);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--space-3);">
          <div class="label" style="margin:0;">Product Name</div>
          <button type="button" class="btn btn-secondary logProdRemove">Remove</button>
        </div>
        <div class="form-group">
          <input type="text" class="input input-uppercase logProdName" placeholder="e.g. SAYER, SUKKARI, MEDJOOL">
        </div>

        <div class="form-group">
          <div class="label" style="display:flex; align-items:center; gap:var(--space-2);">
            Primary Packaging
            <span class="info-icon logProdPrimaryInfoIcon" tabindex="0">!</span>
          </div>
          <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
            <input type="text" inputmode="decimal" class="input input-number-comma logProdPrimaryQty" placeholder="Qty" style="flex:1; min-width:100px;">
            <select class="select logProdPrimaryUnit" style="flex:2; min-width:200px;">
              <option value="">-- select unit --</option>
            </select>
            <input type="text" class="input logProdPrimaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
          </div>
          <div class="info-tooltip logProdPrimaryInfoTooltip">Fill in either Primary or Secondary Qty — the other one is calculated automatically from the selected unit's ratio.</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <div class="label" style="display:flex; align-items:center; gap:var(--space-2);">
            Secondary Packaging
            <span class="info-icon logProdSecondaryInfoIcon" tabindex="0">!</span>
          </div>
          <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; align-items:center;">
            <input type="text" inputmode="decimal" class="input input-number-comma logProdSecondaryQty" placeholder="Qty" style="flex:1; min-width:100px;">
            <select class="select logProdSecondaryUnit" style="flex:2; min-width:200px;">
              <option value="">-- select unit --</option>
            </select>
            <input type="text" class="input logProdSecondaryWeight" placeholder="Total weight (KG)" disabled style="flex:1; min-width:150px;">
          </div>
          <div class="info-tooltip logProdSecondaryInfoTooltip">Fill in either Primary or Secondary Qty — the other one is calculated automatically from the selected unit's ratio.</div>
        </div>
      </div>
    </template>

    <div style="display:flex; justify-content:flex-start; margin-bottom:var(--space-4);">
      <button type="button" class="btn btn-secondary" id="btnLogAddProduct">+ Add Product</button>
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

<!-- Defective Stock — Return History: opened by clicking the "Defective
     Stock" row inside a Logistic List accordion item. Fetched fresh on open
     (ajax/manage_defective_stock.php?action=history / return_history)
     rather than embedded in the Logistic List response, so it can carry
     price per row. Two tab buttons switch which movement direction is shown
     for this same product — "Taken Out" (movement_type='out', customer
     bought from defective stock) vs "Returned In" (movement_type='in',
     customer's return was restocked into the defective bucket) — plus a
     "Repair to Normal Stock" action for the qty still on hand, shared by
     both tabs since it acts on defective_qty regardless of which list is
     showing. Revised 27 Sep 2026, see PROJECT_NOTES.md "Redesain besar:
     hapus tab Defective Stock..." and the later "tambah Returned In" entry. -->
<div class="modal-overlay" id="logDefectiveHistoryOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Defective Stock — Return History</div></div>
    <div class="modal-body">
      <div class="accordion-row" style="padding-bottom:var(--space-3); margin-bottom:var(--space-2); border-bottom:1px dashed rgba(255,255,255,0.06);">
        <span class="accordion-row-label">Defective Stock</span>
        <span class="accordion-row-value" id="logDefectiveHistoryQty">-</span>
      </div>

      <div class="empty-sub" id="logDefectiveHistoryError" style="display:none; color:var(--danger); margin-bottom:var(--space-2);"></div>
      <div class="empty-sub" id="logDefectiveHistorySuccess" style="display:none; color:var(--success); margin-bottom:var(--space-2);"></div>

      <div class="tab-group" id="logDefectiveHistoryTabGroup" style="margin-top:var(--space-2);">
        <div class="tab active" data-defective-history-tab="history">Taken Out</div>
        <div class="tab" data-defective-history-tab="return_history">Returned In</div>
      </div>
      <div class="label" style="margin-top:var(--space-2);" id="logDefectiveHistoryListLabel">Taken by customers</div>
      <div id="logDefectiveHistoryList"></div>

      <div class="form-group" id="logDefectiveRepairSection" style="margin-top:var(--space-4); padding-top:var(--space-3); border-top:1px dashed rgba(255,255,255,0.06); display:none;">
        <div class="label">Repair to Normal Stock</div>
        <div style="display:flex; gap:var(--space-2);">
          <input type="text" inputmode="decimal" class="input" id="logDefectiveRepairQty" placeholder="Qty" style="flex:1;">
          <button type="button" class="btn btn-secondary" id="btnLogDefectiveRepair">Move to Normal Stock</button>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnLogDefectiveHistoryClose">Close</button>
    </div>
  </div>
</div>

<!-- Remaining Primary Qty (normal stock) ledger: opened by clicking the
     "Remaining Primary Qty" row. Running-balance view — initial stock,
     then (for every normal return) how much was still out right before
     that return plus the return itself, then the current balance. -->
<div class="modal-overlay" id="logNormalLedgerOverlay" style="display:none;">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Remaining Primary Qty — History</div></div>
    <div class="modal-body">
      <div id="logNormalLedgerList"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="btnLogNormalLedgerClose">Close</button>
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

  // ---------- Tabs ----------
  var logTabGroup = document.getElementById('logTabGroup');
  var panels = {
    list:      document.getElementById('logTabPanelList'),
    movements: document.getElementById('logTabPanelMovements'),
    create:    document.getElementById('logTabPanelCreate')
  };

  function setActiveLogTab(name) {
    logTabGroup.querySelectorAll('.tab').forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-log-tab') === name);
    });
    Object.keys(panels).forEach(function (key) {
      panels[key].style.display = key === name ? 'block' : 'none';
    });
    if (name === 'list') loadLogisticList();
    if (name === 'movements') loadLogisticMovements();
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

  // Same look as accordionRow(), but clickable ("push") to open a fly
  // window (modal) with the return history — who returned it and when —
  // instead of expanding inline. Kept as its own small row so future
  // "click to see more" rows can reuse the same pattern.
  function accordionRowClickable(label, value, onClick) {
    var row = document.createElement('div');
    row.className = 'accordion-row accordion-row-clickable';
    row.setAttribute('tabindex', '0');

    var l = document.createElement('span');
    l.className = 'accordion-row-label';
    l.textContent = label;

    var right = document.createElement('span');
    right.className = 'accordion-row-value-wrap';
    var v = document.createElement('span');
    v.className = 'accordion-row-value';
    v.textContent = value;
    var chevron = document.createElement('i');
    chevron.className = 'ti ti-chevron-right log-mov-chevron';
    right.appendChild(v);
    right.appendChild(chevron);

    row.appendChild(l);
    row.appendChild(right);

    row.addEventListener('click', function (e) { e.stopPropagation(); onClick(); });
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); onClick(); }
    });

    return row;
  }

  // ---------- Defective Stock fly window (return history + repair) ----------
  var logDefectiveHistoryOverlay   = document.getElementById('logDefectiveHistoryOverlay');
  var logDefectiveHistoryQty       = document.getElementById('logDefectiveHistoryQty');
  var logDefectiveHistoryList      = document.getElementById('logDefectiveHistoryList');
  var logDefectiveHistoryListLabel = document.getElementById('logDefectiveHistoryListLabel');
  var logDefectiveHistoryTabGroup  = document.getElementById('logDefectiveHistoryTabGroup');
  var logDefectiveHistoryError     = document.getElementById('logDefectiveHistoryError');
  var logDefectiveHistorySuccess   = document.getElementById('logDefectiveHistorySuccess');
  var logDefectiveRepairQty        = document.getElementById('logDefectiveRepairQty');
  var logDefectiveRepairSection    = document.getElementById('logDefectiveRepairSection');
  var btnLogDefectiveRepair        = document.getElementById('btnLogDefectiveRepair');
  var btnLogDefectiveHistoryClose  = document.getElementById('btnLogDefectiveHistoryClose');
  var defectiveHistoryCurrent      = null; // { logistic_id, unit_label, defective_qty } of the open product
  var defectiveHistoryTab          = 'history'; // 'history' (Taken Out) | 'return_history' (Returned In) — which list is showing
  // Labels shown above the list per tab, and per-tab empty-state text.
  var DEFECTIVE_HISTORY_TAB_META = {
    history:        { label: 'Taken by customers', empty: 'No defective-stock pickups yet.' },
    return_history: { label: 'Returned by customers', empty: 'No defective-stock returns yet.' }
  };

  function dsShowError(msg) {
    logDefectiveHistorySuccess.style.display = 'none';
    logDefectiveHistoryError.textContent = msg;
    logDefectiveHistoryError.style.display = 'block';
  }
  function dsHideMessages() {
    logDefectiveHistoryError.style.display = 'none';
    logDefectiveHistorySuccess.style.display = 'none';
  }

  function renderDefectiveHistoryList(data) {
    logDefectiveHistoryList.innerHTML = '';
    var historyItems = data.history || [];
    if (!historyItems.length) {
      var empty = document.createElement('div');
      empty.className = 'defective-history-empty';
      empty.textContent = DEFECTIVE_HISTORY_TAB_META[defectiveHistoryTab].empty;
      logDefectiveHistoryList.appendChild(empty);
      return;
    }
    historyItems.forEach(function (h) {
      var line = document.createElement('div');
      line.className = 'defective-history-line';
      var who = document.createElement('span');
      who.className = 'defective-history-who';
      who.textContent = h.customer_name || 'Unknown customer';
      var meta = document.createElement('span');
      meta.className = 'defective-history-meta';
      meta.textContent = fmtNum(h.qty) + ' ' + (data.unit_label || '')
        + (h.price !== null ? ' \u00d7 ' + fmtIDR(h.price) : '')
        + ' \u00b7 ' + (h.movement_date || '-');
      line.appendChild(who);
      line.appendChild(meta);
      logDefectiveHistoryList.appendChild(line);
    });
  }

  // Repair form only makes sense while there's actually defective stock on
  // hand — hidden entirely at 0 (nothing to move back). While shown, the
  // qty input defaults to the full remaining defective_qty (the common
  // case: repairing everything that's left) but stays a plain editable
  // input, not read-only, so staff can still type a smaller partial qty.
  function syncDefectiveRepairUI() {
    var qty = defectiveHistoryCurrent ? defectiveHistoryCurrent.defective_qty : 0;
    if (qty > 0.0001) {
      logDefectiveRepairSection.style.display = '';
      logDefectiveRepairQty.value = fmtNum(qty);
    } else {
      logDefectiveRepairSection.style.display = 'none';
      logDefectiveRepairQty.value = '';
    }
  }

  // Fetches fresh from the server every time it opens/switches tab (rather
  // than reusing whatever list_logistics.php sent for the row), because
  // this is the only place that needs price per row — see
  // manage_defective_stock.php. `action` is 'history' (Taken Out) or
  // 'return_history' (Returned In) — same response shape either way.
  function loadDefectiveHistory(l) {
    logDefectiveHistoryList.innerHTML = '';
    fetch('ajax/manage_defective_stock.php?action=' + defectiveHistoryTab + '&logistic_id=' + encodeURIComponent(l.id), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) { dsShowError(res.message || 'Could not load defective stock history.'); return; }
        var data = res.data;
        defectiveHistoryCurrent.defective_qty = data.defective_qty;
        logDefectiveHistoryQty.textContent = fmtNum(data.defective_qty) + ' ' + (data.unit_label || '');
        renderDefectiveHistoryList(data);
        syncDefectiveRepairUI();
      })
      .catch(function () { dsShowError('Connection error.'); });
  }

  function openDefectiveHistory(l) {
    dsHideMessages();
    logDefectiveHistoryQty.textContent = fmtNum(l.defective_qty) + ' ' + (l.primary_unit_label || '');
    logDefectiveHistoryList.innerHTML = '';
    defectiveHistoryCurrent = { logistic_id: l.id, unit_label: l.primary_unit_label, defective_qty: l.defective_qty };
    syncDefectiveRepairUI();
    defectiveHistoryTab = 'history';
    logDefectiveHistoryTabGroup.querySelectorAll('.tab').forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-defective-history-tab') === 'history');
    });
    logDefectiveHistoryListLabel.textContent = DEFECTIVE_HISTORY_TAB_META.history.label;
    show(logDefectiveHistoryOverlay);
    loadDefectiveHistory(l);
  }

  logDefectiveHistoryTabGroup.addEventListener('click', function (e) {
    var tabEl = e.target.closest('[data-defective-history-tab]');
    if (!tabEl || !defectiveHistoryCurrent) return;
    var tab = tabEl.getAttribute('data-defective-history-tab');
    if (tab === defectiveHistoryTab) return;
    defectiveHistoryTab = tab;
    logDefectiveHistoryTabGroup.querySelectorAll('.tab').forEach(function (t) {
      t.classList.toggle('active', t === tabEl);
    });
    logDefectiveHistoryListLabel.textContent = DEFECTIVE_HISTORY_TAB_META[tab].label;
    dsHideMessages();
    loadDefectiveHistory({ id: defectiveHistoryCurrent.logistic_id });
  });

  btnLogDefectiveRepair.addEventListener('click', function () {
    dsHideMessages();
    if (!defectiveHistoryCurrent) return;
    var n = parseNumberInput(logDefectiveRepairQty.value);
    if (isNaN(n) || n <= 0) { dsShowError('Enter a quantity above zero.'); return; }
    if (n > defectiveHistoryCurrent.defective_qty + 0.0001) {
      dsShowError('Only ' + fmtNum(defectiveHistoryCurrent.defective_qty) + ' ' + (defectiveHistoryCurrent.unit_label || '') + ' is on hand.');
      return;
    }
    btnLogDefectiveRepair.disabled = true;
    fetch('ajax/manage_defective_stock.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'repair', logistic_id: defectiveHistoryCurrent.logistic_id, qty: n }).toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btnLogDefectiveRepair.disabled = false;
        if (!res.ok) { dsShowError(res.message || 'Could not repair the stock.'); return; }
        logDefectiveHistorySuccess.textContent = fmtNum(n) + ' ' + (defectiveHistoryCurrent.unit_label || '') + ' moved back to normal stock.';
        logDefectiveHistorySuccess.style.display = 'block';
        defectiveHistoryCurrent.defective_qty -= n;
        logDefectiveHistoryQty.textContent = fmtNum(defectiveHistoryCurrent.defective_qty) + ' ' + (defectiveHistoryCurrent.unit_label || '');
        syncDefectiveRepairUI(); // hides the form at 0, or re-defaults qty to what's left
        loadLogisticList(); // remaining_primary_qty / defective_qty changed — refresh the row underneath
      })
      .catch(function () {
        btnLogDefectiveRepair.disabled = false;
        dsShowError('Connection error.');
      });
  });

  btnLogDefectiveHistoryClose.addEventListener('click', function () { hide(logDefectiveHistoryOverlay); });
  logDefectiveHistoryOverlay.addEventListener('click', function (e) {
    if (e.target === logDefectiveHistoryOverlay) hide(logDefectiveHistoryOverlay);
  });

  // ---------- Remaining Primary Qty fly window (normal-stock ledger) ----------
  var logNormalLedgerOverlay = document.getElementById('logNormalLedgerOverlay');
  var logNormalLedgerList    = document.getElementById('logNormalLedgerList');
  var btnLogNormalLedgerClose = document.getElementById('btnLogNormalLedgerClose');

  // "2026-09-27" + "2026-09-27 16:45:00" -> "2026-09-27, 16:45" (falls back
  // gracefully if either half is missing — e.g. the very first "Initial
  // Stock" step never has a time).
  function fmtLedgerWhen(date, time) {
    if (!date) return '-';
    var hhmm = logMovFormatTime(time);
    return hhmm ? (date + ', ' + hhmm) : date;
  }

  function ledgerLine(label, badgeClass, badgeText, qtyText, whenText, subText) {
    var line = document.createElement('div');
    line.className = 'defective-history-line ledger-line';

    var left = document.createElement('span');
    left.className = 'defective-history-who';
    var lbl = document.createElement('div');
    lbl.textContent = label;
    left.appendChild(lbl);
    if (badgeText) {
      var badge = document.createElement('span');
      badge.className = 'badge ' + badgeClass;
      badge.style.marginTop = '2px';
      badge.style.display = 'inline-block';
      badge.textContent = badgeText;
      left.appendChild(badge);
    }
    if (subText) {
      var sub = document.createElement('div');
      sub.style.cssText = 'font-size:var(--text-xs); color:var(--text-muted); margin-top:2px;';
      sub.textContent = subText;
      left.appendChild(sub);
    }

    var right = document.createElement('span');
    right.className = 'defective-history-meta';
    var qtyEl = document.createElement('div');
    qtyEl.style.fontWeight = '600';
    qtyEl.textContent = qtyText;
    right.appendChild(qtyEl);
    var whenEl = document.createElement('div');
    whenEl.textContent = whenText;
    right.appendChild(whenEl);
    right.appendChild(document.createElement('div'));

    line.appendChild(left);
    line.appendChild(right);
    return line;
  }

  function openNormalLedger(l) {
    logNormalLedgerList.innerHTML = '';
    var unit = l.primary_unit_label || '';
    var steps = l.normal_stock_ledger || [];

    steps.forEach(function (s) {
      var qtyText = fmtNum(s.qty) + ' ' + unit;
      var whenText = fmtLedgerWhen(s.date, s.time);
      var el;
      if (s.type === 'initial') {
        el = ledgerLine('Initial Stock', null, null, qtyText, whenText);
      } else if (s.type === 'taken_so_far') {
        el = ledgerLine('Taken (outstanding, before the return below)', 'badge-warning', null, qtyText, whenText);
      } else if (s.type === 'return') {
        el = ledgerLine(s.customer_name || 'Unknown customer', 'badge-success', 'RETURN \u00b7 NORMAL', qtyText, whenText);
      } else { // 'final'
        el = ledgerLine('Current Stock', null, null, qtyText, whenText);
        el.style.borderBottom = 'none';
        el.style.fontWeight = '600';
      }
      logNormalLedgerList.appendChild(el);
    });

    show(logNormalLedgerOverlay);
  }

  btnLogNormalLedgerClose.addEventListener('click', function () { hide(logNormalLedgerOverlay); });
  logNormalLedgerOverlay.addEventListener('click', function (e) {
    if (e.target === logNormalLedgerOverlay) hide(logNormalLedgerOverlay);
  });

  // ---------- Tab 1: Logistic List ----------
  var logPreviewList  = document.getElementById('logPreviewList');
  var logPreviewEmpty = document.getElementById('logPreviewEmpty');
  var logPreviewEmptyDefaultHtml = logPreviewEmpty.innerHTML; // saved once so error states can be reverted later

  function fmtNum(v) {
    if (v === null || v === undefined || v === '') return '-';
    var n = Number(v);
    if (isNaN(n)) return '-';
    return n.toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

  function renderLogisticList(list) {
    logPreviewList.innerHTML = '';
    logPreviewEmpty.innerHTML = logPreviewEmptyDefaultHtml; // restore default text in case a previous load failed and overwrote it
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
      bodyInner.appendChild(accordionRowClickable('Remaining Primary Qty', fmtNum(l.remaining_primary_qty) + ' / ' + fmtNum(l.primary_qty), function () { openNormalLedger(l); }));
      bodyInner.appendChild(accordionRowClickable('Defective Stock', fmtNum(l.defective_qty), function () { openDefectiveHistory(l); }));
      bodyInner.appendChild(accordionRow('Total All Stock', fmtNum(l.total_all_stock) + ' ' + (l.primary_unit_label || '')));
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
        else {
          console.warn(res.message);
          logPreviewList.innerHTML = '';
          logPreviewEmpty.textContent = res.message || 'Failed to load the logistic list.';
          logPreviewEmpty.style.display = 'block';
        }
      })
      .catch(function (e) {
        console.error(e);
        logPreviewList.innerHTML = '';
        logPreviewEmpty.textContent = 'Failed to load the logistic list (invalid server response).';
        logPreviewEmpty.style.display = 'block';
      });
  }
  // ---------- Tab 2: Movements ----------
  // Same collapsible click/dblclick behaviour as stBindCollapsible() in
  // transaction_content.php, reimplemented here (own class names
  // log-mov-* / log-mov-open) since each *_content.php file is its own
  // IIFE and doesn't share JS scope with the others.
  function logMovBindCollapsible(card, head) {
    var timer = null;
    card.classList.add('log-mov-collapsible');
    head.classList.add('log-mov-head');
    head.setAttribute('tabindex', '0');

    function toggleExclusive() {
      var willOpen = !card.classList.contains('log-mov-open');
      if (willOpen && card.parentNode) {
        Array.prototype.forEach.call(card.parentNode.children, function (sib) {
          if (sib !== card && sib.classList.contains('log-mov-collapsible')) sib.classList.remove('log-mov-open');
        });
      }
      card.classList.toggle('log-mov-open', willOpen);
    }

    head.addEventListener('click', function () {
      if (timer) clearTimeout(timer);
      timer = setTimeout(function () { timer = null; toggleExclusive(); }, 220);
    });
    head.addEventListener('dblclick', function () {
      if (timer) { clearTimeout(timer); timer = null; }
      card.classList.toggle('log-mov-open');
    });
    head.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleExclusive(); }
    });
  }

  function logMovChevron() {
    var c = document.createElement('i');
    c.className = 'ti ti-chevron-down log-mov-chevron';
    return c;
  }

  function fmtIDR(v) {
    var n = Number(v || 0);
    if (isNaN(n)) n = 0;
    return 'Rp ' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
  }

  // Actual Taken = Taken (out) - Returned (in), same "Actual" convention
  // already used in Sales Transaction > Customers tab — for QTY. For VALUE,
  // a price adjustment (discount) also has to be subtracted: it doesn't
  // move stock, but it does lower what the customer owes for goods already
  // taken, same as "Total Actual = Total Ordered - Total Returned - Total
  // Discounts" in transaction_content.php's customer card. Without this,
  // Actual Taken's value here read higher than the equivalent figure in the
  // Customers tab for any product that ever had a discount applied.
  function logMovActual(totalOut, totalIn, totalAdjustment) {
    var adjValue = totalAdjustment ? totalAdjustment.value : 0;
    return {
      qty:   round2(totalOut.qty - totalIn.qty),
      value: round2(totalOut.value - totalIn.value - adjValue)
    };
  }
  function round2(n) { return Math.round((Number(n) || 0) * 100) / 100; }

  // "Taken: 12 CTN · Rp 1,200,000" / "Returned: 2 CTN · Rp 200,000" /
  // "Discount: Rp 100,000" (only shown if > 0) /
  // "Actual Taken: 10 CTN · Rp 900,000"
  function logMovTotalsEl(totalOut, totalIn, totalAdjustment, unitLabel) {
    var wrap = document.createElement('div');
    wrap.className = 'log-mov-totals';
    var outLine = document.createElement('div');
    outLine.className = 'log-mov-out';
    outLine.textContent = 'Taken: ' + fmtNum(totalOut.qty) + ' ' + (unitLabel || '') + ' \u00b7 ' + fmtIDR(totalOut.value);
    var inLine = document.createElement('div');
    inLine.className = 'log-mov-in';
    inLine.textContent = 'Returned: ' + fmtNum(totalIn.qty) + ' ' + (unitLabel || '') + ' \u00b7 ' + fmtIDR(totalIn.value);
    wrap.appendChild(outLine);
    wrap.appendChild(inLine);
    var adjValue = totalAdjustment ? totalAdjustment.value : 0;
    if (adjValue > 0) {
      var adjLine = document.createElement('div');
      adjLine.className = 'log-mov-adj';
      adjLine.textContent = 'Discount: \u2212 ' + fmtIDR(adjValue);
      wrap.appendChild(adjLine);
    }
    var actual = logMovActual(totalOut, totalIn, totalAdjustment);
    var actualLine = document.createElement('div');
    actualLine.className = 'log-mov-actual';
    actualLine.textContent = 'Actual Taken: ' + fmtNum(actual.qty) + ' ' + (unitLabel || '') + ' \u00b7 ' + fmtIDR(actual.value);
    wrap.appendChild(actualLine);
    return wrap;
  }

  function logMovFormatTime(createdAt) {
    if (!createdAt) return '';
    var m = String(createdAt).match(/(\d{2}):(\d{2})/);
    return m ? (m[1] + ':' + m[2]) : '';
  }

  function renderLogMovLine(mv, unitLabel) {
    var line = document.createElement('div');
    line.className = 'log-mov-line';

    var left = document.createElement('div');
    left.className = 'log-mov-line-left';
    var badge = document.createElement('span');
    // 'in' = physically returned, 'price_adjustment' = kept by the customer,
    // just sold cheaper (no stock movement at all — see "Kendala #1 & #2" in
    // PROJECT_NOTES.md, 22 Sep 2026), anything else = 'out' (Taken).
    var badgeClass, badgeText;
    if (mv.movement_type === 'in') {
      badgeClass = 'badge-success';
      badgeText = 'Returned';
    } else if (mv.movement_type === 'price_adjustment') {
      badgeClass = 'badge-danger';
      badgeText = 'Discount';
    } else {
      badgeClass = 'badge-warning';
      badgeText = 'Taken';
    }
    badge.className = 'badge ' + badgeClass;
    badge.textContent = badgeText;
    left.appendChild(badge);
    // Tag the bucket a pickup/restock actually touched, so a defective-stock
    // movement never looks identical to a normal one in this list — added
    // 27 Sep 2026, see PROJECT_NOTES.md "Redesain besar: hapus tab
    // Defective Stock...". Only shown for 'defective' — 'normal' is the
    // silent default and doesn't need its own badge.
    if (mv.stock_source === 'defective') {
      var srcBadge = document.createElement('span');
      srcBadge.className = 'badge badge-danger';
      srcBadge.style.marginLeft = 'var(--space-1)';
      srcBadge.textContent = 'DEFECTIVE';
      left.appendChild(srcBadge);
    }
    var sub = document.createElement('div');
    sub.className = 'log-mov-line-sub';
    var subParts = [];
    if (logMovFormatTime(mv.created_at)) subParts.push(logMovFormatTime(mv.created_at));
    if (mv.customer_name) subParts.push(mv.customer_name);
    if (mv.driver_name) subParts.push(mv.driver_name);
    if (mv.police_number) subParts.push(mv.police_number);
    sub.textContent = subParts.join(' \u00b7 ');
    left.appendChild(sub);

    var right = document.createElement('div');
    right.className = 'log-mov-line-right';
    right.textContent = fmtNum(mv.qty) + ' ' + (unitLabel || '');
    var rightSub = document.createElement('div');
    rightSub.className = 'log-mov-line-sub';
    // total_price for 'price_adjustment' is the DISCOUNT amount (a positive
    // number in the DB, same convention create_price_adjustment.php uses for
    // total_price/total_outflow) — the minus sign here is purely display,
    // to read at a glance as "money taken off", same idea as Returned rows
    // in Sales Transaction > Customers.
    rightSub.textContent = mv.total_price !== null
      ? (mv.movement_type === 'price_adjustment' ? '\u2212 ' : '') + fmtIDR(mv.total_price)
      : '';
    right.appendChild(rightSub);

    line.appendChild(left);
    line.appendChild(right);
    return line;
  }

  // Per-item "Valid" / "Invalid" badge, shown right next to the activity
  // name in EACH logistic (= activity code) card header — visible
  // immediately even while that card is collapsed, so a problem can be
  // narrowed down to a specific product at a glance instead of having to
  // expand every card to find it. Reuses the existing .badge component
  // instead of a bespoke style.
  //
  // Actual Taken (derived from movement history, out - in, excluding
  // price_adjustment) must equal total_taken_qty — the source-agnostic
  // "currently with customers" balance kept in sync by create_order.php
  // (+, both stock_source buckets) and create_return.php (-, both
  // restock_bucket destinations), and left untouched by
  // create_price_adjustment.php.
  //
  // NOT primary_qty - remaining_primary_qty: since the defective-stock
  // feature (22 Sep 2026), remaining_primary_qty only ever reflects the
  // 'normal' bucket, so any product ever taken as stock_source='defective',
  // or returned with restock_bucket='defective', would show a false
  // Invalid even when every movement is accounted for correctly. See
  // PROJECT_NOTES.md, "Bugfix: logMovValidTag pakai field salah (defective
  // stock)".
  function logMovValidTag(actualQty, totalTakenQty) {
    var tag = document.createElement('span');
    if (totalTakenQty === null) {
      tag.className = 'badge';
      tag.textContent = 'Not validated';
      return tag;
    }
    var ok = Math.abs(round2(actualQty) - round2(totalTakenQty)) < 0.01;
    tag.className = 'badge ' + (ok ? 'badge-success' : 'badge-danger');
    tag.textContent = ok ? 'Valid' : 'Invalid';
    return tag;
  }

  // Second, separate badge: normal-bucket qty (out_normal - in_normal,
  // stock_source/restock_bucket = 'normal' only) vs what
  // remaining_primary_qty implies (primary_qty - remaining_primary_qty).
  // This is NOT the same check as logMovValidTag above — that one validates
  // total_taken_qty (normal+defective combined) against Actual Taken (also
  // combined). This one validates remaining_primary_qty specifically,
  // which the fix on 27 Sep 2026 established only ever reflects the
  // 'normal' bucket, so it must be compared against normal-only movement,
  // never against the combined total (that was the original bug).
  function logMovNormalValidTag(outNormalQty, inNormalQty, primaryQty, remainingPrimaryQty) {
    var tag = document.createElement('span');
    if (primaryQty === null || remainingPrimaryQty === null) {
      tag.className = 'badge';
      tag.textContent = 'Not validated';
      return tag;
    }
    var normalNet = round2(outNormalQty - inNormalQty);
    var expected  = round2(primaryQty - remainingPrimaryQty);
    var ok = Math.abs(normalNet - expected) < 0.01;
    tag.className = 'badge ' + (ok ? 'badge-success' : 'badge-danger');
    tag.textContent = ok ? 'Valid' : 'Invalid';
    return tag;
  }

  // Per-customer breakdown, rendered inside each activity code's Validation
  // card. Reconciles what this activity code's movement history says about
  // each customer against what Sales Transaction > Customers would compute
  // for that same (logistic, customer) pair — both ultimately read from the
  // same logistic_movements rows, so this is less "catches arithmetic bugs"
  // and more "guarantees the two independently-built tabs never silently
  // drift apart" (e.g. if one query's WHERE clause changes later and the
  // other doesn't). Reuses logMovActual/logMovTotalsEl/logMovValidTag so a
  // real mismatch, if one ever appears, is computed identically here and in
  // every other card.
  //
  // Only INVALID customers are listed — a fully-valid activity code (the
  // common case) would otherwise force-render one row per customer just to
  // say "fine" over and over, which is noise, not signal, and was the
  // direct cause of the cramped/cluttered mobile layout reported after the
  // first version of this card. Each listed customer's row is itself
  // collapsible (name = header, Taken/Returned/Discount/Actual = body) so
  // even the flagged rows stay compact until opened.
  function logMovByCustomerEl(byCustomer, unitLabel) {
    var wrap = document.createElement('div');
    var list = byCustomer || [];
    var invalid = list.filter(function (c) {
      var actual = logMovActual(c.total_out, c.total_in, c.total_adjustment);
      return Math.abs(round2(actual.qty) - round2(c.total_out.qty - c.total_in.qty)) >= 0.01;
    });

    if (!list.length) {
      var empty = document.createElement('div');
      empty.className = 'log-mov-subtitle';
      empty.textContent = 'No customer movements yet.';
      wrap.appendChild(empty);
      return wrap;
    }
    if (!invalid.length) {
      var ok = document.createElement('div');
      ok.className = 'log-mov-subtitle';
      ok.textContent = 'All ' + list.length + ' customer' + (list.length === 1 ? '' : 's') + ' valid.';
      wrap.appendChild(ok);
      return wrap;
    }

    invalid.forEach(function (c) {
      var row = document.createElement('div');
      row.className = 'log-mov-customer-row log-mov-collapsible';

      var head = document.createElement('div');
      var left = document.createElement('div');
      var name = document.createElement('div');
      name.className = 'log-mov-customer-name';
      name.textContent = c.customer_name || '(Unknown customer)';
      left.appendChild(name);
      var actual = logMovActual(c.total_out, c.total_in, c.total_adjustment);
      left.appendChild(logMovValidTag(actual.qty, round2(c.total_out.qty - c.total_in.qty)));
      head.appendChild(left);
      head.appendChild(logMovChevron());
      row.appendChild(head);

      var body = document.createElement('div');
      body.className = 'log-mov-body';
      body.appendChild(logMovTotalsEl(c.total_out, c.total_in, c.total_adjustment, unitLabel));
      row.appendChild(body);

      logMovBindCollapsible(row, head);
      wrap.appendChild(row);
    });
    return wrap;
  }

  // Card Validation — nested INSIDE each activity code's own
  // log-mov-logistic-card, positioned above the Detail Movement card, both
  // collapsible. Contains this activity code's own Valid/Invalid badge (top
  // — Actual Taken vs total_taken_qty), the normal-bucket-vs-
  // remaining_primary_qty badge, and the per-customer breakdown — but only
  // shows each check's Taken/Returned/Discount/Actual figures when that
  // check is Invalid. A fully-valid activity code (the common case) renders
  // as three badges and nothing else; expanding it isn't needed to confirm
  // everything is fine, and there's nothing to dig into anyway.
  function logMovValidationCard(lg, unitLabel) {
    var card = document.createElement('div');
    card.className = 'log-mov-sub-card';

    var head = document.createElement('div');
    var titleWrap = document.createElement('div');
    var title = document.createElement('div');
    title.className = 'log-mov-title';
    title.style.cssText = 'display:flex; align-items:center; gap:var(--space-2); flex-wrap:wrap;';
    var titleText = document.createElement('span');
    titleText.textContent = 'Validation';
    title.appendChild(titleText);
    var actual = logMovActual(lg.total_out, lg.total_in, lg.total_adjustment);
    var actualOk = Math.abs(round2(actual.qty) - round2(lg.total_taken_qty)) < 0.01;
    title.appendChild(logMovValidTag(actual.qty, lg.total_taken_qty));
    titleWrap.appendChild(title);
    head.appendChild(titleWrap);
    head.appendChild(logMovChevron());
    card.appendChild(head);

    var body = document.createElement('div');
    body.className = 'log-mov-body';

    var actualRow = document.createElement('div');
    actualRow.className = 'log-mov-customer-row';
    var actualLeft = document.createElement('div');
    var actualLabel = document.createElement('div');
    actualLabel.className = 'log-mov-customer-name';
    actualLabel.textContent = 'Actual Taken';
    actualLeft.appendChild(actualLabel);
    actualRow.appendChild(actualLeft);
    body.appendChild(actualRow);
    // Taken/Returned/Discount/Actual figures only shown when this specific
    // check is Invalid — see function doc comment above.
    if (!actualOk) {
      body.appendChild(logMovTotalsEl(lg.total_out, lg.total_in, lg.total_adjustment, unitLabel));
    }

    var normalOk = lg.primary_qty !== null && lg.remaining_primary_qty !== null &&
      Math.abs(round2(lg.total_out_normal_qty - lg.total_in_normal_qty) - round2(lg.primary_qty - lg.remaining_primary_qty)) < 0.01;
    var remRow = document.createElement('div');
    remRow.className = 'log-mov-customer-row';
    var remLeft = document.createElement('div');
    var remLabel = document.createElement('div');
    remLabel.className = 'log-mov-customer-name';
    remLabel.textContent = 'Remaining Primary Qty (normal stock)';
    remLeft.appendChild(remLabel);
    remLeft.appendChild(logMovNormalValidTag(
      lg.total_out_normal_qty, lg.total_in_normal_qty, lg.primary_qty, lg.remaining_primary_qty
    ));
    remRow.appendChild(remLeft);
    if (!normalOk) {
      var remRight = document.createElement('div');
      remRight.className = 'log-mov-totals';
      remRight.textContent = lg.remaining_primary_qty !== null
        ? (fmtNum(lg.remaining_primary_qty) + ' / ' + fmtNum(lg.primary_qty) + ' ' + (unitLabel || ''))
        : '\u2014';
      remRow.appendChild(remRight);
    }
    body.appendChild(remRow);

    var custHeading = document.createElement('div');
    custHeading.className = 'log-mov-subtitle';
    custHeading.style.marginTop = 'var(--space-3)';
    custHeading.textContent = 'Per customer (vs Sales Transaction > Customers)';
    body.appendChild(custHeading);
    body.appendChild(logMovByCustomerEl(lg.by_customer, unitLabel));

    card.appendChild(body);
    logMovBindCollapsible(card, head);
    return card;
  }

  // Top-of-page validation card — sits ABOVE the per-item cards, one row
  // per activity code (same unit as the cards below), NOT one combined
  // grand total. Lets the user scan every activity code's Valid/Invalid
  // status and its Taken/Returned/Discount/Actual/Expected numbers in one
  // place without expanding each card individually — the per-item badge in
  // each card's own title (logMovValidTag above) still exists side by side
  // with this, so a problem found here can be jumped to and audited in
  // detail below. Deliberately reuses logMovActual/logMovValidTag/
  // logMovTotalsEl instead of re-deriving the same numbers, so this card
  // can never drift out of sync with what each item's own card shows.
  function renderLogisticMovements(list) {
    var container = document.getElementById('logMovementsList');
    var empty = document.getElementById('logMovementsEmpty');
    container.innerHTML = '';
    empty.style.display = list.length ? 'none' : 'block';

    list.forEach(function (lg) {
      var unitLabel = lg.primary_unit_label || '';

      var mainCard = document.createElement('div');
      mainCard.className = 'log-mov-logistic-card';
      var mainHead = document.createElement('div');
      var mainTitle = document.createElement('div');
      mainTitle.className = 'log-mov-title';
      mainTitle.style.cssText = 'display:flex; align-items:center; gap:var(--space-2); flex-wrap:wrap;';
      var mainTitleText = document.createElement('span');
      mainTitleText.textContent = lg.activity_name;
      mainTitle.appendChild(mainTitleText);
      var mainActual = logMovActual(lg.total_out, lg.total_in, lg.total_adjustment);
      mainTitle.appendChild(logMovValidTag(mainActual.qty, lg.total_taken_qty));
      var mainSub = document.createElement('div');
      mainSub.className = 'log-mov-subtitle';
      mainSub.textContent = 'Activity Code ' + lg.activity_code + ' \u00b7 ' + lg.department;
      var mainTitleWrap = document.createElement('div');
      mainTitleWrap.appendChild(mainTitle);
      mainTitleWrap.appendChild(mainSub);
      mainHead.appendChild(mainTitleWrap);
      var mainTotals = logMovTotalsEl(lg.total_out, lg.total_in, lg.total_adjustment, unitLabel);
      mainHead.appendChild(mainTotals);
      mainHead.appendChild(logMovChevron());
      mainCard.appendChild(mainHead);

      var mainBody = document.createElement('div');
      mainBody.className = 'log-mov-body';

      // Card Validation sits first, above Card Detail Movement — see
      // logMovValidationCard() above.
      mainBody.appendChild(logMovValidationCard(lg, unitLabel));

      // Card Detail Movement wraps the existing year/month/day hierarchy,
      // now nested one level deeper (inside mainBody, alongside the
      // Validation card) instead of being mainBody's only content.
      var detailCard = document.createElement('div');
      detailCard.className = 'log-mov-sub-card';
      var detailHead = document.createElement('div');
      var detailTitle = document.createElement('div');
      detailTitle.className = 'log-mov-title';
      detailTitle.textContent = 'Detail Movement';
      detailHead.appendChild(detailTitle);
      detailHead.appendChild(logMovChevron());
      detailCard.appendChild(detailHead);

      var detailBody = document.createElement('div');
      detailBody.className = 'log-mov-body';

      lg.years.forEach(function (y) {
        var yCard = document.createElement('div');
        yCard.className = 'log-mov-year-card';
        var yHead = document.createElement('div');
        var yTitle = document.createElement('div');
        yTitle.className = 'log-mov-title';
        yTitle.textContent = y.year;
        yHead.appendChild(yTitle);
        yHead.appendChild(logMovTotalsEl(y.total_out, y.total_in, y.total_adjustment, unitLabel));
        yHead.appendChild(logMovChevron());
        yCard.appendChild(yHead);

        var yBody = document.createElement('div');
        yBody.className = 'log-mov-body';

        y.months.forEach(function (mo) {
          var moCard = document.createElement('div');
          moCard.className = 'log-mov-month-card';
          var moHead = document.createElement('div');
          var moTitle = document.createElement('div');
          moTitle.className = 'log-mov-title';
          moTitle.textContent = mo.month_label;
          moHead.appendChild(moTitle);
          moHead.appendChild(logMovTotalsEl(mo.total_out, mo.total_in, mo.total_adjustment, unitLabel));
          moHead.appendChild(logMovChevron());
          moCard.appendChild(moHead);

          var moBody = document.createElement('div');
          moBody.className = 'log-mov-body';

          mo.days.forEach(function (d) {
            var dCard = document.createElement('div');
            dCard.className = 'log-mov-day-card';
            var dHead = document.createElement('div');
            var dTitle = document.createElement('div');
            dTitle.className = 'log-mov-title';
            dTitle.textContent = d.date_label;
            dHead.appendChild(dTitle);
            dHead.appendChild(logMovTotalsEl(d.total_out, d.total_in, d.total_adjustment, unitLabel));
            dHead.appendChild(logMovChevron());
            dCard.appendChild(dHead);

            var dBody = document.createElement('div');
            dBody.className = 'log-mov-body';
            d.movements.forEach(function (mv) { dBody.appendChild(renderLogMovLine(mv, unitLabel)); });
            dCard.appendChild(dBody);

            logMovBindCollapsible(dCard, dHead);
            moBody.appendChild(dCard);
          });

          moCard.appendChild(moBody);
          logMovBindCollapsible(moCard, moHead);
          yBody.appendChild(moCard);
        });

        yCard.appendChild(yBody);
        logMovBindCollapsible(yCard, yHead);
        detailBody.appendChild(yCard);
      });

      detailCard.appendChild(detailBody);
      logMovBindCollapsible(detailCard, detailHead);
      mainBody.appendChild(detailCard);

      mainCard.appendChild(mainBody);
      logMovBindCollapsible(mainCard, mainHead);
      container.appendChild(mainCard);
    });
  }

  function loadLogisticMovements() {
    fetch('ajax/list_logistic_movements.php')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) renderLogisticMovements(res.data);
        else console.warn(res.message);
      })
      .catch(function (e) { console.error(e); });
  }

  // ---------- Tab 3: Create New Logistic ----------
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
    updateAllProductBlocksAvailability();
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
          opt.textContent = a.year + ' — ' + a.activity_code + ' — ' + a.activity_name
            + (a.has_logistic ? ' (has: ' + a.existing_products.join(', ') + ')' : '');
          logActivityCode.appendChild(opt);
        });
      })
      .catch(function (e) { console.error(e); });
  }

  logDepartment.addEventListener('change', loadActivityCodes);

  logActivityCode.addEventListener('change', function () {
    selectedActivity = activityById[logActivityCode.value] || null;
    updateAllProductBlocksAvailability();
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

  // ---------- Products: one .logProductBlock per product ----------
  // Since 28 Sep 2026 one activity code can hold several products, so the
  // Create form holds a repeatable list of product blocks instead of a
  // single set of Primary/Secondary Packaging fields. Each block owns its
  // own qty-source lock (Primary drives Secondary, or vice versa) via
  // block._qtySource — same logic as before, just scoped per block instead
  // of to one global pair of fields.
  var logProductsContainer = document.getElementById('logProductsContainer');
  var cachedPrimaryUnits   = [];
  var cachedSecondaryUnits = [];
  var logProdBlockSeq = 0;

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

  // Used by the Edit Logistic modal (single product, id-based selects) —
  // still needed as-is, kept separate from the Create form's per-block cache.
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

  function recalcProductBlock(block) {
    var qtyEl        = block.querySelector('.logProdPrimaryQty');
    var unitEl       = block.querySelector('.logProdPrimaryUnit');
    var weightEl     = block.querySelector('.logProdPrimaryWeight');
    var secQtyEl     = block.querySelector('.logProdSecondaryQty');
    var secUnitEl    = block.querySelector('.logProdSecondaryUnit');
    var secWeightEl  = block.querySelector('.logProdSecondaryWeight');

    var pOpt = unitEl.selectedOptions[0];
    var pWeight = pOpt ? parseFloat(pOpt.getAttribute('data-weight')) : NaN;

    var sOpt = secUnitEl.selectedOptions[0];
    var ratio = sOpt ? parseFloat(sOpt.getAttribute('data-ratio')) : NaN;
    var sWeight = sOpt ? parseFloat(sOpt.getAttribute('data-weight')) : NaN;

    var primaryQty, secondaryQty;

    if (block._qtySource === 'secondary') {
      var secQtyInput = parseNumberInput(secQtyEl.value);
      if (!isNaN(secQtyInput) && !isNaN(ratio) && ratio !== 0 && secUnitEl.value !== '') {
        secondaryQty = secQtyInput;
        primaryQty = secQtyInput / ratio;
        qtyEl.value = formatNumberInput(primaryQty.toFixed(3).replace(/\.?0+$/, ''));
      } else {
        primaryQty = NaN;
        secondaryQty = secQtyInput;
        qtyEl.value = '';
      }
    } else {
      // qtySource is 'primary' or null — Primary drives Secondary, same as before.
      var priQtyInput = parseNumberInput(qtyEl.value);
      primaryQty = priQtyInput;
      if (!isNaN(priQtyInput) && !isNaN(ratio) && secUnitEl.value !== '') {
        secondaryQty = priQtyInput * ratio;
        secQtyEl.value = formatNumberInput(secondaryQty.toFixed(2).replace(/\.?0+$/, ''));
      } else {
        secondaryQty = NaN;
        secQtyEl.value = '';
      }
    }

    weightEl.value = (!isNaN(primaryQty) && !isNaN(pWeight) && unitEl.value !== '')
      ? formatNumberInput((primaryQty * pWeight).toFixed(3).replace(/\.?0+$/, '')) : '';
    secWeightEl.value = (!isNaN(secondaryQty) && !isNaN(sWeight) && secUnitEl.value !== '')
      ? formatNumberInput((secondaryQty * sWeight).toFixed(3).replace(/\.?0+$/, '')) : '';
  }

  function updateProductBlockAvailability(block) {
    var enabled = !!selectedActivity;
    block.querySelectorAll('.logProdName, .logProdPrimaryUnit, .logProdSecondaryUnit').forEach(function (el) { el.disabled = !enabled; });
    if (block._qtySource === 'primary') {
      block.querySelector('.logProdPrimaryQty').disabled = !enabled;
      block.querySelector('.logProdSecondaryQty').disabled = true;
    } else if (block._qtySource === 'secondary') {
      block.querySelector('.logProdPrimaryQty').disabled = true;
      block.querySelector('.logProdSecondaryQty').disabled = !enabled;
    } else {
      block.querySelector('.logProdPrimaryQty').disabled = !enabled;
      block.querySelector('.logProdSecondaryQty').disabled = !enabled;
    }
  }

  function updateAllProductBlocksAvailability() {
    logProductsContainer.querySelectorAll('.logProductBlock').forEach(updateProductBlockAvailability);
  }

  var logProdBlockTpl = document.getElementById('logProductBlockTemplate');

  function createProductBlock() {
    var block = logProdBlockTpl.content.firstElementChild.cloneNode(true);
    logProdBlockSeq += 1;
    block._qtySource = null; // 'primary' | 'secondary' | null — mirrors old global qtySource, scoped to this block

    var nameEl       = block.querySelector('.logProdName');
    var qtyEl        = block.querySelector('.logProdPrimaryQty');
    var unitEl       = block.querySelector('.logProdPrimaryUnit');
    var secQtyEl     = block.querySelector('.logProdSecondaryQty');
    var secUnitEl    = block.querySelector('.logProdSecondaryUnit');
    var infoIcon     = block.querySelector('.logProdPrimaryInfoIcon');
    var infoTooltip  = block.querySelector('.logProdPrimaryInfoTooltip');
    var secInfoIcon  = block.querySelector('.logProdSecondaryInfoIcon');
    var secInfoTooltip = block.querySelector('.logProdSecondaryInfoTooltip');
    var removeBtn    = block.querySelector('.logProdRemove');

    fillUnitSelect(unitEl, cachedPrimaryUnits, 'primary');
    fillUnitSelect(secUnitEl, cachedSecondaryUnits, 'secondary');
    initNumberCommaInput(qtyEl);
    initNumberCommaInput(secQtyEl);

    // Same uppercase-as-you-type behaviour as the page's static .input-uppercase
    // fields (wired once at load for elements that exist then — this one is
    // created later, so it needs its own listener).
    nameEl.addEventListener('input', function () {
      var pos = nameEl.selectionStart;
      nameEl.value = nameEl.value.toUpperCase();
      if (pos !== null) nameEl.setSelectionRange(pos, pos);
    });

    qtyEl.addEventListener('input', function () {
      block._qtySource = qtyEl.value.trim() === '' ? null : 'primary';
      updateProductBlockAvailability(block);
      recalcProductBlock(block);
    });
    secQtyEl.addEventListener('input', function () {
      block._qtySource = secQtyEl.value.trim() === '' ? null : 'secondary';
      updateProductBlockAvailability(block);
      recalcProductBlock(block);
    });
    unitEl.addEventListener('change', function () { recalcProductBlock(block); });
    secUnitEl.addEventListener('change', function () { recalcProductBlock(block); });

    infoIcon.addEventListener('click', function () { infoTooltip.classList.toggle('open'); });
    infoIcon.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); infoTooltip.classList.toggle('open'); }
    });
    secInfoIcon.addEventListener('click', function () { secInfoTooltip.classList.toggle('open'); });
    secInfoIcon.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); secInfoTooltip.classList.toggle('open'); }
    });

    removeBtn.addEventListener('click', function () {
      // Always keep at least one block — remove just clears it instead of
      // leaving the form with no product at all.
      if (logProductsContainer.querySelectorAll('.logProductBlock').length <= 1) {
        nameEl.value = '';
        qtyEl.value = ''; secQtyEl.value = '';
        block._qtySource = null;
        updateProductBlockAvailability(block);
        recalcProductBlock(block);
        return;
      }
      block.remove();
    });

    updateProductBlockAvailability(block);
    return block;
  }

  function addProductBlock() {
    var block = createProductBlock();
    logProductsContainer.appendChild(block);
    return block;
  }

  document.getElementById('btnLogAddProduct').addEventListener('click', function () {
    if (!selectedActivity) {
      logCreateError.textContent = 'Select an Activity Code first.';
      logCreateError.style.display = 'block';
      return;
    }
    addProductBlock();
  });

  function resetProductBlocks() {
    logProductsContainer.innerHTML = '';
    addProductBlock();
  }

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
    fetch('ajax/manage_packaging_units.php?kind=primary&action=list')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) return;
        cachedPrimaryUnits = res.units;
        logProductsContainer.querySelectorAll('.logProductBlock').forEach(function (block) {
          fillUnitSelect(block.querySelector('.logProdPrimaryUnit'), cachedPrimaryUnits, 'primary');
          recalcProductBlock(block);
        });
      })
      .catch(function (e) { console.error(e); });

    fetch('ajax/manage_packaging_units.php?kind=secondary&action=list')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) return;
        cachedSecondaryUnits = res.units;
        logProductsContainer.querySelectorAll('.logProductBlock').forEach(function (block) {
          fillUnitSelect(block.querySelector('.logProdSecondaryUnit'), cachedSecondaryUnits, 'secondary');
          recalcProductBlock(block);
        });
      })
      .catch(function (e) { console.error(e); });
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
    resetProductBlocks();
    logCreateError.style.display = 'none';
    updateAllProductBlocksAvailability();
    loadActivityCodes();
  }

  document.getElementById('btnLogCreateSubmit').addEventListener('click', function () {
    logCreateError.style.display = 'none';

    if (!selectedActivity) {
      logCreateError.textContent = 'Select an Activity Code first.';
      logCreateError.style.display = 'block';
      return;
    }

    var blocks = Array.prototype.slice.call(logProductsContainer.querySelectorAll('.logProductBlock'));
    if (blocks.length === 0) {
      logCreateError.textContent = 'Add at least one product.';
      logCreateError.style.display = 'block';
      return;
    }

    // Only rates are sent — totals (weight, secondary qty) are calculated
    // by the server when the Logistic List is loaded, never stored as-is.
    // qty fields are read through parseNumberInput() to strip the comma
    // grouping they display while typing — create_logistic.php's
    // is_numeric() check would otherwise reject "1,234.5".
    var products = [];
    for (var i = 0; i < blocks.length; i++) {
      var block = blocks[i];
      var name = block.querySelector('.logProdName').value.trim();
      if (name === '') {
        logCreateError.textContent = 'Fill in the product name for every product block (or remove the empty one).';
        logCreateError.style.display = 'block';
        return;
      }
      var pOpt = block.querySelector('.logProdPrimaryUnit').selectedOptions[0];
      var sOpt = block.querySelector('.logProdSecondaryUnit').selectedOptions[0];
      var primaryQtyClean = parseNumberInput(block.querySelector('.logProdPrimaryQty').value);
      products.push({
        product_name: name,
        primary_qty: isNaN(primaryQtyClean) ? '' : String(primaryQtyClean),
        primary_unit_label: pOpt ? (pOpt.getAttribute('data-label') || '') : '',
        primary_unit_weight_kg: pOpt ? (pOpt.getAttribute('data-weight') || '') : '',
        secondary_unit_label: sOpt ? (sOpt.getAttribute('data-label') || '') : '',
        secondary_unit_weight_kg: sOpt ? (sOpt.getAttribute('data-weight') || '') : '',
        secondary_ratio_per_primary: sOpt ? (sOpt.getAttribute('data-ratio') || '') : ''
      });
    }

    var payload = new URLSearchParams({
      activity_id: selectedActivity.id,
      incoming_date: document.getElementById('logIncomingDate').value,
      products: JSON.stringify(products)
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
  resetProductBlocks(); // one empty (disabled, until an activity is picked) product block, like the old single-product fields
  loadActivityCodes();
  loadLogisticList(); // Logistic List is the default tab on entering the menu
})();
</script>