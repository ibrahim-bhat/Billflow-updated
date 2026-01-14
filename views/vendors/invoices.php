<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers/feature_helper.php';

// Check if purchase feature is enabled
require_feature('purchase', '../../views/dashboard/');

require_once __DIR__ . '/../layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Handle invoice deletion
if (isset($_POST['delete_invoice'])) {
    $invoice_id = intval($_POST['invoice_id']);
    
    try {
        $conn->begin_transaction();
        
        // Get invoice details before deletion
        $sql = "SELECT vi.*, v.name as vendor_name FROM vendor_invoices vi 
                JOIN vendors v ON vi.vendor_id = v.id 
                WHERE vi.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        
        if (!$invoice) {
            throw new Exception("Invoice not found");
        }
        
        // Check if invoice is from today - only allow deletion of today's invoices
        $today = date('Y-m-d');
        $invoice_date = date('Y-m-d', strtotime($invoice['invoice_date']));
        if ($invoice_date !== $today) {
            throw new Exception("Cannot delete invoices from previous days. Only today's invoices can be deleted.");
        }
        
        // Get all invoice items to update inventory or other records if needed
        $sql = "SELECT vii.*, i.name as item_name 
                FROM vendor_invoice_items vii 
                JOIN items i ON vii.item_id = i.id 
                WHERE vii.invoice_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $invoice_items = $stmt->get_result();
        
        $total_invoice_amount = 0;
        
        // Process each invoice item
        while ($item = $invoice_items->fetch_assoc()) {
            $total_invoice_amount += $item['amount'];
            // Perform any necessary inventory adjustments here if needed
        }
        
        // Update vendor balance (remove the invoice amount)
        $sql = "UPDATE vendors SET balance = balance - ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('di', $total_invoice_amount, $invoice['vendor_id']);
        $stmt->execute();
        
        // Delete invoice items
        $sql = "DELETE FROM vendor_invoice_items WHERE invoice_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        
        // Delete the invoice
        $sql = "DELETE FROM vendor_invoices WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        
        $conn->commit();
        $success_message = "Purchase invoice deleted successfully. Vendor balance updated.";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error deleting invoice: " . $e->getMessage();
    }
}

// Set default filter values - automatically show today's invoices
// We're filtering by created_at date, not invoice_date
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$vendor_search = isset($_GET['vendor_search']) ? $_GET['vendor_search'] : '';
$is_filtered = true; // Always show filtered results, default to today

// Modified query to group invoices by vendor and date with FIFO sorting (oldest first)
// Now filtering by created_at date instead of invoice_date
// Using display_group_date to prevent combining invoices with different dates
$sql = "SELECT 
        DATE(vi.invoice_date) as invoice_date,
        DATE(vi.created_at) as created_date,
        vi.invoice_date as display_group_date, -- Use invoice_date as display_group_date to prevent combining different dates
        v.id as vendor_id,
        v.name as vendor_name,
        v.contact as vendor_contact,
        v.type as vendor_type,
        GROUP_CONCAT(DISTINCT vi.invoice_number ORDER BY CAST(vi.invoice_number AS UNSIGNED)) as invoice_numbers,
        GROUP_CONCAT(DISTINCT vi.id ORDER BY vi.id) as invoice_ids,
        COUNT(DISTINCT vi.id) as invoice_count,
        SUM(vii.quantity) as total_quantity,
        COUNT(vii.id) as items_count,
        SUM(vii.amount) as total_amount
        FROM vendor_invoices vi
        JOIN vendors v ON vi.vendor_id = v.id
        LEFT JOIN vendor_invoice_items vii ON vi.id = vii.invoice_id
        WHERE DATE(vi.created_at) = ?";

// Add vendor search if provided
if (!empty($vendor_search)) {
    $sql .= " AND (v.name LIKE ? OR v.contact LIKE ? OR v.type LIKE ?)";
}

// Group by vendor, created_at date, and display_group_date (invoice_date), FIFO sorting - oldest invoices first
$sql .= " GROUP BY DATE(vi.created_at), display_group_date, v.id ORDER BY MIN(CAST(vi.invoice_number AS UNSIGNED)) ASC";

// Prepare and execute the query with filters
$stmt = $conn->prepare($sql);

