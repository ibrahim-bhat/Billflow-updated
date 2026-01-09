<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

if (!isset($_SESSION['ai_parsed_invoices']) || !is_array($_SESSION['ai_parsed_invoices'])) {
    header('Location: index.php');
    exit();
}

$parsed = $_SESSION['ai_parsed_invoices'];
$invoices = $parsed['invoices'] ?? [];

$customers_res = $conn->query("SELECT id, name FROM customers ORDER BY name");
$customers = [];
while ($row = $customers_res->fetch_assoc()) { $customers[] = $row; }

// Get vendors for initial population
$vendors_res = $conn->query("SELECT id, name, shortcut_code FROM vendors ORDER BY name");
$vendors = [];
while ($row = $vendors_res->fetch_assoc()) { $vendors[] = $row; }

// Don't load all items here - they will be loaded per vendor via AJAX

// Helper function to normalize names for fuzzy matching (declared once)
function normalizeItemName($name) {
    return preg_replace('/[^a-z0-9]/i', '', strtolower($name));
}

// Get AI markers from settings
$settings_res = $conn->query("SELECT ai_prev_marker, ai_prev_prev_marker FROM company_settings LIMIT 1");
$settings = $settings_res->fetch_assoc();
$ai_prev_marker = strtolower(trim($settings['ai_prev_marker'] ?? 'p'));
$ai_prev_prev_marker = strtolower(trim($settings['ai_prev_prev_marker'] ?? 'pp'));
?>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Map Parsed Invoices</h2>
        <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="alert alert-info mb-4">
        <h6 class="alert-heading"><i class="fas fa-info-circle"></i> AI Date Markers Guide</h6>
        <ul class="mb-0" style="font-size: 0.9em;">
            <li><strong>No marker (e.g., "ib", "v1")</strong> → Today's inventory items</li>
            <li><strong>"<?php echo $ai_prev_marker; ?>" marker (e.g., "ib<?php echo $ai_prev_marker; ?>", "v1<?php echo $ai_prev_marker; ?>")</strong> → Yesterday's inventory items</li>
            <li><strong>"<?php echo $ai_prev_prev_marker; ?>" marker (e.g., "ib<?php echo $ai_prev_prev_marker; ?>", "v1<?php echo $ai_prev_prev_marker; ?>")</strong> → Day before yesterday's inventory items</li>
            <li><strong>Invoice numbers</strong> are auto-generated - you don't need to enter them</li>
        </ul>
    </div>

    <form method="post" action="../../handlers/ai/process.php" id="mapForm">
        <?php foreach ($invoices as $idx => $inv): ?>
            <div class="card mb-3 invoice-card" data-invoice-index="<?php echo $idx; ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Invoice #<?php echo $idx + 1; ?></strong>
                    <button type="button" class="btn btn-sm btn-danger remove-invoice-btn" data-invoice-index="<?php echo $idx; ?>">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Display Date</label>
                            <input type="date" class="form-control" name="invoices[<?php echo $idx; ?>][display_date]" value="<?php echo htmlspecialchars($inv['displayDate'] ?? date('Y-m-d')); ?>" required>
                            <small class="text-muted">Date to show on invoice</small>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Customer</label>
                            <select class="form-select" name="invoices[<?php echo $idx; ?>][customer_id]" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <?php $sel = (isset($inv['customerName']) && strtolower(trim($inv['customerName'])) === strtolower(trim($c['name']))) ? 'selected' : ''; ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th style="width: 28%">Item Name</th>
                                    <th style="width: 22%">Vendor</th>
                                    <th style="width: 10%">Qty</th>
                                    <th style="width: 10%">Weight</th>
                                    <th style="width: 10%">Rate</th>
                                    <th style="width: 10%">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $items = is_array($inv['items'] ?? null) ? $inv['items'] : []; ?>
                                <?php foreach ($items as $j => $it): ?>
                                    <tr>
                                        <td>
                                            <?php $parsedItemName = $it['itemName'] ?? ''; ?>
                                            <select class="form-select item-select" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][item_name]" 
                                                    data-parsed-item="<?php echo htmlspecialchars($parsedItemName); ?>" required>
                                                <option value="">Select Vendor First</option>
                                            </select>
                                            <input type="hidden" class="inventory-item-id" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][inventory_item_id]">
                                        </td>
                                        <td>
                                            <?php
                                            // Store vendor info and pre-populate dropdown
                                            $vendorShortcut = isset($it['vendorShortcut']) ? strtolower(trim($it['vendorShortcut'])) : '';
                                            $autoVendorId = '';
                                            
                                            // Find vendor by shortcut
                                            if ($vendorShortcut !== '') {
                                                foreach ($vendors as $v) {
                                                    if (!empty($v['shortcut_code']) && strtolower(trim($v['shortcut_code'])) === $vendorShortcut) {
                                                        $autoVendorId = $v['id'];
                                                        break;
                                                    }
                                                }
                                            }
                                            
                                            // Display batch marker info
                                            $vendorCodeRaw = $it['vendorCodeRaw'] ?? '';
                                            $batchMarker = $it['batchMarker'] ?? '';
                                            ?>
                                            <select class="form-select vendor-select" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][vendor_id]" 
                                                    data-vendor-shortcut="<?php echo htmlspecialchars($vendorShortcut); ?>" required>
                                                <option value="">Select Vendor</option>
                                                <?php foreach ($vendors as $v): ?>
                                                    <option value="<?php echo $v['id']; ?>" <?php echo ($autoVendorId && $autoVendorId == $v['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($v['name']); ?><?php echo !empty($v['shortcut_code']) ? ' ('.htmlspecialchars($v['shortcut_code']).')' : ''; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php
                                            // Persist raw code and marker to server on submit
                                            ?>
                                            <input type="hidden" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][vendor_code_raw]" value="<?php echo htmlspecialchars($vendorCodeRaw); ?>">
                                            <input type="hidden" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][batch_marker]" value="<?php echo htmlspecialchars($batchMarker); ?>">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" class="form-control" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][quantity]" value="<?php echo htmlspecialchars((string)($it['quantity'] ?? 0)); ?>">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" class="form-control" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][weight]" value="<?php echo htmlspecialchars((string)($it['weight'] ?? 0)); ?>">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" class="form-control" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][rate]" value="<?php echo htmlspecialchars((string)($it['rate'] ?? 0)); ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" class="form-control" name="invoices[<?php echo $idx; ?>][items][<?php echo $j; ?>][amount]" value="<?php echo htmlspecialchars((string)($it['amount'] ?? 0)); ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Insert Invoices</button>
            <a href="invoice_ai.php" class="btn btn-outline-secondary">Back</a>
        </div>
    </form>
