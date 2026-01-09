<!-- Add Inventory Modal -->
<div class="modal fade" id="addInventoryModal" tabindex="-1" aria-labelledby="addInventoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addInventoryModalLabel">Add Inventory</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="../../handlers/inventory/process.php" id="inventoryForm">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="vendor_category_filter" class="form-label">Vendor Category <span class="text-danger">*</span></label>
                            <select class="form-select" id="vendor_category_filter" required>
                                <option value="">Select Category</option>
                                <option value="Commission Based" selected>Commission Based</option>
                                <option value="Purchase Based">Purchase Based</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="vendor_id" class="form-label">Vendor <span class="text-danger">*</span></label>
                            <div class="vendor-search-container">
                                <input type="text" id="vendor_search" class="form-control" placeholder="Select category first..." autocomplete="off" readonly>
                                <input type="hidden" id="vendor_id" name="vendor_id" required>
                                <div id="vendor_dropdown" class="vendor-dropdown">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="date_received" class="form-label">Date Received <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="date_received" name="date_received" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="vehicle_no" class="form-label">Vehicle No.</label>
                            <input type="text" class="form-control" id="vehicle_no" name="vehicle_no">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="vehicle_charges" class="form-label">Vehicle Charges</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" class="form-control" id="vehicle_charges" name="vehicle_charges" step="0.01" value="0.00">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="bardan" class="form-label">Bardan</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" class="form-control" id="bardan" name="bardan" step="0.01">
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered" id="inventory_items_table">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Quantity</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr id="inventory_empty_row">
                                    <td colspan="3" class="text-center">Click "Add Item" to add items to inventory</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <button type="button" id="add_inventory_item" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_inventory" class="btn btn-primary">Add Inventory</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="historyModalLabel">Purchase History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="history_content">
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

<script>
// TODO: Move vendor dropdown CSS to external CSS file

// Vendor search functionality for inventory modal
const vendorSearchInput = document.getElementById('vendor_search');
const vendorIdInput = document.getElementById('vendor_id');
const vendorDropdown = document.getElementById('vendor_dropdown');
const vendorCategoryFilter = document.getElementById('vendor_category_filter');

// Add category filter change handler
vendorCategoryFilter.addEventListener('change', function() {
    const selectedCategory = this.value;
    console.log('Category filter changed to:', selectedCategory);
    
    // Clear vendor search and selection
    vendorSearchInput.value = '';
    vendorIdInput.value = '';
    vendorDropdown.style.display = 'none';
    
    if (selectedCategory) {
        // Enable vendor search
        vendorSearchInput.readOnly = false;
        vendorSearchInput.placeholder = 'Search vendor...';
        // Load vendors for selected category
        loadVendorsByCategory(selectedCategory);
    } else {
        // Disable vendor search
        vendorSearchInput.readOnly = true;
        vendorSearchInput.placeholder = 'Select category first...';
    }
});

// Add vendor search functionality
vendorSearchInput.addEventListener('input', function() {
    const vendorName = this.value.trim();
    console.log('Searching for vendor in inventory:', vendorName);
    
    if (vendorName.length >= 2) {
        // Clear previous results
        vendorDropdown.innerHTML = '';
        // Show loading state
        vendorDropdown.innerHTML = '<div class="p-2 text-muted">Loading vendors...</div>';
        vendorDropdown.style.display = 'block';
        
        console.log('Making API call to:', 'get_vendors_with_inventory.php?search=' + encodeURIComponent(vendorName));
        
        const selectedCategory = vendorCategoryFilter.value;
        if (!selectedCategory) {
            vendorDropdown.innerHTML = '<div class="p-2 text-warning">Please select a vendor category first</div>';
            return;
        }
        
        fetch('../../api/vendors/get_with_inventory.php?search=' + encodeURIComponent(vendorName) + '&category=' + encodeURIComponent(selectedCategory))
            .then(response => {
                console.log('API response status:', response.status);
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('API response data:', data);
                vendorDropdown.innerHTML = '';
                
                if (data.vendors && data.vendors.length > 0) {
                    data.vendors.forEach(vendor => {
                        const option = document.createElement('div');
                        option.className = 'vendor-option p-2 border-bottom';
                        option.style.cursor = 'pointer';
                        option.innerHTML = `<div class="fw-bold">${vendor.name}</div>`;
                        option.dataset.vendorId = vendor.id;
                        option.dataset.vendorName = vendor.name;
                        vendorDropdown.appendChild(option);
                    });
                } else {
                    vendorDropdown.innerHTML = '<div class="p-2 text-muted">No vendors found</div>';
                }
            })
            .catch(error => {
                console.error('Error searching vendors:', error);
                vendorDropdown.innerHTML = '<div class="p-2 text-danger">Error searching vendors: ' + error.message + '</div>';
            });
    } else if (vendorName.length === 0) {
        // If search field is empty, show all vendors
        loadAllVendors();
    } else {
        // If less than 2 characters, keep showing all vendors
        vendorDropdown.style.display = 'block';
    }
});