if (!empty($vendor_search)) {
    $search_param = "%$vendor_search%";
    $stmt->bind_param("ssss", $filter_date, $search_param, $search_param, $search_param);
} else {
    $stmt->bind_param("s", $filter_date);
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

// Handle session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!-- Main content -->
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center">
            <h2 class="mb-0 me-3">Vendor Invoices</h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dateModal" title="Select Date">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
        </div>
        <div>
            <a href="../../print/vendors/all_invoices.php?date=<?php echo $filter_date; ?>" 
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
                       placeholder="Search vendors by name, contact or type..." 
                       value="<?php echo htmlspecialchars($vendor_search); ?>">
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
                <strong>Showing invoices created on:</strong> <?php echo date('d/m/Y', strtotime($filter_date)); ?>
                <?php if (!empty($vendor_search)): ?>
                    <span class="ms-2 text-muted">
                        <i class="fas fa-search me-1"></i>
                        Filtered by: "<?php echo htmlspecialchars($vendor_search); ?>"
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
                        <th class="border-0">Vendor</th>
                        <th class="border-0">Type</th>
                        <th class="border-0">Amount</th>
                        <th class="border-0">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr class="border-bottom" data-vendor="<?php echo strtolower(htmlspecialchars($row['vendor_name'] . ' ' . ($row['vendor_contact'] ?? '') . ' ' . ($row['vendor_type'] ?? ''))); ?>">
                            <td class="py-3">
                                <div class="fw-bold text-primary"><?php echo htmlspecialchars($row['invoice_numbers']); ?></div>
                                <small class="text-muted">Invoice Date: <?php echo date('d/m/Y', strtotime($row['display_group_date'])); ?></small>
                                <small class="text-muted d-block">Created: <?php echo date('d/m/Y', strtotime($row['created_date'])); ?></small>
                            </td>
                            <td class="py-3">
                                <div class="fw-bold"><?php echo htmlspecialchars($row['vendor_name']); ?></div>
                                <?php if (!empty($row['vendor_contact'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['vendor_contact']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="py-3">
                                <span class="badge bg-<?php echo $row['vendor_type'] === 'Local' ? 'primary' : 'info'; ?> fs-6 px-3 py-2">
                                    <?php echo htmlspecialchars($row['vendor_type']); ?>
                                </span>
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
                                        <a href="view_invoice.php?id=<?php echo $first_invoice_id; ?>" 
                                           class="btn btn-outline-primary" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="../../handlers/vendors/download_invoice.php?id=<?php echo $first_invoice_id; ?>" 
                                           class="btn btn-outline-success" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <a href="../../print/vendors/invoice.php?id=<?php echo $first_invoice_id; ?>" 
                                           class="btn btn-outline-info" title="Print" target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    <?php else: ?>
                                        <!-- Changed to use the same view page instead of a combined view -->
                                        <a href="view_invoice.php?vendor_id=<?php echo $row['vendor_id']; ?>&date=<?php echo $row['invoice_date']; ?>" 
                                           class="btn btn-outline-primary" title="View All">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="../../handlers/vendors/download_invoice.php?vendor_id=<?php echo $row['vendor_id']; ?>&date=<?php echo $row['invoice_date']; ?>" 
                                           class="btn btn-outline-success" title="Download All">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <a href="../../print/vendors/invoice.php?vendor_id=<?php echo $row['vendor_id']; ?>&date=<?php echo $row['invoice_date']; ?>" 
                                           class="btn btn-outline-info" title="Print All" target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Only show edit button for single invoices created today
                                    $created_date = $row['created_date'];
                                    $today = date('Y-m-d');
                                    if ($row['invoice_count'] == 1 && $created_date == $today): 
                                    ?>
                                        <a href="edit_invoice.php?id=<?php echo $first_invoice_id; ?>" 
                                           class="btn btn-outline-warning" title="Edit Invoice">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php elseif ($row['invoice_count'] > 1): ?>
                                        <a href="edit_combined.php?vendor_id=<?php echo $row['vendor_id']; ?>&date=<?php echo $row['invoice_date']; ?>" 
                                           class="btn btn-outline-warning" title="Edit All Invoices">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php else: ?>
                                        <button type="button" 
                                                class="btn btn-outline-warning" 
                                                disabled
                                                title="Cannot edit invoices from previous days">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Show delete button only for invoices created today
                                    if ($row['invoice_count'] == 1 && $created_date == $today): 
                                    ?>
                                        <button type="button" 
                                                class="btn btn-outline-danger delete-invoice" 
                                                data-invoice-id="<?php echo $first_invoice_id; ?>"
                                                data-invoice-number="<?php echo htmlspecialchars(explode(',', $row['invoice_numbers'])[0]); ?>"
                                                data-vendor-name="<?php echo htmlspecialchars($row['vendor_name']); ?>"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php elseif ($row['invoice_count'] > 1 && $created_date == $today): ?>
                                        <!-- For multiple invoices with same invoice_date created today -->
                                        <button type="button" 
                                                class="btn btn-outline-danger delete-combined-invoices" 
                                                data-vendor-id="<?php echo $row['vendor_id']; ?>"
                                                data-date="<?php echo $row['display_group_date']; ?>"
                                                data-vendor-name="<?php echo htmlspecialchars($row['vendor_name']); ?>"
                                                data-invoice-count="<?php echo $row['invoice_count']; ?>"
                                                title="Delete All Invoices">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" 
                                                class="btn btn-outline-danger" 
                                                disabled
                                                title="Cannot delete invoices created on previous days">
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
                    <i class="fas fa-calendar-alt me-2"></i>Select Creation Date
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="get">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="modal_filter_date" class="form-label">Choose Date:</label>
                        <input type="date" class="form-control" id="modal_filter_date" name="filter_date" value="<?php echo $filter_date; ?>" required>
                    </div>
                    <?php if (!empty($vendor_search)): ?>
                        <input type="hidden" name="vendor_search" value="<?php echo htmlspecialchars($vendor_search); ?>">
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
                <p><strong>Vendor:</strong> <span id="deleteVendorName"></span></p>
                <p><strong>Note:</strong> This will update vendor balance.</p>
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

<!-- Delete Combined Invoices Modal -->
<div class="modal fade" id="deleteCombinedModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Combined Invoices</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <span id="deleteCombinedCount"></span> invoices for <span id="deleteCombinedVendorName"></span> with invoice date <span id="deleteCombinedDate"></span>?</p>
                <p><strong>Note:</strong> This will update vendor balance and delete any associated payments.</p>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action cannot be undone! All invoices and associated payments will be permanently deleted.
                </div>
            </div>
            <div class="modal-footer">
                <form action="../../handlers/vendors/delete_combined.php" method="get">
                    <input type="hidden" name="vendor_id" id="deleteCombinedVendorId">
                    <input type="hidden" name="date" id="deleteCombinedInvoiceDate">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete All Invoices</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Wait for DOM to be loaded
document.addEventListener('DOMContentLoaded', function() {
    // Handle single invoice delete button click
    document.querySelectorAll('.delete-invoice').forEach(function(button) {
        button.addEventListener('click', function() {
            var invoiceId = this.getAttribute('data-invoice-id');
            var invoiceNumber = this.getAttribute('data-invoice-number');
            var vendorName = this.getAttribute('data-vendor-name');
            
            document.getElementById('deleteInvoiceId').value = invoiceId;
            document.getElementById('deleteInvoiceNumber').textContent = invoiceNumber;
            document.getElementById('deleteVendorName').textContent = vendorName;
            
            // Use Bootstrap 5 modal
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        });
    });
    
    // Handle combined invoices delete button click
    document.querySelectorAll('.delete-combined-invoices').forEach(function(button) {
        button.addEventListener('click', function() {
            var vendorId = this.getAttribute('data-vendor-id');
            var date = this.getAttribute('data-date');
            var vendorName = this.getAttribute('data-vendor-name');
            var invoiceCount = this.getAttribute('data-invoice-count');
            
            document.getElementById('deleteCombinedVendorId').value = vendorId;
            document.getElementById('deleteCombinedInvoiceDate').value = date;
            document.getElementById('deleteCombinedVendorName').textContent = vendorName;
            document.getElementById('deleteCombinedCount').textContent = invoiceCount;
            document.getElementById('deleteCombinedDate').textContent = new Date(date).toLocaleDateString();
            
            // Use Bootstrap 5 modal
            var deleteCombinedModal = new bootstrap.Modal(document.getElementById('deleteCombinedModal'));
            deleteCombinedModal.show();
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
            const vendorData = row.getAttribute('data-vendor').toLowerCase();
            const isVisible = vendorData.includes(searchTerm);
            
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