</div>

<script>
// AI Markers from settings
const AI_PREV_MARKER = '<?php echo $ai_prev_marker; ?>';
const AI_PREV_PREV_MARKER = '<?php echo $ai_prev_prev_marker; ?>';

document.addEventListener('DOMContentLoaded', function() {
    // Setup all vendor selects
    document.querySelectorAll('select[name*="[vendor_id]"]').forEach(function(vendorSelect) {
        const row = vendorSelect.closest('tr');
        const itemSelect = row.querySelector('.item-select');
        
        // Load items when vendor is selected on page load
        if (vendorSelect.value) {
            loadVendorItems(vendorSelect.value, itemSelect);
        }
        
        // Load items when vendor changes
        vendorSelect.addEventListener('change', function() {
            const vendorId = this.value;
            if (vendorId) {
                loadVendorItems(vendorId, itemSelect);
            } else {
                itemSelect.innerHTML = '<option value="">Select Vendor First</option>';
            }
        });
    });
});

// Function to load items for a specific vendor
function loadVendorItems(vendorId, itemSelect) {
    const parsedItemName = itemSelect.getAttribute('data-parsed-item') || '';
    
    // Show loading state
    itemSelect.innerHTML = '<option value="">Loading items...</option>';
    
    // Fetch items from vendor
    fetch(`../get_vendor_items.php?vendor_id=${vendorId}`)
        .then(response => response.json())
        .then(data => {
            let optionsHtml = '<option value="">Select Item</option>';
            let matchedItem = null;
            
            // Helper function to extract base item name (remove date part)
            const getBaseItemName = (name) => {
                if (!name) return '';
                // Remove date pattern like (05/11/2025) or (dd/mm/yyyy)
                return name.replace(/\s*\(\d{2}\/\d{2}\/\d{4}\)\s*$/i, '').trim();
            };
            
            // Helper function to normalize names - remove spaces and special chars
            const normalizeName = (name) => {
                if (!name) return '';
                return name.replace(/[^a-z0-9]/gi, '').toLowerCase();
            };
            
            const normalizedParsed = normalizeName(parsedItemName);
            console.log('Looking for item:', parsedItemName, 'Normalized:', normalizedParsed);
            
            if (data.items && data.items.length > 0) {
                data.items.forEach(function(item) {
                    // Get base item name without date
                    const baseItemName = getBaseItemName(item.name);
                    const normalizedDbItem = normalizeName(baseItemName);
                    
                    // Check for exact or fuzzy match (ignoring dates)
                    const isExactMatch = (parsedItemName && baseItemName.toLowerCase() === parsedItemName.toLowerCase());
                    const isFuzzyMatch = (normalizedParsed && normalizedDbItem === normalizedParsed);
                    const isSelected = isExactMatch || isFuzzyMatch;
                    
                    console.log('Checking:', item.name, 'Base:', baseItemName, 'Normalized:', normalizedDbItem, 'Match:', isSelected);
                    
                    if (isSelected) {
                        matchedItem = item;
                    }
                    
                    optionsHtml += `<option value="${escapeHtml(item.name)}" 
                        data-rate="${item.last_rate || 0}"
                        data-stock="${item.available_stock || 0}"
                        data-inventory-item-id="${item.inventory_item_id || ''}"
                        ${isSelected ? 'selected' : ''}>
                        ${escapeHtml(item.name)} (Stock: ${item.available_stock || 0})
                    </option>`;
                });
                
                itemSelect.innerHTML = optionsHtml;
                
                console.log('Matched item:', matchedItem);
                
                // Auto-fill rate if item was matched
                if (matchedItem) {
                    const row = itemSelect.closest('tr');
                    const rateInput = row.querySelector('input[name*="[rate]"]');
                    const inventoryItemIdInput = row.querySelector('.inventory-item-id');
                    
                    if (rateInput && matchedItem.last_rate) {
                        rateInput.value = matchedItem.last_rate;
                    }
                    if (inventoryItemIdInput && matchedItem.inventory_item_id) {
                        inventoryItemIdInput.value = matchedItem.inventory_item_id;
                    }
                }
            } else {
                itemSelect.innerHTML = '<option value="">No items available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading items:', error);
            itemSelect.innerHTML = '<option value="">Error loading items</option>';
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Remove invoice functionality
document.querySelectorAll('.remove-invoice-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const invoiceIndex = this.getAttribute('data-invoice-index');
        const card = document.querySelector(`.invoice-card[data-invoice-index="${invoiceIndex}"]`);
        
        if (confirm('Are you sure you want to remove this invoice from the list?')) {
            card.style.transition = 'all 0.3s ease';
            card.style.opacity = '0';
            card.style.transform = 'scale(0.95)';
            
            setTimeout(function() {
                card.remove();
                
                // Check if any invoices remain
                const remainingInvoices = document.querySelectorAll('.invoice-card');
                if (remainingInvoices.length === 0) {
                    window.location.href = 'invoice_ai.php';
                } else {
                    // Renumber remaining invoices
                    remainingInvoices.forEach(function(card, idx) {
                        const header = card.querySelector('.card-header strong');
                        if (header) {
                            header.textContent = `Invoice #${idx + 1}`;
                        }
                    });
                }
            }, 300);
        }
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>