vendorSearchInput.addEventListener('blur', function() {
    setTimeout(() => {
        vendorDropdown.style.display = 'none';
    }, 200);
});

// Show dropdown when search field is focused
vendorSearchInput.addEventListener('focus', function() {
    if (this.value.trim().length === 0) {
        loadAllVendors();
    }
});

vendorDropdown.addEventListener('click', function(event) {
    const selectedOption = event.target.closest('.vendor-option');
    if (selectedOption) {
        vendorSearchInput.value = selectedOption.dataset.vendorName;
        vendorIdInput.value = selectedOption.dataset.vendorId;
        vendorDropdown.style.display = 'none';
        console.log('Selected vendor for inventory:', selectedOption.dataset.vendorName, 'ID:', selectedOption.dataset.vendorId);
    }
});

// Load vendors when inventory modal opens
document.getElementById('addInventoryModal').addEventListener('show.bs.modal', function() {
    // Reset form
    document.getElementById('inventoryForm').reset();
    document.getElementById('date_received').value = new Date().toISOString().split('T')[0];
    
    // Set default category to Commission Based and load vendors
    vendorCategoryFilter.value = 'Commission Based';
    vendorSearchInput.value = '';
    vendorIdInput.value = '';
    vendorDropdown.style.display = 'none';
    vendorSearchInput.readOnly = false;
    vendorSearchInput.placeholder = 'Search vendor...';
    
    // Load Commission Based vendors by default
    loadVendorsByCategory('Commission Based');
    
    // Clear items
    const rows = document.querySelectorAll('#inventory_items_table tbody tr:not(#inventory_empty_row)');
    rows.forEach(row => row.remove());
    document.getElementById('inventory_empty_row').style.display = '';
});

// Function to load vendors by category
function loadVendorsByCategory(category) {
    console.log('Loading vendors for category:', category);
    vendorDropdown.innerHTML = '<div class="p-2 text-muted">Loading vendors...</div>';
    vendorDropdown.style.display = 'block';
    
    fetch('../../api/vendors/get_with_inventory.php?category=' + encodeURIComponent(category))
        .then(response => {
            console.log('API response status:', response.status);
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('API response data:', data);
            vendorDropdown.innerHTML = '';
            if (data.vendors && data.vendors.length > 0) {
                console.log('Found', data.vendors.length, 'vendors');
                data.vendors.forEach(vendor => {
                    const option = document.createElement('div');
                    option.className = 'vendor-option p-2 border-bottom';
                    option.style.cursor = 'pointer';
                    option.innerHTML = `<div class="fw-bold">${vendor.name}</div><small class="text-muted">${vendor.type} - ${vendor.vendor_category}</small>`;
                    option.dataset.vendorId = vendor.id;
                    option.dataset.vendorName = vendor.name;
                    vendorDropdown.appendChild(option);
                });
            } else {
                console.log('No vendors found in response');
                vendorDropdown.innerHTML = '<div class="p-2 text-muted">No vendors found for this category</div>';
            }
        })
        .catch(error => {
            console.error('Error loading vendors:', error);
            vendorDropdown.innerHTML = '<div class="p-2 text-danger">Error loading vendors: ' + error.message + '</div>';
        });
}

