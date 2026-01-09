<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Handle invoice deletion
if (isset($_POST['delete_invoice'])) {
    $invoice_id = intval($_POST['invoice_id']);
    
    try {
        $conn->begin_transaction();
        
        // Get invoice details before deletion
        $sql = "SELECT ci.*, c.name as customer_name FROM customer_invoices ci 
                JOIN customers c ON ci.customer_id = c.id 
                WHERE ci.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        
        if (!$invoice) {
            throw new Exception("Invoice not found");
        }
        
        // Check if invoice is from today - only allow deletion of today's invoices
        $today = date('Y-m-d');
        $invoice_date = date('Y-m-d', strtotime($invoice['date']));
        if ($invoice_date !== $today) {
            throw new Exception("Cannot delete invoices from previous days. Only today's invoices can be deleted.");
        }
        
        // Get all invoice items to restore inventory
        $sql = "SELECT cii.*, i.name as item_name 
                FROM customer_invoice_items cii 
                JOIN items i ON cii.item_id = i.id 
                WHERE cii.invoice_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $invoice_items = $stmt->get_result();
        
        $total_invoice_amount = 0;
        $inventory_restored_items = [];
        $inventory_skipped_items = [];
        
        // Check and restore inventory for each item
        while ($item = $invoice_items->fetch_assoc()) {
            $total_invoice_amount += $item['amount'];
            
            // Use the inventory_item_id directly from the invoice item for accurate restoration
            if (!empty($item['inventory_item_id'])) {
                // Check if the inventory item still exists
                $check_sql = "SELECT ii.id, ii.remaining_stock, i.name as item_name 
                             FROM inventory_items ii 
                             JOIN items i ON ii.item_id = i.id 
                             WHERE ii.id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('i', $item['inventory_item_id']);
                $check_stmt->execute();
                $inventory_item = $check_stmt->get_result()->fetch_assoc();
                
                if ($inventory_item) {
                    // Inventory item exists, restore the quantity
                    $sql = "UPDATE inventory_items SET remaining_stock = remaining_stock + ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('di', $item['quantity'], $item['inventory_item_id']);
                    $stmt->execute();
                    $inventory_restored_items[] = $item['item_name'];
                } else {
                    // Inventory item doesn't exist, skip restoration
                    $inventory_skipped_items[] = $item['item_name'] . " (inventory item deleted)";
                }
            } else {
                // Fallback: Find the inventory item for this vendor and item
            $sql = "SELECT ii.* FROM inventory_items ii 
                    JOIN inventory inv ON ii.inventory_id = inv.id 
                    WHERE inv.vendor_id = ? AND ii.item_id = ? 
                    ORDER BY inv.date_received DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $item['vendor_id'], $item['item_id']);
            $stmt->execute();
            $inventory_item = $stmt->get_result()->fetch_assoc();
            
            if ($inventory_item) {
                // Restore the quantity to inventory
                $sql = "UPDATE inventory_items SET remaining_stock = remaining_stock + ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('di', $item['quantity'], $inventory_item['id']);
                $stmt->execute();
                    $inventory_restored_items[] = $item['item_name'];
                } else {
                    // No inventory item found, skip restoration
                    $inventory_skipped_items[] = $item['item_name'] . " (no inventory found)";
                }
            }
        }
        
        // Update customer balance (remove the invoice amount)
        $sql = "UPDATE customers SET balance = balance - ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('di', $total_invoice_amount, $invoice['customer_id']);
        $stmt->execute();
        
        // Delete invoice items
        $sql = "DELETE FROM customer_invoice_items WHERE invoice_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        
        // Delete the invoice
        $sql = "DELETE FROM customer_invoices WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        
        $conn->commit();
        
        // Build success message based on what was restored/skipped
        $success_message = "Invoice deleted successfully. Customer balance updated.";
        
        if (!empty($inventory_restored_items)) {
            $success_message .= " Inventory restored for: " . implode(", ", $inventory_restored_items) . ".";
        }
        
        if (!empty($inventory_skipped_items)) {
            $success_message .= " Inventory not restored for: " . implode(", ", $inventory_skipped_items) . ".";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error deleting invoice: " . $e->getMessage();
    }
}

