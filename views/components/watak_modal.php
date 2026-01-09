<!-- Add Watak Modal -->
<div class="modal fade" id="watakModal" tabindex="-1" aria-labelledby="watakModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="watakModalLabel">Create Watak</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="../../handlers/watak/process.php" id="watakForm">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label for="vendor_id" class="form-label">Vendor <span class="text-danger">*</span></label>
                            <select class="form-select" id="vendor_id" name="vendor_id" required>
                                <option value="">Select Vendor</option>
                            </select>
                        </div>
                        <div class="col-md-3 hidden-field">
                            <label for="watak_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="watak_date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                            <small class="form-text text-muted">Watak date (today's date when creating the watak)</small>
                        </div>
                        <div class="col-md-3">
                            <label for="inventory_date" class="form-label">Inventory Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="inventory_date" name="inventory_date" value="<?php echo date('Y-m-d'); ?>" required>
                            <small class="form-text text-muted">Date of inventory/receipt</small>
                        </div>
                        <div class="col-md-3">
                            <label for="vehicle_no" class="form-label">Vehicle No.</label>
                            <input type="text" class="form-control" id="vehicle_no" name="vehicle_no">
                        </div>
                        <div class="col-md-3">
                            <label for="chalan_no" class="form-label">Chalan No.</label>
                            <input type="text" class="form-control" id="chalan_no" name="chalan_no" placeholder="Enter chalan number">
                        </div>
                    </div>

                    <div class="mt-4 mb-3" id="watak_items_container">
                        <div id="watak_empty_row" class="text-center text-muted py-4 border rounded">
                            Click "Add Item" to add items to the watak
                        </div>
                    </div>
                    
                    <button type="button" id="addRow" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-plus"></i> Add Item
                    </button>

                    <!-- Summary rows -->
                    <div class="row mt-3">
                        <div class="col-md-8 offset-md-4">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="text-end"><strong>Sub Total:</strong></td>
                                    <td width="150">₹<span id="subTotal">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-end"><strong>Total Commission:</strong></td>
                                    <td>₹<span id="totalCommission">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-end"><strong>Total Labor:</strong></td>
                                    <td>₹<span id="totalLabor">0.00</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-3 mb-3">
                            <label for="commission_percent">Commission %</label>
                            <div class="input-group">
                                <input type="number" name="commission_percent" id="commissionPercent" class="form-control" value="10" step="0.01" min="0" max="100">
                                <div class="input-group-append">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="labor_rate">Labor Rate</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="labor_rate" id="laborRate" class="form-control" value="1" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="vehicle_charges">Vehicle Charges</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="vehicle_charges" id="vehicleCharges" class="form-control" value="0" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="bardan">Bardan</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="bardan" id="bardan" class="form-control" value="0" step="0.01">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="other_charges">Other Charges</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="other_charges" id="otherCharges" class="form-control" value="0" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-9 mb-3">
                            <label>&nbsp;</label>
                            <div class="form-text">
                                <strong>Note:</strong> Commission is calculated as a percentage of subtotal. Labor charges are automatically calculated as (Labor Rate × Quantity) for all items except "Krade".
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-8 offset-md-4">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="text-end"><strong>Grand Total:</strong></td>
                                    <td width="150">₹<span id="grandTotal">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-end"><strong>Total Commission:</strong></td>
                                    <td>₹<span id="commissionTotal">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-end"><strong>Net Amount:</strong></td>
                                    <td>₹<span id="netAmount">0.00</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_watak" class="btn btn-primary">Create Watak</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Watak Modal -->
<div class="modal fade" id="viewWatakModal" tabindex="-1" aria-labelledby="viewWatakModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewWatakModalLabel">View Watak</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="watak_list_content">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- View Ledger Modal -->
<div class="modal fade" id="ledgerModal" tabindex="-1" aria-labelledby="ledgerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ledgerModalLabel">Vendor Ledger</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="ledger_content">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="export_vendor_ledger_pdf" href="#" target="_blank" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Save as PDF
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Load vendors when watak modal opens
document.getElementById('watakModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    const vendorType = button.getAttribute('data-vendor-type');
    
    // Reset form
    document.getElementById('watakForm').reset();
    document.getElementById('watak_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('inventory_date').value = new Date().toISOString().split('T')[0];
    
    // Clear items
    const cards = document.querySelectorAll('.watak-item-card');
    cards.forEach(card => card.remove());
    document.getElementById('watak_empty_row').style.display = '';
    
    // Reset totals
    calculateAllTotals();
    
    // Load vendors and auto-select the clicked vendor
    fetch('../../api/vendors/get_list.php')
        .then(response => response.json())
        .then(data => {
            const vendorSelect = document.getElementById('vendor_id');
            vendorSelect.innerHTML = '<option value="">Select Vendor</option>';
            
            if (data.success) {
                data.vendors.forEach(vendor => {
                    const option = document.createElement('option');
                    option.value = vendor.id;
                    option.textContent = vendor.name;
                    option.setAttribute('data-vendor-type', vendor.type);
                    vendorSelect.appendChild(option);
                });
                
                // Auto-select the vendor that was clicked
                if (vendorId) {
                    vendorSelect.value = vendorId;
                    
                    // Update commission and labor rates based on vendor type
                    if (vendorType === 'Local') {
                        document.getElementById('commissionPercent').value = '10';
                        document.getElementById('laborRate').value = '1';
                    } else if (vendorType === 'Outsider') {
                        document.getElementById('commissionPercent').value = '6';
                        document.getElementById('laborRate').value = '2';
                    }
                    
                    // Load all items for manual watak creation
                    loadAllItems();
                }
            } else {
                console.error('Error loading vendors:', data.error || 'Unknown error');
            }
        })
        .catch(error => console.error('Error loading vendors:', error));
});

// Add new row
document.getElementById('addRow').addEventListener('click', function() {
    const container = document.getElementById('watak_items_container');
    const emptyRow = document.getElementById('watak_empty_row');
    if (emptyRow) emptyRow.style.display = 'none';
    
    const index = container.querySelectorAll('.watak-item-card').length;
    
    const newItem = document.createElement('div');
    newItem.classList.add('watak-item-card', 'border', 'rounded', 'p-3', 'mb-3', 'position-relative');
    newItem.innerHTML = `
        <button type="button" class="btn btn-sm btn-danger delete-watak-item position-absolute top-0 end-0 m-2">
            <i class="fas fa-times"></i>
        </button>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Item Name <span class="text-danger">*</span></label>
                <select name="items[${index}][name]" class="form-control item-select" required>
                    <option value="">Select Item</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                <input type="number" name="items[${index}][quantity]" class="form-control quantity" value="0" step="0.01" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Weight (Optional)</label>
                <input type="number" name="items[${index}][weight]" class="form-control weight" value="0" step="0.01">
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <label class="form-label">Rate <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">₹</span>
                    <input type="text" name="items[${index}][rate]" class="form-control rate" value="0" required>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Total Amount</label>
                <div class="input-group">
                    <span class="input-group-text">₹</span>
                    <input type="number" class="form-control amount" value="0" readonly>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    
    // Add event listeners for calculations
    newItem.querySelectorAll('.quantity, .weight, .rate').forEach(input => {
        input.addEventListener('input', function() {
            calculateRowAmount(newItem);
            calculateAllTotals();
        });
        input.addEventListener('change', function() {
            calculateRowAmount(newItem);
            calculateAllTotals();
        });
    });
    
    // Populate item select dropdown for the new card with all items
    const itemSelect = newItem.querySelector('.item-select');
    console.log('Loading items for new card...');
    
    fetch('../../api/inventory/get_items_for_watak.php')
        .then(response => {
            console.log('Add card response status:', response.status);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Add card items data:', data);
            if (data.success && data.items && data.items.length > 0) {
                console.log('Loading ' + data.items.length + ' items into new card dropdown');
                data.items.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.name;
                    option.textContent = item.name;
                    option.setAttribute('data-rate', item.rate || 0);
                    option.setAttribute('data-weight', item.weight || 0);
                    itemSelect.appendChild(option);
                });
            } else {
                console.error('No items available for new card:', data.error || 'Unknown error');
                itemSelect.innerHTML = '<option value="">No items available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading items for new card:', error);
            itemSelect.innerHTML = '<option value="">Error loading items</option>';
        });
    
    // Add remove button functionality
    newItem.querySelector('.delete-watak-item').addEventListener('click', function() {
        newItem.remove();
        if (container.querySelectorAll('.watak-item-card').length === 0) {
            document.getElementById('watak_empty_row').style.display = '';
        }
        calculateAllTotals();
    });
    
    calculateAllTotals();
});

// Calculate row amount
function calculateRowAmount(card) {
    const quantity = parseFloat(card.querySelector('.quantity').value) || 0;
    const weight = parseFloat(card.querySelector('.weight').value) || 0;
    const rate = parseFloat(card.querySelector('.rate').value) || 0;
    
    // Calculate amount based on weight vs quantity logic
    let amount = 0;
    if (weight > 0) {
        // If weight is provided, use weight × rate
        amount = weight * rate;
    } else if (quantity > 0) {
        // If weight is not provided but quantity is, use quantity × rate
        amount = quantity * rate;
    }
    
    card.querySelector('.amount').value = amount.toFixed(2);
    calculateAllTotals();
}

// Calculate all summary totals
function calculateAllTotals() {
    let subTotal = 0;
    let totalLabor = 0;
    
    // Get labor rate from input field
    const laborRate = parseFloat(document.getElementById('laborRate').value) || 1;
    
    // Process each card to calculate subtotal and labor
    document.querySelectorAll('.watak-item-card').forEach(card => {
        const quantity = parseFloat(card.querySelector('.quantity').value) || 0;
        const weight = parseFloat(card.querySelector('.weight').value) || 0;
        const rate = parseFloat(card.querySelector('.rate').value) || 0;
        
        // Calculate amount based on weight vs quantity logic
        let amount = 0;
        if (weight > 0) {
            // If weight is provided, use weight × rate
            amount = weight * rate;
        } else if (quantity > 0) {
            // If weight is not provided but quantity is, use quantity × rate
            amount = quantity * rate;
        }
        
        card.querySelector('.amount').value = amount.toFixed(2);
        
        // Add to subtotal
        subTotal += amount;
        
        // Calculate labor for this item (labor rate * quantity for all items except "Krade")
        const itemSelect = card.querySelector('.item-select');
        const itemName = itemSelect ? itemSelect.value : '';
        if (itemName.toLowerCase() !== 'krade') {
            totalLabor += quantity * laborRate;
        }
    });
    
    // Calculate commission as percentage of subtotal
    const commissionPercent = parseFloat(document.getElementById('commissionPercent').value) || 10;
    const totalCommission = (subTotal * commissionPercent) / 100;
    
    // Get additional charges
    const vehicleCharges = parseFloat(document.getElementById('vehicleCharges').value) || 0;
    const otherCharges = parseFloat(document.getElementById('otherCharges').value) || 0;
    const bardan = parseFloat(document.getElementById('bardan').value) || 0;
    
    // Apply rounding logic (same as backend)
    // 1. Expenses: Remove all decimals (round down)
    const roundedCommission = Math.floor(totalCommission);
    const roundedLabor = Math.floor(totalLabor);
    const roundedVehicleCharges = Math.floor(vehicleCharges);
    const roundedOtherCharges = Math.floor(otherCharges);
    const roundedBardan = Math.floor(bardan);
    
    // 2. Goods Sale Proceeds: If decimal >= 0.5, round up by 1 rupee; if < 0.5, keep current amount and remove decimal
    let goodsSaleProceeds = subTotal;
    const decimalPart = goodsSaleProceeds - Math.floor(goodsSaleProceeds);
    if (decimalPart >= 0.5) {
        goodsSaleProceeds = Math.ceil(goodsSaleProceeds);
    } else {
        goodsSaleProceeds = Math.floor(goodsSaleProceeds);
    }
    
    // 3. Net Amount: Remove all decimals (round down)
    const netAmount = Math.floor(goodsSaleProceeds - roundedCommission - roundedLabor - roundedVehicleCharges - roundedOtherCharges - roundedBardan);
    
    // Update the displayed totals with rounded values
    document.getElementById('subTotal').textContent = goodsSaleProceeds.toFixed(0);
    document.getElementById('totalCommission').textContent = roundedCommission.toFixed(0);
    document.getElementById('totalLabor').textContent = roundedLabor.toFixed(0);
    document.getElementById('grandTotal').textContent = goodsSaleProceeds.toFixed(0);
    document.getElementById('commissionTotal').textContent = roundedCommission.toFixed(0);
    document.getElementById('netAmount').textContent = netAmount.toFixed(0);
}

// Update vendor type when vendor selection changes
document.getElementById('vendor_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const vendorType = selectedOption ? selectedOption.getAttribute('data-vendor-type') : '';
    const vendorId = this.value;
    
    // Update commission and labor rates based on vendor type
    if (vendorType === 'Local') {
        document.getElementById('commissionPercent').value = '10';
        document.getElementById('laborRate').value = '1';
    } else if (vendorType === 'Outsider') {
        document.getElementById('commissionPercent').value = '6';
        document.getElementById('laborRate').value = '2';
    }
    
    // Load all items for manual watak creation
    loadAllItems();
    
    calculateAllTotals();
});

// Update totals when additional charges change
['vehicleCharges', 'bardan', 'otherCharges', 'commissionPercent', 'laborRate'].forEach(id => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('input', calculateAllTotals);
        element.addEventListener('change', calculateAllTotals);
    }
});

// Load all items for manual watak creation
function loadAllItems() {
    // Show loading state
    const itemSelects = document.querySelectorAll('.item-select');
    itemSelects.forEach(select => {
        select.innerHTML = '<option value="">Loading items...</option>';
    });

    console.log('Loading items for watak creation...');

    fetch('../../api/inventory/get_items_for_watak.php')
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Items data received:', data);
            
            const itemSelects = document.querySelectorAll('.item-select');
            
            itemSelects.forEach(select => {
                // Clear existing options except the first one
                select.innerHTML = '<option value="">Select Item</option>';
                
                if (data.success && data.items && data.items.length > 0) {
                    console.log('Loading ' + data.items.length + ' items into dropdown');
                    data.items.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.name;
                        option.textContent = item.name;
                        option.setAttribute('data-rate', item.rate || 0);
                        option.setAttribute('data-weight', item.weight || 0);
                        select.appendChild(option);
                    });
                } else {
                    // Show error message if no items or failed
                    select.innerHTML = '<option value="">No items available</option>';
                    if (data.error) {
                        console.error('Server error:', data.error);
                        console.error('Debug info:', data.debug_info);
                    }
                    if (data.debug_info) {
                        console.log('Debug info:', data.debug_info);
                    }
                }
            });
        })
        .catch(error => {
            console.error('Error loading items:', error);
            const itemSelects = document.querySelectorAll('.item-select');
            itemSelects.forEach(select => {
                select.innerHTML = '<option value="">Error loading items</option>';
            });
        });
}

// Handle item selection to auto-fill rate and weight
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('item-select')) {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const row = e.target.closest('tr');
        
        if (selectedOption && selectedOption.value) {
            const rate = selectedOption.getAttribute('data-rate') || 0;
            const weight = selectedOption.getAttribute('data-weight') || 0;
            
            row.querySelector('.rate').value = rate;
            row.querySelector('.weight').value = weight;
            
            // Trigger calculation
            calculateRowAmount(row);
            calculateAllTotals();
        }
    }
});

// Global event listener for quantity, weight, and rate changes
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('quantity') || e.target.classList.contains('weight') || e.target.classList.contains('rate')) {
        const row = e.target.closest('tr');
        if (row) {
            calculateRowAmount(row);
            calculateAllTotals();
        }
    }
});

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('quantity') || e.target.classList.contains('weight') || e.target.classList.contains('rate')) {
        const row = e.target.closest('tr');
        if (row) {
            calculateRowAmount(row);
            calculateAllTotals();
        }
    }
});

// Load vendor ledger
document.getElementById('ledgerModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    
    this.querySelector('.modal-title').textContent = `Ledger - ${vendorName}`;
    
    // Save vendor ID for PDF export
    this.setAttribute('data-vendor-id', vendorId);
    
    // Load ledger via AJAX
    fetch('../../api/vendors/get_ledger.php?vendor_id=' + vendorId)
        .then(response => response.json())
        .then(data => {
            let html = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Debit</th>
                                <th>Credit</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            if (data.success && data.ledger.length > 0) {
                data.ledger.forEach(entry => {
                    html += `
                        <tr>
                            <td>${entry.date}</td>
                            <td>${entry.description}</td>
                            <td class="text-end">${entry.debit ? '₹' + entry.debit : ''}</td>
                            <td class="text-end">${entry.credit ? '₹' + entry.credit : ''}</td>
                            <td class="text-end">₹${entry.balance}</td>
                            <td class="${entry.balance_type === 'Payable to Vendor' ? 'text-danger' : 'text-success'}">${entry.balance_type}</td>
                        </tr>
                    `;
                });
            } else {
                html += `<tr><td colspan="6" class="text-center">No ledger entries found</td></tr>`;
            }
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            document.getElementById('ledger_content').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading ledger:', error);
            document.getElementById('ledger_content').innerHTML = `
                <div class="alert alert-danger">
                    Failed to load ledger. Please try again.
                </div>
            `;
        });
});

// Handle Vendor Ledger PDF Export
document.getElementById('export_vendor_ledger_pdf').addEventListener('click', function(e) {
    e.preventDefault();
    const vendorId = document.getElementById('ledgerModal').getAttribute('data-vendor-id');
    if (vendorId) {
        const url = `download_vendor_ledger.php?vendor_id=${vendorId}`;
        window.open(url, '_blank');
    } else {
        alert('Vendor information is missing. Please try again.');
    }
});

// Load watak list
document.getElementById('viewWatakModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    
    this.querySelector('.modal-title').textContent = `Watak List - ${vendorName}`;
    
    // Load watak list via AJAX
    fetch('../../api/watak/get_vendor_watak.php?vendor_id=' + vendorId)
        .then(response => response.json())
        .then(data => {
            let html = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Watak #</th>
                                <th>Vehicle No</th>
                                <th>Items</th>
                                <th>Total Amount</th>
                                <th>Commission</th>
                                <th>Net Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            if (data.success && data.watak.length > 0) {
                data.watak.forEach(entry => {
                    html += `
                        <tr>
                            <td>${entry.date}</td>
                            <td>${entry.watak_number}</td>
                            <td>${entry.vehicle_no || '-'}</td>
                            <td>${entry.items}</td>
                            <td class="text-end">₹${entry.total_amount}</td>
                            <td class="text-end">₹${entry.commission}</td>
                            <td class="text-end">₹${entry.net_amount}</td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="../watak/view.php?id=${entry.raw_id}" target="_blank" class="btn btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../watak/edit.php?id=${entry.raw_id}" class="btn btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger" title="Delete" onclick="deleteWatak(${entry.raw_id})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <a href="../watak/view.php?id=${entry.raw_id}&print=1" target="_blank" class="btn btn-outline-info" title="Print">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    `;
                });
            } else {
                html += `<tr><td colspan="8" class="text-center">No watak entries found</td></tr>`;
            }
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            document.getElementById('watak_list_content').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading watak list:', error);
            document.getElementById('watak_list_content').innerHTML = `
                <div class="alert alert-danger">
                    Failed to load watak list. Please try again.
                </div>
            `;
        });
});

// Watak delete function
function deleteWatak(id) {
    if (confirm('Are you sure you want to delete this watak? This action cannot be undone.')) {
        window.location.href = '../../handlers/watak/delete.php?id=' + id;
    }
}
</script> 