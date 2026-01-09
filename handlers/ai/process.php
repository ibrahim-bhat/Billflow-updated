<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../views/ai/index.php');
    exit();
}

$posted = $_POST['invoices'] ?? [];
if (!is_array($posted) || empty($posted)) {
    $_SESSION['error_message'] = 'No invoices to process.';
    header('Location: ../../views/ai/map.php');
    exit();
}

$created_count = 0;
$errors = [];

function findItemIdByName($conn, $name) {
    // Remove date pattern like (05/11/2025) from the item name
    $baseName = preg_replace('/\s*\(\d{2}\/\d{2}\/\d{4}\)\s*$/', '', trim($name));
    
    // First try exact match with base name
    $sql = "SELECT id FROM items WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $baseName);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res) {
        return $res['id'];
    }
    
    // Try fuzzy match - remove all spaces and special characters from base name
    $cleanName = preg_replace('/[^a-z0-9]/i', '', $baseName);
    $sql = "SELECT id, name FROM items";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $cleanDbName = preg_replace('/[^a-z0-9]/i', '', $row['name']);
        if (strcasecmp($cleanName, $cleanDbName) === 0) {
            return $row['id'];
        }
    }
    
    return null;
}

function pickInventoryItem($conn, $vendorId, $itemId, $targetDate = null) {
    if ($targetDate) {
        $sql = "SELECT ii.id, ii.remaining_stock
                FROM inventory_items ii
                JOIN inventory inv ON ii.inventory_id = inv.id
                WHERE inv.vendor_id = ? AND ii.item_id = ? AND ii.remaining_stock > 0
                  AND DATE(inv.date_received) = ?
                ORDER BY inv.date_received ASC, ii.id ASC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iis', $vendorId, $itemId, $targetDate);
    } else {
        $sql = "SELECT ii.id, ii.remaining_stock
                FROM inventory_items ii
                JOIN inventory inv ON ii.inventory_id = inv.id
                WHERE inv.vendor_id = ? AND ii.item_id = ? AND ii.remaining_stock > 0
                ORDER BY inv.date_received ASC, ii.id ASC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $vendorId, $itemId);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

foreach ($posted as $idx => $inv) {
    $customer_id = intval($inv['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        $errors[] = "Invoice #" . ($idx+1) . ": missing customer.";
        continue;
    }

    $display_date = !empty($inv['display_date']) ? $inv['display_date'] : date('Y-m-d');
    $system_date = date('Y-m-d');

    // Load AI markers from company_settings
    $markers = $conn->query("SELECT ai_prev_marker, ai_prev_prev_marker FROM company_settings LIMIT 1")->fetch_assoc();
    $prev_marker = strtolower(trim($markers['ai_prev_marker'] ?? 'p'));
    $prev_prev_marker = strtolower(trim($markers['ai_prev_prev_marker'] ?? 'pp'));

    $conn->begin_transaction();
    try {
        // Always auto-generate invoice number from database
        $next = getNextInvoiceNumber($conn);
        $invoice_number = formatInvoiceNumber($next);

        $insert_sql = "INSERT INTO customer_invoices (customer_id, invoice_number, date, display_date, total_amount)
                       VALUES (?, ?, ?, ?, 0)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param('isss', $customer_id, $invoice_number, $system_date, $display_date);
        $stmt->execute();
        $invoice_id = $conn->insert_id;

        $items = $inv['items'] ?? [];
        $total_amount = 0.0;

        foreach ($items as $j => $it) {
            $item_name_raw = trim($it['item_name'] ?? '');
            $vendor_id = intval($it['vendor_id'] ?? 0);
            $vendor_code_raw = isset($it['vendor_code_raw']) ? strtolower(trim($it['vendor_code_raw'])) : '';
            $batch_marker_input = isset($it['batch_marker']) ? strtolower(trim($it['batch_marker'])) : '';

            // Extract date from item name if present (e.g., "item 1 (04/11/2025)")
            $extractedDate = null;
            if (preg_match('/\((\d{2})\/(\d{2})\/(\d{4})\)/', $item_name_raw, $matches)) {
                // Convert DD/MM/YYYY to YYYY-MM-DD
                $extractedDate = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
                error_log("Extracted date from '$item_name_raw': DD/MM/YYYY={$matches[1]}/{$matches[2]}/{$matches[3]} -> YYYY-MM-DD=$extractedDate");
            }
            
            // Remove date from item name for lookup
            $item_name = preg_replace('/\s*\(\d{2}\/\d{2}\/\d{4}\)\s*$/', '', trim($item_name_raw));
            error_log("Item name after date removal: '$item_name' (from '$item_name_raw')");

            // Resolve vendor via shortcut if not provided
            if ($vendor_id <= 0 && $vendor_code_raw !== '') {
                $marker_used = '';
                if ($prev_prev_marker && substr($vendor_code_raw, -strlen($prev_prev_marker)) === $prev_prev_marker) {
                    $marker_used = $prev_prev_marker;
                    $vendor_short = substr($vendor_code_raw, 0, -strlen($prev_prev_marker));
                } elseif ($prev_marker && substr($vendor_code_raw, -strlen($prev_marker)) === $prev_marker) {
                    $marker_used = $prev_marker;
                    $vendor_short = substr($vendor_code_raw, 0, -strlen($prev_marker));
                } else {
                    $vendor_short = $vendor_code_raw;
                }

                $sqlv = "SELECT id FROM vendors WHERE LOWER(TRIM(shortcut_code)) = ? LIMIT 1";
                $sv = $conn->prepare($sqlv);
                $sv->bind_param('s', $vendor_short);
                $sv->execute();
                $vres = $sv->get_result()->fetch_assoc();
                if ($vres && isset($vres['id'])) {
                    $vendor_id = intval($vres['id']);
                }

                if ($batch_marker_input === '' && $marker_used !== '') {
                    $batch_marker_input = $marker_used;
                }
            }
            $qty = floatval($it['quantity'] ?? 0);
            $weight = floatval($it['weight'] ?? 0);
            $rate = floatval($it['rate'] ?? 0);
            $amount = isset($it['amount']) ? floatval($it['amount']) : 0.0;

            if ($item_name === '' || $vendor_id <= 0) {
                continue;
            }

            $item_id = findItemIdByName($conn, $item_name);
            if (!$item_id) {
                throw new Exception("Item not found: " . $item_name);
            }

            if ($amount <= 0) {
                if ($weight > 0) {
                    $amount = $weight * $rate;
                } else {
                    $amount = $qty * $rate;
                }
            }

            // Determine target date from batch marker or extracted date
            $targetDate = null;
            if ($extractedDate) {
                // Use the date extracted from item name (e.g., "item 1 (04/11/2025)")
                $targetDate = $extractedDate;
                error_log("Using extracted date: $targetDate");
            } elseif ($batch_marker_input === $prev_marker) {
                $targetDate = date('Y-m-d', strtotime($system_date . ' -1 day'));
                error_log("Using prev_marker date: $targetDate");
            } elseif ($batch_marker_input === $prev_prev_marker) {
                $targetDate = date('Y-m-d', strtotime($system_date . ' -2 days'));
                error_log("Using prev_prev_marker date: $targetDate");
            } else {
                $targetDate = $system_date;
                error_log("Using system date: $targetDate");
            }

            error_log("Looking for inventory: vendor_id=$vendor_id, item_id=$item_id, targetDate=$targetDate");
            $inventory = pickInventoryItem($conn, $vendor_id, $item_id, $targetDate);
            if (!$inventory) {
                error_log("No inventory found for: vendor_id=$vendor_id, item_id=$item_id, targetDate=$targetDate");
                throw new Exception("No stock for item: " . $item_name);
            }
            error_log("Found inventory: id={$inventory['id']}, remaining_stock={$inventory['remaining_stock']}");

            $deduct = ($weight > 0) ? $weight : $qty;
            if ($deduct <= 0) { $deduct = $qty; }

            $update_sql = "UPDATE inventory_items SET remaining_stock = remaining_stock - ? WHERE id = ? AND remaining_stock >= ?";
            $ustmt = $conn->prepare($update_sql);
            $ustmt->bind_param('dii', $deduct, $inventory['id'], $deduct);
            $ustmt->execute();
            if ($ustmt->affected_rows <= 0) {
                throw new Exception("Insufficient stock for item: " . $item_name);
            }

            $item_sql = "INSERT INTO customer_invoice_items (invoice_id, item_id, vendor_id, inventory_item_id, quantity, weight, rate, amount)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $istmt = $conn->prepare($item_sql);
            $istmt->bind_param('iiiidddd', $invoice_id, $item_id, $vendor_id, $inventory['id'], $qty, $weight, $rate, $amount);
            $istmt->execute();

            $total_amount += $amount;
        }

        $up_sql = "UPDATE customer_invoices SET total_amount = ? WHERE id = ?";
        $up_stmt = $conn->prepare($up_sql);
        $up_stmt->bind_param('di', $total_amount, $invoice_id);
        $up_stmt->execute();

        $bal_sql = "UPDATE customers SET balance = balance + ? WHERE id = ?";
        $bal_stmt = $conn->prepare($bal_sql);
        $bal_stmt->bind_param('di', $total_amount, $customer_id);
        $bal_stmt->execute();

        $conn->commit();
        $created_count++;
    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = "Invoice #" . ($idx+1) . ": " . $e->getMessage();
    }
}

if ($created_count > 0) {
    $_SESSION['success_message'] = $created_count . ' invoice(s) created successfully.';
}
if (!empty($errors)) {
    $_SESSION['error_message'] = implode("\n", $errors);
}

header('Location: ../../views/invoices/index.php');
exit();
?>