// Function to check if invoice can be edited (now always returns true - no restrictions)
function canEditInvoice($conn, $invoice_id) {
    // Invoices can now always be edited regardless of inventory availability
    // Inventory will be updated conditionally during the edit process
    return true;
}

// Set default filter values - automatically show today's invoices
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$customer_search = isset($_GET['customer_search']) ? $_GET['customer_search'] : '';
$is_filtered = true; // Always show filtered results, default to today

// Modified query to group invoices by customer and date with FIFO sorting (oldest first)
$sql = "SELECT 
        DATE(ci.date) as invoice_date,
        COALESCE(ci.display_date, DATE(ci.date)) as display_group_date,
        c.id as customer_id,
        c.name as customer_name,
        c.contact as customer_contact,
        GROUP_CONCAT(DISTINCT ci.invoice_number ORDER BY CAST(ci.invoice_number AS UNSIGNED)) as invoice_numbers,
        GROUP_CONCAT(DISTINCT ci.id ORDER BY ci.id) as invoice_ids,
        COUNT(DISTINCT ci.id) as invoice_count,
        SUM(cii.quantity) as total_quantity,
        COUNT(cii.id) as items_count,
        SUM(cii.amount) as total_amount
        FROM customer_invoices ci
        JOIN customers c ON ci.customer_id = c.id
        LEFT JOIN customer_invoice_items cii ON ci.id = cii.invoice_id
        WHERE (
            ? = CURRENT_DATE AND DATE(ci.date) = CURRENT_DATE
            OR
            ? != CURRENT_DATE AND DATE(COALESCE(ci.display_date, ci.date)) = ?
        )";

// Add customer search if provided
if (!empty($customer_search)) {
    $sql .= " AND (c.name LIKE ? OR c.contact LIKE ?)";
}

// Group by customer and date, FIFO sorting - oldest invoices first
$sql .= " GROUP BY DATE(ci.date), display_group_date, c.id ORDER BY MIN(CAST(ci.invoice_number AS UNSIGNED)) ASC";

// Prepare and execute the query with filters
$stmt = $conn->prepare($sql);

if (!empty($customer_search)) {
    $search_param = "%$customer_search%";
    $stmt->bind_param("sssss", $filter_date, $filter_date, $filter_date, $search_param, $search_param);
} else {
    $stmt->bind_param("sss", $filter_date, $filter_date, $filter_date);
}

$stmt->execute();
$result = $stmt->get_result();

// Calculate summary statistics
$invoice_count = $result->num_rows;
$total_invoice_amount = 0;
$total_individual_invoices = 0;

// We need to clone the result to avoid consuming it
$temp_result = $result;
while ($row = $temp_result->fetch_assoc()) {
    $total_invoice_amount += $row['total_amount'];
    $total_individual_invoices += $row['invoice_count'];
}

// Reset the result pointer to the beginning
mysqli_data_seek($result, 0);
?>

