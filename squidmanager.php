<?php
// Sepiida squid manager prototype. This will allow users to easily manage their lists from their home directory.
// Define the main ACL file and an associative array of categories with their corresponding flat file names.
$aclFile = 'squid/blocked_domains.acl';
$categories = [
    'gambling'  => 'gambling_domains.acl',
    'sports'    => 'sports_domains.acl',
    'adult'     => 'adult_domains.acl',
    'games'     => 'games_domains.acl',
    'tobacco'   => 'tobacco_domains.acl',
    'firearms'  => 'firearms_domains.acl',
    'malicious' => 'malicious_domains.acl'
];

// Initialize a message variable for user feedback.
$message = '';

// Process form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* -----------------------------------------------------------
       A. DELETE A DOMAIN FROM THE ACL (Individual Domain Removal)
       ----------------------------------------------------------- */
    if (isset($_POST['delete_domain']) && isset($_POST['domain_to_delete'])) {
        $toDelete = trim($_POST['domain_to_delete']);
        if (file_exists($aclFile)) {
            $lines = file($aclFile, FILE_IGNORE_NEW_LINES);
            $newLines = [];
            $deleted = false;
            foreach ($lines as $line) {
                // Only delete if the trimmed line exactly matches.
                if (trim($line) === $toDelete) {
                    $deleted = true;
                    // Skip adding this line.
                    continue;
                }
                $newLines[] = $line;
            }
            if (file_put_contents($aclFile, implode("\n", $newLines) . "\n") !== false) {
                if ($deleted) {
                    $message .= "Removed domain: " . htmlspecialchars($toDelete) . ". ";
                } else {
                    $message .= "Domain not found: " . htmlspecialchars($toDelete) . ". ";
                }
            } else {
                $message .= "Error updating file for removal of: " . htmlspecialchars($toDelete) . ". ";
            }
        } else {
            $message .= "ACL file does not exist. ";
        }
    }

    /* --------------------------------------------
       B. Manual Domain Addition Form Processing
       -------------------------------------------- */
    if (isset($_POST['add_domain'])) {
        if (isset($_POST['domain']) && trim($_POST['domain']) !== '') {
            $domain = trim($_POST['domain']);

            // Validate the domain (using PHP 7+ validation)
            if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                if ($fp = fopen($aclFile, 'a')) {
                    if (flock($fp, LOCK_EX)) {
                        if (fwrite($fp, $domain . "\n") !== false) {
                            $message .= "Domain added successfully. ";
                        } else {
                            $message .= "Error: Could not write to the file. ";
                        }
                        flock($fp, LOCK_UN);
                    } else {
                        $message .= "Error: Could not lock the file. ";
                    }
                    fclose($fp);
                } else {
                    $message .= "Error: File is not writable or does not exist. ";
                }
            } else {
                $message .= "Invalid domain. Please enter a valid domain (e.g., example.com). ";
            }
        } else {
            $message .= "Please enter a domain. ";
        }
    }

    /* --------------------------------------------
       C. Update List (Restart Squid) Form Processing
       -------------------------------------------- */
    if (isset($_POST['update_list'])) {
        // Execute the restart command via sudo.
        // Make sure your sudoers file allows the web server user to run:
        // /bin/systemctl restart squid.service without a password.
        $output = shell_exec('sudo /bin/systemctl restart squid.service 2>&1');
        $message .= "Squid restart triggered. Output: " . htmlspecialchars($output) . " ";
    } 
    /* -------------------------------------------------------------
       D. Bulk Update from Predefined Domain List Selection Processing
       ------------------------------------------------------------- */
    if (isset($_POST['update_selection'])) {
        // Get the selected categories (if any).
        $selectedCategories = [];
        if (isset($_POST['categories']) && is_array($_POST['categories'])) {
            $selectedCategories = $_POST['categories'];
        }

        // Read the current ACL file content.
        if (file_exists($aclFile)) {
            $content = file_get_contents($aclFile);
        } else {
            $content = "";
        }

        // Remove existing marker blocks for all managed categories.
        foreach ($categories as $catKey => $dummy) {
            // Use a regular expression in dotall mode to remove from BEGIN to END marker.
            $pattern = '/### BEGIN CATEGORY: ' . preg_quote($catKey, '/') . '\s*.*?\s*### END CATEGORY: ' . preg_quote($catKey, '/') . '\s*/s';
            $content = preg_replace($pattern, '', $content);
        }

        // For each selected category, read its list file and append a new marker block.
        foreach ($selectedCategories as $cat) {
            if (isset($categories[$cat])) {
                $listFile = $categories[$cat];
                if (file_exists($listFile) && is_readable($listFile)) {
                    $listContent = file_get_contents($listFile);
                    // Build the block string with markers.
                    $block = "### BEGIN CATEGORY: " . $cat . "\n" 
                           . trim($listContent) . "\n" 
                           . "### END CATEGORY: " . $cat . "\n";
                    // Append the block.
                    $content .= "\n" . $block;
                    $message .= "Added/updated category: " . htmlspecialchars($cat) . ". ";
                } else {
                    $message .= "Could not read file for category: " . htmlspecialchars($cat) . ". ";
                }
            }
        }

        // Write the updated content back to the ACL file.
        if (file_put_contents($aclFile, $content) !== false) {
            $message .= "Bulk update complete. ";
        } else {
            $message .= "Error writing updated categories to file. ";
        }
    }
}

