    </div> <!-- End of wrapper -->
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <?php
    // Determine the correct path to assets based on current file location
    $current_path = $_SERVER['PHP_SELF'];
    $path_parts = explode('/', trim($current_path, '/'));
    $depth = count($path_parts) - 2; // Subtract filename and base folder
    $base_path = $depth > 0 ? str_repeat('../', $depth) : '';
    ?>
    <script src="<?php echo $base_path; ?>assets/js/script.js"></script>
</body>
</html> 