<!-- Main content -->
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center">
            <h2 class="mb-0 me-3">Customer Invoices</h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dateModal" title="Select Date">
                    <i class="fas fa-calendar-alt"></i>
                </button>
                <a href="../../ai/invoice_ai.php" class="btn btn-outline-success btn-sm" title="Create from Register Photo">
                    <i class="fas fa-wand-magic-sparkles"></i>
                </a>
            </div>
        </div>
        <div>
            <a href="../../print/customers/all_invoices.php?date=<?php echo $filter_date; ?>" 
               class="btn btn-outline-info" 
               title="Print All Today's Invoices" 
               target="_blank">
                <i class="fas fa-print"></i> Print All Invoices
            </a>
        </div>
    </div>

    <!-- Live Search Bar -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0">
                    <i class="fas fa-search text-muted"></i>
                </span>
                <input type="text" class="form-control border-start-0" id="liveSearch" 
                       placeholder="Search customers by name or contact..." 
                       value="<?php echo htmlspecialchars($customer_search); ?>">
                <button class="btn btn-outline-secondary" type="button" id="clearSearch" title="Clear Search">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
                
    <!-- Current Date Display -->
    <div class="alert alert-light border mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-calendar-day text-primary me-2"></i>
                <strong>Showing invoices for:</strong> <?php echo date('d/m/Y', strtotime($filter_date)); ?>
                <?php if (!empty($customer_search)): ?>
                    <span class="ms-2 text-muted">
                        <i class="fas fa-search me-1"></i>
                        Filtered by: "<?php echo htmlspecialchars($customer_search); ?>"
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($is_filtered && $result->num_rows > 0): ?>
                    <div class="table-responsive">
            <table class="table table-hover table-borderless">
                            <thead>
                                <tr class="table-light">
                        <th class="border-0">Invoice #</th>
                                    <th class="border-0">Customer</th>
                        <th class="border-0">Amount</th>
                                    <th class="border-0">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr class="border-bottom">
                        <td class="py-3">
                            <div class="fw-bold text-primary"><?php echo htmlspecialchars($row['invoice_numbers']); ?></div>
                            <small class="text-muted">Date shown: <?php echo date('d/m/Y', strtotime($row['display_group_date'])); ?></small>
                        </td>
                                        <td class="py-3">
                            <div class="fw-bold"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                            <?php if (!empty($row['customer_contact'])): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($row['customer_contact']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="py-3">
                            <span class="badge bg-success fs-6 px-3 py-2">
                                ₹<?php echo number_format($row['total_amount'], 2); ?>
                            </span>
                        </td>
                        <td class="py-3">
                            <div class="btn-group btn-group-sm action-buttons">
                                                <?php 
                                                // Get the first invoice ID and decide single vs combined actions
                                                $invoice_ids = explode(',', $row['invoice_ids']);
                                                $first_invoice_id = $invoice_ids[0];
                                                $is_single_row = ((int)$row['invoice_count'] === 1);
                                                ?>
                                                <?php if ($is_single_row): ?>
                                                    <a href="../customers/view_invoice.php?id=<?php echo $first_invoice_id; ?>" 
                                                       class="btn btn-outline-primary" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="../../handlers/invoices/download_combined.php?id=<?php echo $first_invoice_id; ?>" 
                                                       class="btn btn-outline-success" title="Download">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <a href="../../print/vendors/combined_invoices.php?id=<?php echo $first_invoice_id; ?>" 
                                                       class="btn btn-outline-info" title="Print" target="_blank">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="../vendors/combined_invoices.php?date=<?php echo $row['invoice_date']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                                                       class="btn btn-outline-primary" title="View Combined">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="../../handlers/invoices/download_combined.php?date=<?php echo $row['invoice_date']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                                                       class="btn btn-outline-success" title="Download Combined">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <a href="../../print/vendors/combined_invoices.php?date=<?php echo $row['invoice_date']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                                                       class="btn btn-outline-info" title="Print Combined" target="_blank">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php 
                                // Show edit button for single invoices (date restriction removed)
                                                $invoice_date = $row['invoice_date'];
                                                $today = date('Y-m-d');
                                if ($row['invoice_count'] == 1): 
                                    // Check if inventory is available for editing (date restriction removed)
                                    if (canEditInvoice($conn, $first_invoice_id)):
                                                ?>
                                                <a href="edit.php?id=<?php echo $first_invoice_id; ?>" 
                                   class="btn btn-outline-warning" title="Edit Invoice"
                                   target="_blank">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php else: ?>
                                                <button type="button" 
                                        class="btn btn-outline-warning" 
                                        disabled
                                        title="Cannot edit - inventory item deleted">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php 
                                    endif;
                                else: 
                                ?>
                                <button type="button" 
                                        class="btn btn-outline-warning" 
                                                        disabled
                                        title="Cannot edit combined invoices">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php 
                                // Show delete button only for today's individual invoices
                                if ($row['invoice_count'] == 1 && $invoice_date == $today): 
                                                ?>
                                                <button type="button" 
                                        class="btn btn-outline-danger delete-invoice" 
                                                        data-invoice-id="<?php echo $first_invoice_id; ?>"
                                                        data-invoice-number="<?php echo htmlspecialchars(explode(',', $row['invoice_numbers'])[0]); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($row['customer_name']); ?>"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php else: ?>
                                                <button type="button" 
                                        class="btn btn-outline-danger" 
                                                        disabled
                                        title="<?php echo $row['invoice_count'] > 1 ? 'Cannot delete combined invoices' : 'Cannot delete invoices from previous days'; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
        
    
    <?php elseif ($is_filtered): ?>
        <div class="text-center py-4">
            <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No invoices found</h5>
            <p class="text-muted">No invoices found for <?php echo date('d/m/Y', strtotime($filter_date)); ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- Date Selection Modal -->
<div class="modal fade" id="dateModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-alt me-2"></i>Select Date
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="get">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="modal_filter_date" class="form-label">Choose Date:</label>
                        <input type="date" class="form-control" id="modal_filter_date" name="filter_date" value="<?php echo $filter_date; ?>" required>
                    </div>
                    <?php if (!empty($customer_search)): ?>
                        <input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customer_search); ?>">
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-1"></i>Apply
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete invoice <span id="deleteInvoiceNumber"></span>?</p>
                <p><strong>Customer:</strong> <span id="deleteCustomerName"></span></p>
                <p><strong>Note:</strong> This will restore items to inventory and update customer balance.</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action cannot be undone!
                </div>
            </div>
            <div class="modal-footer">
                <form method="post">
                    <input type="hidden" name="invoice_id" id="deleteInvoiceId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_invoice" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Wait for DOM to be loaded