// Read the current ACL file contents for the code view.
$aclContents = file_exists($aclFile) ? file_get_contents($aclFile) : "ACL file not found.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Blocked Domains for Squid</title>
    <style>
        /* Basic styling for the code view and domain management table */
        #aclView, #manageDomains {
            background: #f7f7f7;
            border: 1px solid #ccc;
            padding: 10px;
            max-height: 400px;
            overflow: auto;
            white-space: pre-wrap;
            font-family: Consolas, monospace;
            margin-top: 10px;
        }
        button.toggle-btn {
            margin-top: 20px;
            padding: 6px 12px;
        }
        table {
            border-collapse: collapse;
            margin-top: 10px;
        }
        table, th, td {
            border: 1px solid #aaa;
        }
        th, td {
            padding: 5px 10px;
        }
    </style>
    <script>
        // Toggle the display of the ACL file view.
        function toggleACLView() {
            var view = document.getElementById("aclView");
            var btn = document.getElementById("toggleViewBtn");
            if (view.style.display === "none" || view.style.display === "") {
                view.style.display = "block";
                btn.textContent = "Hide ACL File";
            } else {
                view.style.display = "none";
                btn.textContent = "Show ACL File";
            }
        }
        // Toggle the display of the Domain Manager (table with delete options).
        function toggleManageDomains() {
            var manageDiv = document.getElementById("manageDomains");
            var btn = document.getElementById("toggleManageBtn");
            if (manageDiv.style.display === "none" || manageDiv.style.display === "") {
                manageDiv.style.display = "block";
                btn.textContent = "Hide Domain Manager";
            } else {
                manageDiv.style.display = "none";
                btn.textContent = "Manage Domains";
            }
        }
    </script>
</head>
<body>
    <h1>Manage Blocked Domains for Squid</h1>
    
    <!-- Display feedback messages -->
    <?php if ($message): ?>
        <p><?php echo $message; ?></p>
    <?php endif; ?>

    <!-- 1. Add Domain Form -->
    <h2>Add a Blocked Domain</h2>
    <form method="post" action="">
        <label for="domain">Domain:</label>
        <input type="text" id="domain" name="domain" placeholder="example.com" required>
        <input type="submit" name="add_domain" value="Add Domain">
    </form>

    <!-- 2. Restart Squid Form -->
    <h2>Restart Squid</h2>
    <form method="post" action="">
        <input type="submit" name="update_list" value="Update List">
    </form>

    <!-- 3. Bulk Update: Predefined Domain Categories -->
    <h2>Bulk Update: Predefined Domain Categories</h2>
    <form method="post" action="">
        <?php foreach ($categories as $key => $file): ?>
            <input type="checkbox" name="categories[]" value="<?php echo htmlspecialchars($key); ?>" id="<?php echo htmlspecialchars($key); ?>">
            <label for="<?php echo htmlspecialchars($key); ?>"><?php echo ucfirst($key); ?></label><br>
        <?php endforeach; ?>
        <input type="submit" name="update_selection" value="Update Selection">
    </form>

    <!-- 4. Code View Section (Hidden by Default) -->
    <button type="button" id="toggleViewBtn" class="toggle-btn" onclick="toggleACLView()">Show ACL File</button>
    <div id="aclView" style="display:none;">
        <h3>Current blocked_domains.acl:</h3>
        <pre><?php echo htmlspecialchars($aclContents); ?></pre>
    </div>

    <!-- 5. Domain Manager: Delete Individual Domains (Hidden by Default) -->
    <button type="button" id="toggleManageBtn" class="toggle-btn" onclick="toggleManageDomains()">Manage Domains</button>
    <div id="manageDomains" style="display:none;">
        <h3>Individual Domain Entries (Excluding Category Markers):</h3>
        <?php
        // Read the ACL file and prepare a list of domains (skip empty lines and marker lines).
        $linesForDeletion = [];
        if (file_exists($aclFile)) {
            $lines = file($aclFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, '###') === 0) {
                    continue;
                }
                $linesForDeletion[] = $trimmed;
            }
        }
        ?>
        <?php if (count($linesForDeletion) > 0): ?>
            <table>
                <tr>
                    <th>Domain</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($linesForDeletion as $domain): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($domain); ?></td>
                        <td>
                            <form method="post" action="" onsubmit="return confirm('Are you sure you want to delete this domain?');">
                                <input type="hidden" name="domain_to_delete" value="<?php echo htmlspecialchars($domain); ?>">
                                <input type="submit" name="delete_domain" value="Delete">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No individual domains found in the ACL file.</p>
        <?php endif; ?>
    </div>
</body>
</html>
