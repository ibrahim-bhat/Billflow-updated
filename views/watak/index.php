<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Handle watak deletion
if (isset($_POST['delete_watak'])) {
    $watak_id = intval($_POST['watak_id']);
    
    try {
        $conn->begin_transaction();
        
        // Get watak details before deletion
        $sql = "SELECT w.*, v.name as vendor_name FROM vendor_watak w 
                JOIN vendors v ON w.vendor_id = v.id 
                WHERE w.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $watak_id);
        $stmt->execute();
        $watak = $stmt->get_result()->fetch_assoc();
        
        if (!$watak) {
            throw new Exception("Watak not found");
        }
        
        // Check if watak is from today (same day restriction)
        $watak_date = date('Y-m-d', strtotime($watak['date']));
        $today = date('Y-m-d');
        
        if ($watak_date !== $today) {
            throw new Exception("Cannot delete watak from a different date. Only today's wataks can be deleted.");
        }
        
        // Update vendor balance (remove the watak amount)
        // Since watak increases vendor balance, we need to decrease it when deleting
        $sql = "UPDATE vendors SET balance = balance - ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('di', $watak['net_payable'], $watak['vendor_id']);
        $stmt->execute();
        
        // Delete watak items first
        $sql = "DELETE FROM watak_items WHERE watak_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $watak_id);
        $stmt->execute();
        
        // Delete the watak
        $sql = "DELETE FROM vendor_watak WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $watak_id);
        $stmt->execute();
        
        $conn->commit();
        $success_message = "Watak deleted successfully. Vendor balance updated.";
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error deleting watak: " . $e->getMessage();
    }
}

// Get selected date (default to today)
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$vendor_search = isset($_GET['vendor_search']) ? $_GET['vendor_search'] : '';
$is_filtered = true; // Always show filtered results, default to today

// Get wataks for selected date
$sql = "SELECT w.*, v.name as vendor_name, v.contact as vendor_contact, v.type as vendor_type 
        FROM vendor_watak w
        JOIN vendors v ON w.vendor_id = v.id
        WHERE (
            ? = CURRENT_DATE AND DATE(w.date) = CURRENT_DATE
            OR
            ? != CURRENT_DATE AND DATE(w.inventory_date) = ?
        )";

// Add vendor search if provided
if (!empty($vendor_search)) {
    $sql .= " AND (v.name LIKE ? OR v.contact LIKE ? OR v.type LIKE ?)";
}

$sql .= " ORDER BY w.watak_number ASC";

$stmt = $conn->prepare($sql);

if (!empty($vendor_search)) {
    $search_param = "%$vendor_search%";
    $stmt->bind_param("ssssss", $filter_date, $filter_date, $filter_date, $search_param, $search_param, $search_param);
} else {
    $stmt->bind_param("sss", $filter_date, $filter_date, $filter_date);
}

$stmt->execute();
$result = $stmt->get_result();

// Calculate summary statistics
$watak_count = $result->num_rows;
$total_watak_amount = 0;
$total_net_payable = 0;

// We need to clone the result to avoid consuming it
$temp_result = $result;
while ($row = $temp_result->fetch_assoc()) {
    $total_watak_amount += $row['total_amount'];
    $total_net_payable += $row['net_payable'];
}

// Reset the result pointer to the beginning
mysqli_data_seek($result, 0);
?>

<!-- Main content -->
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center">
            <h2 class="mb-0 me-3">Vendor Wataks</h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dateModal" title="Select Date">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="../../print/watak/all_local.php?date=<?php echo $filter_date; ?>"
               class="btn btn-outline-success"
               title="Print All Local Watak Invoices"
               target="_blank">
                <i class="fas fa-print"></i> Print All Local Watak
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
                <strong>Showing wataks for:</strong> <?php echo date('d/m/Y', strtotime($filter_date)); ?>
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
            <table class="table table-hover table-borderless" id="watakTable">
                            <thead>
                                <tr class="table-light">
                        <th class="border-0">Watak #</th>
                                    <th class="border-0">Vendor</th>
                        <th class="border-0">Type</th>
                                    <th class="border-0">Total Amount</th>
                        <th class="border-0">Net Payable</th>
                                    <th class="border-0">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                    <?php while ($watak = $result->fetch_assoc()): ?>
                        <tr class="watak-row border-bottom" data-vendor="<?php echo strtolower(htmlspecialchars($watak['vendor_name'] . ' ' . ($watak['vendor_contact'] ?? '') . ' ' . ($watak['vendor_type'] ?? ''))); ?>">
                            <td class="py-3">
                                <div class="fw-bold text-primary"><?php echo htmlspecialchars($watak['watak_number']); ?></div>
                            </td>
                            <td class="py-3">
                                <div class="fw-bold"><?php echo htmlspecialchars($watak['vendor_name']); ?></div>
                                <?php if (!empty($watak['vendor_contact'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($watak['vendor_contact']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="py-3">
                                <span class="badge bg-<?php echo $watak['vendor_type'] === 'Local' ? 'primary' : 'info'; ?> fs-6 px-3 py-2">
                                    <?php echo htmlspecialchars($watak['vendor_type']); ?>
                                </span>
                            </td>
                            <td class="py-3">
                                <span class="badge bg-success fs-6 px-3 py-2">
                                    ₹<?php echo number_format($watak['total_amount'], 2); ?>
                                </span>
                            </td>
                            <td class="py-3">
                                <span class="badge bg-warning fs-6 px-3 py-2">
                                    ₹<?php echo number_format($watak['net_payable'], 2); ?>
                                </span>
                            </td>
                            <td class="py-3">
                                <div class="btn-group btn-group-sm action-buttons">
                                    <a href="view.php?id=<?php echo $watak['id']; ?>" 
                                       class="btn btn-outline-primary" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                    <a href="../../print/watak/watak.php?id=<?php echo $watak['id']; ?>" 
                                       class="btn btn-outline-info" title="Print" target="_blank">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                    <a href="../../handlers/watak/download.php?id=<?php echo $watak['id']; ?>" 
                                       class="btn btn-outline-success" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                    <a href="edit.php?id=<?php echo $watak['id']; ?>" 
                                       class="btn btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if (date('Y-m-d') === date('Y-m-d', strtotime($watak['date']))): ?>
                                        <button type="button" class="btn btn-outline-danger" title="Delete"
                                                onclick="deleteWatak(<?php echo $watak['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-danger" disabled 
                                                title="Can only delete today's wataks">
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
            <h5 class="text-muted">No wataks found</h5>
            <p class="text-muted">No wataks found for <?php echo date('d/m/Y', strtotime($filter_date)); ?></p>
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
                <h5 class="modal-title">Delete Watak</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete watak <span id="deleteWatakNumber"></span>?</p>
                <p><strong>Note:</strong> This will update vendor balance by removing the watak amount.</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action cannot be undone!
                </div>
            </div>
            <div class="modal-footer">
                <form method="post">
                    <input type="hidden" name="watak_id" id="deleteWatakId">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_watak" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function deleteWatak(watakId) {
    if (confirm('Are you sure you want to delete this watak? This action cannot be undone.')) {
        // Set the watak ID for the modal
        document.getElementById('deleteWatakId').value = watakId;
        
        // Show the modal
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
}

// Live Search Functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const clearButton = document.getElementById('clearSearch');
    const tableRows = document.querySelectorAll('.watak-row');
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
        const visibleRows = document.querySelectorAll('.watak-row:not(.d-none)');
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
                        searchInfo.innerHTML = `<i class="fas fa-search me-1"></i>Showing ${visibleCount} of ${totalRows} wataks`;
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

    // Enter key to clear search
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