document.addEventListener('DOMContentLoaded', function() {
    // Handle delete button click
    document.querySelectorAll('.delete-invoice').forEach(function(button) {
        button.addEventListener('click', function() {
            console.log('Delete button clicked');
            var invoiceId = this.getAttribute('data-invoice-id');
            var invoiceNumber = this.getAttribute('data-invoice-number');
            var customerName = this.getAttribute('data-customer-name');
            
            console.log('Invoice ID:', invoiceId);
            console.log('Invoice Number:', invoiceNumber);
            console.log('Customer Name:', customerName);
            
            document.getElementById('deleteInvoiceId').value = invoiceId;
            document.getElementById('deleteInvoiceNumber').textContent = invoiceNumber;
            document.getElementById('deleteCustomerName').textContent = customerName;
            
            // Use Bootstrap 5 modal
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        });
    });

    // Live Search Functionality
    const searchInput = document.getElementById('liveSearch');
    const clearButton = document.getElementById('clearSearch');
    const tableRows = document.querySelectorAll('tbody tr');
    let searchTimeout;

    // Live search function
    function performLiveSearch() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        
        tableRows.forEach(row => {
            const customerName = row.querySelector('td:nth-child(2) .fw-bold')?.textContent.toLowerCase() || '';
            const customerContact = row.querySelector('td:nth-child(2) small')?.textContent.toLowerCase() || '';
            const searchData = customerName + ' ' + customerContact;
            
            const isVisible = searchData.includes(searchTerm);
            
            if (searchTerm === '' || isVisible) {
                row.style.display = '';
                row.classList.remove('d-none');
            } else {
                row.style.display = 'none';
                row.classList.add('d-none');
            }
        });

        // Update visible count
        updateVisibleCount();
    }

    // Update visible count
    function updateVisibleCount() {
        const visibleRows = document.querySelectorAll('tbody tr:not(.d-none)');
        const totalRows = tableRows.length;
        const visibleCount = visibleRows.length;
        
        // Update the status display
        const statusElement = document.querySelector('.alert-light');
        if (statusElement) {
            const dateInfo = statusElement.querySelector('div:first-child');
            if (dateInfo) {
                const searchInfo = dateInfo.querySelector('.ms-2.text-muted');
                if (searchInfo) {
                    if (searchInput.value.trim()) {
                        searchInfo.innerHTML = `<i class="fas fa-search me-1"></i>Showing ${visibleCount} of ${totalRows} invoices`;
                    } else {
                        searchInfo.innerHTML = '';
                    }
                }
            }
        }
    }

    // Search input event listener with debouncing
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performLiveSearch, 300);
    });

    // Clear search button
    clearButton.addEventListener('click', function() {
        searchInput.value = '';
        performLiveSearch();
        searchInput.focus();
    });

    // Escape key to clear search
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            searchInput.value = '';
            performLiveSearch();
        }
    });

    // Initialize search
    performLiveSearch();
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 