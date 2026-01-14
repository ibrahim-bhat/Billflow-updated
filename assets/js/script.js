// Common JavaScript functions
console.log('%c script.js LOADED!', 'background: blue; color: white; padding: 5px; font-size: 14px;');

/**
 * Mobile Sidebar Toggle
 */
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.querySelector('.navbar-toggler');
    const sidebar = document.querySelector('.sidebar');
    const body = document.body;
    
    
    // Create overlay element for mobile
    if (!document.querySelector('.sidebar-overlay')) {
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        body.appendChild(overlay);
        console.log('Overlay created');
        
        // Close sidebar when clicking overlay
        overlay.addEventListener('click', function() {
            console.log('Overlay clicked - closing sidebar');
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            body.style.overflow = '';
        });
    }
    
    const overlay = document.querySelector('.sidebar-overlay');
    
    // Toggle sidebar on mobile
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Toggle clicked!');
            console.log('Sidebar classes before:', sidebar.className);
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
            console.log('Sidebar classes after:', sidebar.className);
            console.log('Sidebar computed style:', window.getComputedStyle(sidebar).transform);
            console.log('Sidebar z-index:', window.getComputedStyle(sidebar).zIndex);
            body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
        });
    } else {
        console.error('Missing elements:', {sidebarToggle, sidebar});
    }
    
    // Close sidebar when clicking a link on mobile
    const navLinks = document.querySelectorAll('.sidebar .nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 767) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                body.style.overflow = '';
            }
        });
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 767) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            body.style.overflow = '';
        }
    });
    
    // Menu dots dropdown
    const menuDots = document.querySelector('.menu-dots');
    if (menuDots) {
        menuDots.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            if (menuDots) {
                menuDots.classList.remove('active');
            }
        });
    }
});

// Format currency
function formatCurrency(amount) {
    return '₹' + parseFloat(amount).toFixed(2);
}

// Add item row in invoice/bill forms
function addItemRow() {
    const rowCount = $('.item-row').length + 1;
    const newRow = `
        <tr class="item-row">
            <td>
                <select name="item_id[]" class="form-select item-select" required>
                    <option value="">Select Item</option>
                </select>
            </td>
            <td><input type="number" name="quantity[]" class="form-control quantity" min="0" step="0.01" required></td>
            <td><input type="number" name="weight[]" class="form-control weight" min="0" step="0.01"></td>
            <td><input type="number" name="rate[]" class="form-control rate" min="0" step="0.01" required></td>
            <td><input type="number" name="amount[]" class="form-control amount" readonly></td>
            <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-trash"></i></button></td>
        </tr>
    `;
    $('#items-table tbody').append(newRow);
    
    // Load items for the new row
    loadItems($('.item-row:last-child .item-select'));
    
    // Calculate on input change
    $('.item-row:last-child .quantity, .item-row:last-child .rate').on('input', function() {
        calculateRowAmount($(this).closest('tr'));
        calculateTotal();
    });
    
    // Remove row event
    $('.item-row:last-child .remove-row').on('click', function() {
        $(this).closest('tr').remove();
        calculateTotal();
    });
}

// Calculate row amount
function calculateRowAmount(row) {
    const quantity = parseFloat(row.find('.quantity').val()) || 0;
    const rate = parseFloat(row.find('.rate').val()) || 0;
    const amount = quantity * rate;
    row.find('.amount').val(amount.toFixed(2));
}

// Calculate total amount
function calculateTotal() {
    let total = 0;
    $('.amount').each(function() {
        total += parseFloat($(this).val()) || 0;
    });
    $('#total-amount').text(formatCurrency(total));
    $('input[name="total_amount"]').val(total.toFixed(2));
}

// Load items for dropdown (can be filtered by vendor)
function loadItems(selectElement, vendorId = null) {
    let url = '/includes/get_items.php';
    if (vendorId) {
        url += '?vendor_id=' + vendorId;
    }
    
    $.ajax({
        url: url,
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            selectElement.empty();
            selectElement.append('<option value="">Select Item</option>');
            
            $.each(data, function(index, item) {
                selectElement.append('<option value="' + item.id + '">' + item.name + '</option>');
            });
        },
        error: function() {
            console.error('Failed to load items');
        }
    });
}

// Document ready
$(document).ready(function() {
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add item row button
    $('#add-item-row').on('click', function() {
        addItemRow();
    });
    
    // Initial load for first item row if exists
    if ($('.item-select').length > 0) {
        const vendorId = $('select[name="vendor_id"]').val() || null;
        loadItems($('.item-select'), vendorId);
    }
    
    // Vendor change should update items
    $('select[name="vendor_id"]').on('change', function() {
        const vendorId = $(this).val() || null;
        $('.item-select').each(function() {
            loadItems($(this), vendorId);
        });
    });
}); 