// Add Inventory Item Row
document.getElementById('add_inventory_item').addEventListener('click', function() {
    const tbody = document.querySelector('#inventory_items_table tbody');
    const emptyRow = document.getElementById('inventory_empty_row');
    if (emptyRow) emptyRow.style.display = 'none';
    
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <select class="form-select item-select" name="item_id[]" required>
                <option value="">Select Item</option>
                <option value="loading" disabled>Loading items...</option>
            </select>
        </td>
        <td>
            <input type="number" class="form-control" name="quantity[]" step="0.01" required>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-danger remove-row">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    
    // Load items into the select dropdown
    const itemSelect = newRow.querySelector('.item-select');
    
    // Show loading state
    itemSelect.disabled = true;
    
    // Use XMLHttpRequest instead of fetch for better compatibility
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '../../api/inventory/get_items_simple.php', true);
    
    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 400) {
            // Success
            try {
                console.log('Raw response from get_items_simple.php:', xhr.responseText);
                const data = JSON.parse(xhr.responseText);
                console.log('Parsed data:', data);
                
                // Clear the loading option
                itemSelect.innerHTML = '<option value="">Select Item</option>';
                itemSelect.disabled = false;
                
                if (data.success && data.items && data.items.length > 0) {
                    console.log('Loading', data.items.length, 'items into dropdown');
                    data.items.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.id;
                        option.textContent = item.name;
                        itemSelect.appendChild(option);
                    });
                } else {
                    console.error('No items returned from server:', data);
                    itemSelect.innerHTML = '<option value="">No items available</option>';
                    alert('No items available. Please add items first in the Items section.');
                }
            } catch (e) {
                console.error('Error parsing JSON:', e);
                console.error('Response text:', xhr.responseText);
                itemSelect.innerHTML = '<option value="">Error loading items</option>';
                itemSelect.disabled = false;
                alert('Error loading items. Please check the console for details.');
            }
        } else {
            // Error
            console.error('Server returned error:', xhr.status, xhr.statusText);
            console.error('Response:', xhr.responseText);
            itemSelect.innerHTML = '<option value="">Error loading items</option>';
            itemSelect.disabled = false;
            alert('Server error (' + xhr.status + '). Please contact support.');
        }
    };
    
    xhr.onerror = function() {
        console.error('Request failed - network error');
        itemSelect.innerHTML = '<option value="">Error loading items</option>';
        itemSelect.disabled = false;
        alert('Network error. Please check your connection and try again.');
    };
    
    xhr.send();
    
    // Add remove button functionality
    newRow.querySelector('.remove-row').addEventListener('click', function() {
        newRow.remove();
        if (tbody.querySelectorAll('tr').length === 1) {
            document.getElementById('inventory_empty_row').style.display = '';
        }
    });
});

// Load purchase history
document.getElementById('historyModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const itemId = button.getAttribute('data-item-id');
    const itemName = button.getAttribute('data-item-name');
    
    this.querySelector('.modal-title').textContent = `Purchase History - ${itemName}`;
    
    fetch('../../api/inventory/get_item_history.php?item_id=' + itemId)
        .then(response => response.json())
        .then(data => {
            let html = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Vendor</th>
                                <th>Vehicle No</th>
                                <th>Quantity</th>
                                <th>Remaining</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            if (data.success && data.history.length > 0) {
                data.history.forEach(entry => {
                    html += `
                        <tr>
                            <td>${entry.date}</td>
                            <td>${entry.vendor_name}</td>
                            <td>${entry.vehicle_no || '-'}</td>
                            <td class="text-end">${entry.quantity_received}</td>
                            <td class="text-end">${entry.remaining_stock}</td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" onclick="viewInventory(${entry.inventory_id})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-info" onclick="printInventory(${entry.inventory_id})">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });
            } else {
                html += `<tr><td colspan="6" class="text-center">No purchase history found</td></tr>`;
            }
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            document.getElementById('history_content').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading history:', error);
            document.getElementById('history_content').innerHTML = `
                <div class="alert alert-danger">
                    Failed to load purchase history. Please try again.
                </div>
            `;
        });
});

function viewInventory(id) {
    window.open('view_inventory.php?id=' + id, '_blank');
}

function printInventory(id) {
    window.open('print_inventory.php?id=' + id, '_blank');
}
</script> 