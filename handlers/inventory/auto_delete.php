<?php
/**
 * Auto Delete Inventory Items
 * This script automatically deletes inventory items that have been completely sold
 * and have had a watak created for them.
 * 
 * This script should be run daily at 12 AM via cron job or task scheduler.
 */

require_once __DIR__ . '/../../config/config.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');

$log_file = 'logs/auto_delete_inventory.log';
$last_run_file = 'logs/last_auto_delete.txt';

// Create logs directory if it doesn't exist
if (!is_dir('logs')) {
    mkdir('logs', 0755, true);
}

// Function to write to log file
function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Function to check if it's time to run (12 AM daily)
function shouldRunAutoDelete() {
    global $last_run_file;
    
    // If last run file doesn't exist, run it
    if (!file_exists($last_run_file)) {
        return true;
    }
    
    // Read last run time
    $last_run = file_get_contents($last_run_file);
    $last_run_date = date('Y-m-d', strtotime($last_run));
    $current_date = date('Y-m-d');
    
    // If it's a new day and we haven't run today, run it
    return $last_run_date !== $current_date;
}

// Function to mark that we've run today
function markAsRun() {
    global $last_run_file;
    $current_time = date('Y-m-d H:i:s');
    file_put_contents($last_run_file, $current_time);
}

// Function to check if a watak has been created for an inventory item
function hasWatakBeenCreated($conn, $inventory_item_id, $item_name, $vendor_id, $date_received) {
    // Since there's no direct foreign key relationship between inventory_items and watak_items,
    // we'll check by matching vendor, inventory date, and item name
    $sql = "SELECT COUNT(*) as watak_count 
            FROM watak_items wi 
            JOIN vendor_watak vw ON wi.watak_id = vw.id 
            WHERE vw.vendor_id = ? 
            AND DATE(vw.inventory_date) = ? 
            AND wi.item_name = ?";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $vendor_id, $date_received, $item_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['watak_count'] > 0;
    } catch (Exception $e) {
        writeLog("Error checking watak creation: " . $e->getMessage());
        return false;
    }
}

// Function to perform the auto-deletion
function autoDeleteInventoryItems($conn) {
    writeLog("Starting auto-deletion process...");
    
    // Find all inventory items that have been completely sold
    $sql = "SELECT 
                ii.id as inventory_item_id,
                ii.item_name,
                ii.remaining_stock,
                ii.vendor_id,
                ii.date_received,
                v.vendor_name
            FROM inventory_items ii
            JOIN vendors v ON ii.vendor_id = v.id
            WHERE ii.remaining_stock = 0";
    
    try {
        $result = $conn->query($sql);
        $deleted_count = 0;
        $skipped_count = 0;
        
        if ($result->num_rows > 0) {
            writeLog("Found " . $result->num_rows . " items with 0 remaining stock");
            
            while ($row = $result->fetch_assoc()) {
                $inventory_item_id = $row['inventory_item_id'];
                $item_name = $row['item_name'];
                $vendor_id = $row['vendor_id'];
                $date_received = $row['date_received'];
                $vendor_name = $row['vendor_name'];
                
                // Check if a watak has been created for this inventory item
                if (hasWatakBeenCreated($conn, $inventory_item_id, $item_name, $vendor_id, $date_received)) {
                    // Delete the inventory item
                    $delete_sql = "DELETE FROM inventory_items WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $inventory_item_id);
                    
                    if ($delete_stmt->execute()) {
                        writeLog("Deleted inventory item: $item_name (Vendor: $vendor_name, Date: $date_received)");
                        $deleted_count++;
                    } else {
                        writeLog("Failed to delete inventory item: $item_name (Vendor: $vendor_name, Date: $date_received)");
                    }
                    $delete_stmt->close();
                } else {
                    writeLog("Skipped deletion for $item_name (Vendor: $vendor_name, Date: $date_received) - No watak created yet");
                    $skipped_count++;
                }
            }
        } else {
            writeLog("No items found with 0 remaining stock");
        }
        
        // Clean up empty inventory records
        $cleanup_sql = "DELETE FROM inventory WHERE id NOT IN (SELECT DISTINCT inventory_id FROM inventory_items)";
        $cleanup_result = $conn->query($cleanup_sql);
        $affected_rows = $conn->affected_rows;
        
        if ($affected_rows > 0) {
            writeLog("Cleaned up $affected_rows empty inventory records");
        }
        
        writeLog("Auto-deletion completed. Deleted: $deleted_count, Skipped: $skipped_count");
        return [
            'deleted' => $deleted_count,
            'skipped' => $skipped_count,
            'cleaned_up' => $affected_rows
        ];
        
    } catch (Exception $e) {
        writeLog("Error during auto-deletion: " . $e->getMessage());
        throw $e;
    }
}

// Check if this is a web request or command line
$is_web_request = isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI']);

if ($is_web_request) {
    // This is a web request - check if we should run
    if (shouldRunAutoDelete()) {
        try {
            $results = autoDeleteInventoryItems($conn);
            markAsRun();
            
            // Return results for web display
            if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
                // AJAX request from inventory_list.php
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Auto-deletion completed successfully',
                    'results' => $results
                ]);
            } else {
                // Direct web request
                echo "<h2>Auto-Delete Inventory Items</h2>";
                echo "<p>Auto-deletion completed successfully!</p>";
                echo "<p>Deleted items: " . $results['deleted'] . "</p>";
                echo "<p>Skipped items: " . $results['skipped'] . "</p>";
                echo "<p>Cleaned up empty records: " . $results['cleaned_up'] . "</p>";
                echo "<p><a href='../../views/inventory/index.php'>Back to Inventory List</a></p>";
            }
        } catch (Exception $e) {
            writeLog("Error in web-based auto-deletion: " . $e->getMessage());
            
            if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Error during auto-deletion: ' . $e->getMessage()
                ]);
            } else {
                echo "<h2>Auto-Delete Inventory Items</h2>";
                echo "<p>Error: " . $e->getMessage() . "</p>";
                echo "<p><a href='../../views/inventory/index.php'>Back to Inventory List</a></p>";
            }
        }
    } else {
        // Not time to run yet
        if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Auto-deletion already ran today. Will run again tomorrow at 12 AM.'
            ]);
        } else {
            echo "<h2>Auto-Delete Inventory Items</h2>";
            echo "<p>Auto-deletion already ran today. Will run again tomorrow at 12 AM.</p>";
            echo "<p><a href='../../views/inventory/index.php'>Back to Inventory List</a></p>";
        }
    }
} else {
    // This is a command line execution (for backward compatibility)
    writeLog("Starting auto-deletion via command line");
    
    try {
        $results = autoDeleteInventoryItems($conn);
        markAsRun();
        writeLog("Command line execution completed successfully");
        
        echo "Auto-deletion completed successfully!\n";
        echo "Deleted items: " . $results['deleted'] . "\n";
        echo "Skipped items: " . $results['skipped'] . "\n";
        echo "Cleaned up empty records: " . $results['cleaned_up'] . "\n";
        
    } catch (Exception $e) {
        writeLog("Error in command line execution: " . $e->getMessage());
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

$conn->close();
?>
