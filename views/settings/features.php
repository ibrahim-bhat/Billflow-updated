<?php
require_once '../../config/config.php';
require_once '../../core/middleware/auth.php';
require_once '../../core/helpers/feature_helper.php';

$page_title = "Feature Settings";
$current_page = "settings";

// Get current feature settings
$features = get_feature_settings();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Billflow</title>
    <link rel="stylesheet" href="../../assets/css/core.css">
    <link rel="stylesheet" href="../../assets/css/components.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <?php include '../../views/layout/header.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-cog"></i> Feature Settings
            </h1>
            <p>Enable or disable system features</p>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php 
                echo $_SESSION['success']; 
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?php 
                echo $_SESSION['error']; 
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>System Features</h3>
            </div>
            <div class="card-body">
                <form id="featureSettingsForm" method="POST">
                    <div class="form-group">
                        <div class="feature-item">
                            <div class="feature-info">
                                <label class="switch-label">
                                    <input type="checkbox" 
                                           name="enable_commission" 
                                           id="enable_commission" 
                                           value="1"
                                           <?php echo $features['commission'] ? 'checked' : ''; ?>>
                                    <span class="switch-slider"></span>
                                </label>
                                <div class="feature-details">
                                    <h4>Commission-Based System</h4>
                                    <p>Enable Watak (Commission) features including commission tracking, watak management, and commission reports.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="feature-item">
                            <div class="feature-info">
                                <label class="switch-label">
                                    <input type="checkbox" 
                                           name="enable_purchase" 
                                           id="enable_purchase" 
                                           value="1"
                                           <?php echo $features['purchase'] ? 'checked' : ''; ?>>
                                    <span class="switch-slider"></span>
                                </label>
                                <div class="feature-details">
                                    <h4>Purchase-Based System</h4>
                                    <p>Enable vendor purchase invoice features including purchase tracking, vendor invoices, and payment management.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="feature-item">
                            <div class="feature-info">
                                <label class="switch-label">
                                    <input type="checkbox" 
                                           name="enable_ai" 
                                           id="enable_ai" 
                                           value="1"
                                           <?php echo $features['ai'] ? 'checked' : ''; ?>>
                                    <span class="switch-slider"></span>
                                </label>
                                <div class="feature-details">
                                    <h4>AI Features</h4>
                                    <p>Enable AI-powered features including smart suggestions, automated categorization, and intelligent insights.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='../../views/dashboard/'">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h3>Important Notes</h3>
            </div>
            <div class="card-body">
                <ul class="info-list">
                    <li><strong>Category Column:</strong> Vendor category column will be visible when at least one system (Commission or Purchase) is enabled.</li>
                    <li><strong>Navigation Menu:</strong> Menu items will be hidden based on disabled features.</li>
                    <li><strong>Page Access:</strong> Users will be redirected if they try to access disabled feature pages.</li>
                    <li><strong>At least one feature must remain enabled</strong> to use the system.</li>
                </ul>
            </div>
        </div>
    </div>

    <style>
        .feature-item {
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            background: #f9f9f9;
        }

        .feature-info {
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }

        .feature-details h4 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 16px;
        }

        .feature-details p {
            margin: 0;
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .switch-label {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
            flex-shrink: 0;
        }

        .switch-label input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .switch-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .switch-slider {
            background-color: #4CAF50;
        }

        input:checked + .switch-slider:before {
            transform: translateX(26px);
        }

        .form-actions {
            margin-top: 30px;
            display: flex;
            gap: 10px;
        }

        .info-list {
            list-style: none;
            padding: 0;
        }

        .info-list li {
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .info-list li:last-child {
            border-bottom: none;
        }

        .mt-3 {
            margin-top: 20px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
    </style>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#featureSettingsForm').on('submit', function(e) {
                e.preventDefault();
                
                // Check if at least one feature is enabled
                var commissonEnabled = $('#enable_commission').is(':checked');
                var purchaseEnabled = $('#enable_purchase').is(':checked');
                var aiEnabled = $('#enable_ai').is(':checked');
                
                if (!commissonEnabled && !purchaseEnabled && !aiEnabled) {
                    alert('At least one feature must be enabled!');
                    return false;
                }
                
                var formData = {
                    enable_commission: commissonEnabled ? 1 : 0,
                    enable_purchase: purchaseEnabled ? 1 : 0,
                    enable_ai: aiEnabled ? 1 : 0
                };
                
                $.ajax({
                    url: '../../handlers/settings/update_features.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert('Settings saved successfully! Page will reload.');
                            window.location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred while saving settings.');
                    }
                });
            });
        });
    </script>
</body>
</html>
