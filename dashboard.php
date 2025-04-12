<?php
session_start();

// Check if the user is logged in as an admin
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}
$logged_in_admin_id = $_SESSION['account_id']; // Store for JS usage

// Include database configuration (which now includes the logging function)
include 'api-general/config.php';

// Initialize count variables and error message
$total_users = 0;
$total_admins = 0;
$total_abstracts = 0;
$total_programs = 0;
$total_departments = 0;
$db_error_message = null;
$recent_abstracts = [];
$programs = []; // For dropdowns
$departments = []; // For dropdowns
$current_admin_details = null; // To store logged-in admin's info

try {
    // --- Fetch Dashboard Counts ---
    $stmt_users = $conn->query("SELECT COUNT(*) AS total FROM account WHERE account_type = 'User'");
    $total_users = $stmt_users->fetchColumn() ?: 0;
    $stmt_users->closeCursor();

    $stmt_admins = $conn->query("SELECT COUNT(*) AS total FROM account WHERE account_type = 'Admin'");
    $total_admins = $stmt_admins->fetchColumn() ?: 0;
    $stmt_admins->closeCursor();

    $stmt_abstracts = $conn->query("SELECT COUNT(*) AS total FROM abstract");
    $total_abstracts = $stmt_abstracts->fetchColumn() ?: 0;
    $stmt_abstracts->closeCursor();

    $stmt_programs_count = $conn->query("SELECT COUNT(*) AS total FROM program");
    $total_programs = $stmt_programs_count->fetchColumn() ?: 0;
    $stmt_programs_count->closeCursor();

    $stmt_departments_count = $conn->query("SELECT COUNT(*) AS total FROM department");
    $total_departments = $stmt_departments_count->fetchColumn() ?: 0;
    $stmt_departments_count->closeCursor();

    // --- Fetch Data for Dropdowns ---
    $stmt_programs_dropdown = $conn->query("SELECT program_id, program_name FROM program ORDER BY program_name ASC");
    $programs = $stmt_programs_dropdown->fetchAll(PDO::FETCH_ASSOC);
    $stmt_programs_dropdown->closeCursor();

    $stmt_departments_dropdown = $conn->query("SELECT department_id, department_name FROM department ORDER BY department_name ASC");
    $departments = $stmt_departments_dropdown->fetchAll(PDO::FETCH_ASSOC);
    $stmt_departments_dropdown->closeCursor();

    // --- Fetch Recent Abstracts ---
    $stmt_recent = $conn->query("
        SELECT
        a.abstract_id, a.title, a.abstract_type,
        CASE
        WHEN a.abstract_type = 'Thesis' THEN p.program_name
        WHEN a.abstract_type = 'Dissertation' THEN dpt.department_name
        ELSE NULL
        END AS related_entity_name
        FROM abstract a
        LEFT JOIN thesis_abstract ta ON a.abstract_id = ta.thesis_id AND a.abstract_type = 'Thesis'
        LEFT JOIN program p ON ta.program_id = p.program_id
        LEFT JOIN dissertation_abstract da ON a.abstract_id = da.dissertation_id AND a.abstract_type = 'Dissertation'
        LEFT JOIN department dpt ON da.department_id = dpt.department_id
        ORDER BY a.abstract_id DESC
        LIMIT 5
        ");
    $recent_abstracts = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
    $stmt_recent->closeCursor();

    // --- Fetch Current Logged-in Admin Details ---
    // Note: This assumes an ADMIN_DETAILS table exists linked by account_id
    // Adjust the query based on your actual schema
    $stmt_admin_details = $conn->prepare("
        SELECT a.account_id, a.username, a.name, a.sex, ad.work_id, ad.position
        FROM account a
        LEFT JOIN admin ad ON a.account_id = ad.admin_id
        WHERE a.account_id = :admin_id AND a.account_type = 'Admin'
        ");
    $stmt_admin_details->bindParam(':admin_id', $logged_in_admin_id, PDO::PARAM_INT);
    $stmt_admin_details->execute();
    $current_admin_details = $stmt_admin_details->fetch(PDO::FETCH_ASSOC);
    $stmt_admin_details->closeCursor();
    // If details weren't found (edge case), set to an empty array or handle error
    if (!$current_admin_details) {
        $current_admin_details = []; // Prevent errors later if admin not found
    }


} catch (PDOException $e) {
    error_log("Dashboard Database Error: " . $e->getMessage());
    $db_error_message = "An error occurred while loading dashboard data. Please try again later.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Electronic Abstract System</title>
    <link rel="stylesheet" href="css/dashboard.css"> <!-- Adjust path if needed -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ... (keep existing styles) ... */
        .db-error { color: red; background-color: #ffeeee; border: 1px solid red; padding: 10px; margin: 15px; }
        .modal-content-wide { background-color: white; padding: 20px; border-radius: 5px; width: 80%; max-width: 700px; }
        .modal-content-view { background-color: white; padding: 20px; border-radius: 5px; width: 80%; max-width: 500px; }
        .grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;}
        .form-group.full-width { grid-column: 1 / -1; }
        .hidden { display: none; }
        .active { display: block; }
        .view-info p { margin: 8px 0; font-size: 0.95rem; line-height: 1.5; }
        .view-info strong { color: #333; margin-right: 8px; display: inline-block; min-width: 120px; }

        /* Settings Section Styles */
        .settings-container { max-width: 800px; margin: 20px auto; padding: 20px; background-color: #f9f9f9; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .settings-section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .settings-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0;}
        .settings-section h3 { margin-top: 0; margin-bottom: 15px; color: #333; border-bottom: 1px solid #ddd; padding-bottom: 5px;}
        #personalDetailsInfo p { margin-bottom: 10px; }
        #personalDetailsInfo strong { display: inline-block; width: 150px; /* Adjust as needed */ color: #555; }
        .danger-zone { border: 1px solid #dc3545; padding: 15px; border-radius: 5px; background-color: #f8d7da; }
        .danger-zone h3 { color: #721c24; border-bottom-color: #f5c6cb;}
        .danger-zone p { color: #721c24; margin-bottom: 15px; }
        .danger-zone button { background-color: #dc3545; border-color: #dc3545; }
        .danger-zone button:hover { background-color: #c82333; border-color: #bd2130; }

        /* Style for action buttons in settings */
        .settings-actions { margin-top: 20px; text-align: right; }
        .settings-actions .btn { margin-left: 10px;}
        /* Optional CSS for Loading Overlay */
        #loadingOverlay {
            position: fixed; /* Stay in place */
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /* background-color: rgba(0, 0, 0, 0.6); /* Semi-transparent black background */ */ /* Set inline */
            display: flex; /* Use flexbox for centering */
            justify-content: center; /* Center horizontally */
            align-items: center; /* Center vertically */
            /* z-index: 1050; */ /* Ensure it's on top (higher than other modals) - Set inline */
        }

/* Style for the content box inside the overlay (redundant if using inline) */
/*
#loadingOverlay > div {
    background-color: white;
    padding: 25px 40px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
*/

/* Style for the spinner icon (redundant if using inline) */
/*
#loadingOverlay .fa-spinner {
    margin-bottom: 15px;
    color: #007bff;
}
*/

/* Ensure the modal is hidden initially */
#loadingOverlay.hidden {
    display: none;
}

/* --- CSS FOR ABOUT SECTION --- */
.about-section { margin-bottom: 30px; padding: 20px; background-color: #fdfdfd; border-radius: 5px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.about-section h2 { margin-top: 0; margin-bottom: 15px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 8px; font-size: 1.4em; }
.about-section p { line-height: 1.6; color: #555; }

/* --- Team Card Styles --- */
/* Base styles for all team cards */
.team-card, .team-card-lead {
    background-color: #fff;
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #eee;
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    display: flex; /* Use flexbox for alignment */
    flex-direction: column; /* Stack elements vertically */
    align-items: center; /* Center items horizontally */
}
.team-card:hover, .team-card-lead:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.12);
}
.team-card img, .team-card-lead img {
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #eee;
    background-color: #f0f0f0; /* Placeholder bg */
}
.team-card h3, .team-card-lead h3 {
    margin: 12px 0 5px 0;
    font-size: 1.1em;
    color: #333;
}
.team-card p, .team-card-lead p {
    font-size: 0.9em;
    color: #777;
    margin: 0;
    line-height: 1.4;
}

/* Specific styles for the Lead Card */
.team-card-lead {
    margin: 0 auto 25px auto; /* Center the lead card and add bottom margin */
    max-width: 300px; /* Limit width */
    padding: 20px; /* Slightly more padding */
    border-top: 4px solid #007bff; /* Highlight top border */
    background-color: #f8f9fa; /* Slightly different background */
}
.team-card-lead img {
    width: 100px; /* Larger image */
    height: 100px;
    margin-bottom: 15px;
}
.team-card-lead h3 {
    font-size: 1.3em; /* Larger name */
}
.team-card-lead p {
    font-size: 1em; /* Larger role text */
    font-weight: 500;
}

/* Styles for the Member Cards Container */
.team-cards-members-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); /* Responsive grid */
    gap: 20px; /* Spacing between cards */
}
/* Styles for individual Member Cards (inherits from .team-card) */
.team-card img {
    width: 80px; /* Standard image size */
    height: 80px;
    margin-bottom: 10px;
}

/* Changelog Styles */
.changelog-list { list-style: none; padding-left: 0; }
.changelog-list li { background-color: #fff; border-left: 4px solid #007bff; margin-bottom: 15px; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.changelog-list li strong { display: block; margin-bottom: 5px; color: #333; font-size: 1.05em; }
/* --- END CSS FOR ABOUT SECTION --- */

@media (max-width: 600px) {
    .abstract-row { flex-direction: column; align-items: flex-start; }
    .abstract-actions { flex-direction: row; width: 100%; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; justify-content: flex-end; }
    .grid-container { grid-template-columns: 1fr; }
    .team-cards-members-container { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
    .team-card-lead { max-width: 90%; } /* Adjust lead card width on small screens */
}

</style>
</head>
<body class="sidebar-hidden">
    <header class="nav_bar">
        <div class="menu" onclick="toggleMenu()">
            <i class="fa-solid fa-bars"></i>
        </div>
        <div class="nav_name" id="nav_name">EASY</div>
    </header>

    <aside id="sidebar" class="sidebar hidden">
        <i id="x-button" class="fa-solid fa-times close-sidebar" onclick="toggleMenu()"></i>
        <div class="logo"><h2>EASY</h2></div>
        <nav>
         <ul>
            <li><a href="#" data-target="dashboard" class="active"><i class="fas fa-tachometer-alt"></i><span> Dashboard</span></a></li>
            <li><a href="#" data-target="manage-accounts"><i class="fas fa-users-cog"></i><span> Manage Accounts</span></a></li>
            <li><a href="#" data-target="manage-abstracts"><i class="fas fa-book"></i><span> Manage Abstracts</span></a></li>
            <li><a href="#" data-target="manage-programs"><i class="fas fa-list-ul"></i><span> Manage Programs</span></a></li>
            <li><a href="#" data-target="manage-departments"><i class="fas fa-building"></i><span> Manage Departments</span></a></li>
            <li><a href="#" data-target="settings"><i class="fas fa-cog"></i><span> Settings</span></a></li>
            <li><a href="#" data-target="about"><i class="fas fa-info-circle"></i><span> About</span></a></li>
        </ul>
    </nav>
</aside>

<div id="shadow" class="shadow hidden" onclick="toggleMenu()"></div>

<main class="content">
    <?php if ($db_error_message): ?>
        <div class="db-error"><?php echo htmlspecialchars($db_error_message); ?></div>
    <?php endif; ?>

    <!-- Dashboard Section (No changes here) -->
    <section id="dashboard" class="active">
     <h1>Dashboard Overview</h1>
     <!-- ... cards and recent activity ... -->
     <section class="cards">
        <div class="card">
            <i class="fas fa-users"></i>
            <h3>Total Users</h3>
            <p><?php echo htmlspecialchars($total_users); ?></p>
        </div>
        <div class="card"> <!-- Optional Admin Card -->
            <i class="fas fa-user-shield"></i>
            <h3>Total Admins</h3>
            <p><?php echo htmlspecialchars($total_admins); ?></p>
        </div>
        <div class="card">
            <i class="fas fa-book"></i>
            <h3>Total Abstracts</h3>
            <p><?php echo htmlspecialchars($total_abstracts); ?></p>
        </div>
        <div class="card">
            <i class="fas fa-list-ul"></i>
            <h3>Total Programs</h3>
            <p><?php echo htmlspecialchars($total_programs); ?></p>
        </div>
        <div class="card">
            <i class="fas fa-building"></i>
            <h3>Total Departments</h3>
            <p><?php echo htmlspecialchars($total_departments); ?></p>
        </div>
    </section>

    <section class="recent-activity">
        <h2>Recent Uploaded Abstracts</h2>
        <?php if (!empty($recent_abstracts)): ?>
            <ul class="abstract-list">
                <?php foreach ($recent_abstracts as $recent): ?>
                    <li class="abstract-item">
                        <div class="abstract-title"><?php echo htmlspecialchars($recent['title']); ?></div>
                        <div class="abstract-info"><strong>Type:</strong> <?php echo htmlspecialchars($recent['abstract_type']); ?></div>
                        <?php if ($recent['related_entity_name']): ?>
                            <div class="abstract-info"><strong><?php echo ($recent['abstract_type'] === 'Thesis' ? 'Program' : 'Department'); ?>:</strong> <?php echo htmlspecialchars($recent['related_entity_name']); ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No recent abstracts found.</p>
        <?php endif; ?>
    </section>
</section>

<!-- Manage Accounts Section (No changes here, except maybe ensure self-delete prevention is robust) -->
<section id="manage-accounts" class="hidden" style="min-height: 80vh;">
 <h2>Manage Accounts</h2>
 <!-- ... search, filter, add admin button, list, modals ... -->
 <div class="search-filter-container">
    <input type="text" id="search-bar-accounts" placeholder="Search accounts by name/username...">
    <select id="filter-account-type">
     <option value="">All Types</option>
     <option value="User">Users Only</option>
     <option value="Admin">Admins Only</option>
 </select>
 <select id="filter-program"> <!-- This will mostly filter Users -->
    <option value="">All Programs</option>
    <?php foreach ($programs as $program): ?>
        <option value="<?php echo htmlspecialchars($program['program_id']); ?>">
            <?php echo htmlspecialchars($program['program_name']); ?>
        </option>
    <?php endforeach; ?>
</select>
<select id="sort-accounts-by">
    <option value="name">Sort by Name</option>
    <option value="username">Sort by Username</option>
    <option value="account_type">Sort by Type</option>
    <option value="program_name">Sort by Program</option> <!-- Primarily for users -->
</select>
<button id="filter-button-accounts">Filter/Sort</button> <!-- Combined button -->
</div>

<button id="add-admin-button" class="add-button-main" onclick="openAddAdminModal()">
    <i class="fas fa-user-plus"></i> Add Admin
</button>

<div id="accounts-list" class="row-lay">
    <!-- Account rows will be populated by JS -->
    <p>Loading accounts...</p>
</div>

<!-- Edit Account Modal (Combined User/Admin Fields) -->
<div id="editAccountModal" class="modal hidden">
 <div class="modal-content-wide">
    <span class="close-modal" onclick="closeEditAccountModal()">×</span>
    <h2>Edit Account</h2>
    <form id="editAccountForm">
        <input type="hidden" id="editAccountId" name="account_id"> <!-- Changed name -->
        <input type="hidden" id="editAccountType" name="editAccountType">

        <div class="grid-container">
            <!-- Common Fields -->
            <div class="form-group">
                <label for="editAccUsername">Username:</label>
                <input type="text" id="editAccUsername" name="username" required>
            </div>
            <div class="form-group">
                <label for="editAccName">Name:</label>
                <input type="text" id="editAccName" name="name" required>
            </div>
            <div class="form-group">
                <label for="editAccSex">Sex:</label>
                <select id="editAccSex" name="sex" required>
                    <option value="">Select Sex</option>
                    <option value="M">Male</option>
                    <option value="F">Female</option>
                </select>
            </div>

            <!-- User Specific Fields -->
            <div class="form-group user-field" style="display: none;">
                <label for="editAccAcademicLevel">Academic Level:</label>
                <input type="text" id="editAccAcademicLevel" name="academic_level">
            </div>
            <div class="form-group user-field" style="display: none;">
                <label for="editAccProgramId">Program:</label>
                <select id="editAccProgramId" name="program_id">
                    <option value="">Select Program</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?php echo htmlspecialchars($program['program_id']); ?>">
                            <?php echo htmlspecialchars($program['program_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Admin Specific Fields -->
            <div class="form-group admin-field" style="display: none;">
                <label for="editAccWorkId">Work ID:</label>
                <input type="text" id="editAccWorkId" name="work_id">
            </div>
            <div class="form-group admin-field" style="display: none;">
                <label for="editAccPosition">Position:</label>
                <input type="text" id="editAccPosition" name="position">
            </div>
        </div>

        <!-- Buttons -->
        <div class="form-group full-width button-group">
            <button type="button" class="btn secondary-btn" onclick="closeEditAccountModal()">Cancel</button>
            <button type="submit" class="btn primary-btn">Save Changes</button>
        </div>
    </form>
</div>
</div>

<!-- View Account Modal -->
<div id="viewAccountModal" class="modal hidden">
    <div class="modal-content-view"> <!-- Smaller content area -->
        <span class="close-modal" onclick="closeViewAccountModal()">×</span>
        <h2>Account Details</h2>
        <div id="viewAccountInfo" class="view-info">
            <!-- Content populated by JS -->
            <p>Loading details...</p>
        </div>
        <div class="form-group full-width button-group" style="border-top: none; padding-top: 10px; margin-top: 15px;">
         <button type="button" class="btn secondary-btn" onclick="closeViewAccountModal()">Close</button>
     </div>
 </div>
</div>

<!-- Add Admin Modal -->
<div id="addAdminModal" class="modal hidden">
 <div class="modal-content-wide">
    <span class="close-modal" onclick="closeAddAdminModal()">×</span>
    <h2>Add New Admin Account</h2>
    <form id="addAdminForm">
        <div class="grid-container">
            <div class="form-group">
                <label for="addAdminUsername">Username:</label>
                <input type="text" id="addAdminUsername" name="username" required>
            </div>
            <div class="form-group">
                <label for="addAdminName">Name:</label>
                <input type="text" id="addAdminName" name="name" required>
            </div>
            <div class="form-group">
                <label for="addAdminPassword">Password:</label>
                <input type="password" id="addAdminPassword" name="password" required>
            </div>
            <div class="form-group">
                <label for="addAdminConfirmPassword">Confirm Password:</label>
                <input type="password" id="addAdminConfirmPassword" name="confirm_password" required>
            </div>
            <div class="form-group">
                <label for="addAdminSex">Sex:</label>
                <select id="addAdminSex" name="sex" required>
                    <option value="">Select Sex</option>
                    <option value="M">Male</option>
                    <option value="F">Female</option>
                </select>
            </div>
            <div class="form-group">
                <label for="addAdminWorkId">Work ID:</label>
                <input type="text" id="addAdminWorkId" name="work_id"> <!-- Optional? Check DB -->
            </div>
            <div class="form-group">
                <label for="addAdminPosition">Position:</label>
                <input type="text" id="addAdminPosition" name="position"> <!-- Optional? Check DB -->
            </div>
        </div>
        <!-- Buttons -->
        <div class="form-group full-width button-group">
            <button type="button" class="btn secondary-btn" onclick="closeAddAdminModal()">Cancel</button>
            <button type="submit" class="btn primary-btn">Add Admin</button>
        </div>
    </form>
</div>
</div>
</section>

<!-- Manage Abstracts Section (No changes here) -->
<section id="manage-abstracts" class="hidden" style="min-height: 80vh;">
 <h2>Manage Abstracts</h2>
 <!-- ... search, filter, add button, list, modal ... -->
 <div class="search-filter-container">
    <input type="text" id="search-bar-abstracts" placeholder="Search abstracts (title, researchers, citation)...">
    <select id="filter-abstract-type"> <!-- Changed ID -->
        <option value="">All Types</option>
        <option value="Thesis">Thesis</option>     <!-- Match DB Value -->
        <option value="Dissertation">Dissertation</option> <!-- Match DB Value -->
    </select>
    <button id="filter-button-abstracts">Filter</button>
</div>
<!-- Add Button -->
<button id="abstract-add" class="add-button-main" onclick="openAddAbstractModal()">
 <i class="fas fa-plus"></i> Add Abstract
</button>
<div id="abstracts-list" class="row-lay">
    <!-- Abstracts list populated by JS -->
    <p>Loading abstracts...</p>
</div>

<!-- Upload/Edit Abstract Modal -->
<div id="addEditAbstractModal" class="modal hidden"> <!-- Combined Add/Edit Modal -->
    <div class="modal-content-wide">
        <span class="close-modal" onclick="closeAddEditAbstractModal()">×</span>
        <h2 id="abstractModalTitle">Add Abstract</h2> <!-- Title changes for edit -->
        <form id="addEditAbstractForm" enctype="multipart/form-data">
         <input type="hidden" id="editAbstractId" name="abstract_id"> <!-- For editing -->

         <div class="grid-container">
            <div class="form-group">
                <label for="abstractTitle">Title:</label>
                <input type="text" id="abstractTitle" name="title" required>
            </div>
            <div class="form-group">
                <label for="abstractResearchers">Researchers:</label>
                <input type="text" id="abstractResearchers" name="researchers" required> <!-- Changed name -->
            </div>
            <div class="form-group">
                <label for="abstractCitation">Citation:</label>
                <input type="text" id="abstractCitation" name="citation" required>
            </div>
            <div class="form-group">
                <label for="abstractType">Abstract Type:</label>
                <select id="abstractType" name="abstract_type" required> <!-- Changed name -->
                    <option value="">Select Type</option>
                    <option value="Thesis">Thesis</option>
                    <option value="Dissertation">Dissertation</option>
                </select>
            </div>
            <div class="form-group program-field" style="display: none;"> <!-- Hide initially -->
                <label for="abstractProgramId">Program:</label>
                <select id="abstractProgramId" name="program_id"> <!-- Removed disabled -->
                    <option value="">Select Program</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?php echo htmlspecialchars($program['program_id']); ?>">
                            <?php echo htmlspecialchars($program['program_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group department-field" style="display: none;"> <!-- Hide initially -->
                <label for="abstractDepartmentId">Department:</label>
                <select id="abstractDepartmentId" name="department_id"> <!-- Removed disabled -->
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?php echo htmlspecialchars($department['department_id']); ?>">
                            <?php echo htmlspecialchars($department['department_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group full-width">
            <label for="abstractDescription">Description:</label>
            <textarea id="abstractDescription" name="description" required rows="4"></textarea> <!-- Changed to textarea -->
        </div>
        <div class="form-group full-width">
            <label for="abstractFile">Upload File (.pdf):</label>
            <input type="file" id="abstractFile" name="file" accept=".pdf"> <!-- Not required for edit -->
            <span id="currentFileName" style="font-size: 0.9em; margin-left: 10px;"></span> <!-- Show current file on edit -->
        </div>
        <!-- Buttons -->
        <div class="form-group full-width button-group">
            <button type="button" class="btn secondary-btn" onclick="closeAddEditAbstractModal()">Cancel</button>
            <button type="submit" class="btn primary-btn" id="abstractSubmitButton">Upload</button> <!-- Text changes for edit -->
        </div>
    </form>
</div>
</div>
</section>

<!-- Manage Programs Section (No changes here) -->
<section id="manage-programs" class="hidden" style="min-height: 80vh;">
 <h2>Manage Programs</h2>
 <!-- ... search, filter, add button, list, modals ... -->
 <!-- Filters -->
 <div class="search-filter-container">
     <input type="text" id="search-bar-programs" placeholder="Search programs by name/initials...">
     <select id="filter-program-department">
        <option value="">All Departments</option>
        <?php foreach ($departments as $dept): ?>
            <option value="<?php echo htmlspecialchars($dept['department_id']); ?>">
                <?php echo htmlspecialchars($dept['department_name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button id="filter-button-programs">Filter</button>
</div>
<!-- Add Button -->
<button class="add-button-main" onclick="openAddProgramModal()">
 <i class="fas fa-plus"></i> Add Program
</button>
<!-- List -->
<div id="programs-list" class="row-lay">
    <p>Loading programs...</p>
</div>

<!-- Add/Edit Program Modal -->
<div id="addEditProgramModal" class="modal hidden">
 <div class="modal-content-wide">
    <span class="close-modal" onclick="closeProgramModals()">×</span>
    <h2 id="programModalTitle">Add Program</h2>
    <form id="addEditProgramForm">
        <input type="hidden" id="editProgramId" name="program_id">
        <div class="grid-container">
         <div class="form-group">
            <label for="programName">Program Name:</label>
            <input type="text" id="programName" name="program_name" required>
        </div>
        <div class="form-group">
            <label for="programInitials">Program Initials:</label>
            <input type="text" id="programInitials" name="program_initials" required>
        </div>
        <div class="form-group full-width">
            <label for="programDepartmentId">Department:</label>
            <select id="programDepartmentId" name="department_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept['department_id']); ?>">
                        <?php echo htmlspecialchars($dept['department_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-group full-width button-group">
        <button type="button" class="btn secondary-btn" onclick="closeProgramModals()">Cancel</button>
        <button type="submit" class="btn primary-btn" id="programSubmitButton">Save Program</button>
    </div>
</form>
</div>
</div>

<!-- View Program Modal -->
<div id="viewProgramModal" class="modal hidden">
    <div class="modal-content-view">
        <span class="close-modal" onclick="closeProgramModals()">×</span>
        <h2>Program Details</h2>
        <div id="viewProgramInfo" class="view-info">
            <p>Loading details...</p>
        </div>
        <div class="form-group full-width button-group" style="border-top: none; padding-top: 10px; margin-top: 15px;">
         <button type="button" class="btn secondary-btn" onclick="closeProgramModals()">Close</button>
     </div>
 </div>
</div>
</section>

<!-- Manage Departments Section (No changes here) -->
<section id="manage-departments" class="hidden" style="min-height: 80vh;">
 <h2>Manage Departments</h2>
 <!-- ... search, filter, add button, list, modals ... -->
 <!-- Filters -->
 <div class="search-filter-container">
     <input type="text" id="search-bar-departments" placeholder="Search departments by name/initials...">
     <button id="filter-button-departments">Search</button>
 </div>
 <!-- Add Button -->
 <button class="add-button-main" onclick="openAddDepartmentModal()">
     <i class="fas fa-plus"></i> Add Department
 </button>
 <!-- List -->
 <div id="departments-list" class="row-lay">
    <p>Loading departments...</p>
</div>

<!-- Add/Edit Department Modal -->
<div id="addEditDepartmentModal" class="modal hidden">
    <div class="modal-content-wide">
        <span class="close-modal" onclick="closeDepartmentModals()">×</span>
        <h2 id="departmentModalTitle">Add Department</h2>
        <form id="addEditDepartmentForm">
            <input type="hidden" id="editDepartmentId" name="department_id">
            <div class="grid-container">
             <div class="form-group">
                <label for="departmentName">Department Name:</label>
                <input type="text" id="departmentName" name="department_name" required>
            </div>
            <div class="form-group">
                <label for="departmentInitials">Department Initials:</label>
                <input type="text" id="departmentInitials" name="department_initials"> <!-- Assuming optional, check DB -->
            </div>
        </div>
        <div class="form-group full-width button-group">
            <button type="button" class="btn secondary-btn" onclick="closeDepartmentModals()">Cancel</button>
            <button type="submit" class="btn primary-btn" id="departmentSubmitButton">Save Department</button>
        </div>
    </form>
</div>
</div>

<!-- View Department Modal -->
<div id="viewDepartmentModal" class="modal hidden">
 <div class="modal-content-view">
    <span class="close-modal" onclick="closeDepartmentModals()">×</span>
    <h2>Department Details</h2>
    <div id="viewDepartmentInfo" class="view-info">
        <p>Loading details...</p>
    </div>
    <div class="form-group full-width button-group" style="border-top: none; padding-top: 10px; margin-top: 15px;">
     <button type="button" class="btn secondary-btn" onclick="closeDepartmentModals()">Close</button>
 </div>
</div>
</div>
</section>

<!-- Settings Section -->
<section id="settings" class="hidden" style="min-height: 80vh;">
    <h1>Account Settings</h1>
    <div class="settings-container">

        <!-- Personal Details Section -->
        <div class="settings-section">
            <h3>Personal Details</h3>
            <div id="personalDetailsInfo">
                <p>Loading your details...</p>
                <!-- Details will be loaded here by JS -->
            </div>
        </div>

        <!-- Edit Profile Section -->
        <div class="settings-section">
            <h3>Edit Profile</h3>
            <form id="editMyProfileForm">
                <!-- Hidden field for the logged-in user ID -->
                <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($logged_in_admin_id); ?>">
                <div class="grid-container">
                    <div class="form-group">
                        <label for="myProfileUsername">Username:</label>
                        <input type="text" id="myProfileUsername" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="myProfileName">Name:</label>
                        <input type="text" id="myProfileName" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="myProfileSex">Sex:</label>
                        <select id="myProfileSex" name="sex" required>
                            <option value="">Select Sex</option>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                        </select>
                    </div>
                    <div class="form-group"> <!-- Admin specific -->
                        <label for="myProfileWorkId">Work ID:</label>
                        <input type="text" id="myProfileWorkId" name="work_id">
                    </div>
                    <div class="form-group"> <!-- Admin specific -->
                        <label for="myProfilePosition">Position:</label>
                        <input type="text" id="myProfilePosition" name="position">
                    </div>
                </div>
                <div class="settings-actions">
                    <button type="submit" class="btn primary-btn">Save Profile Changes</button>
                </div>
            </form>
        </div>

        <!-- Change Password Section -->
        <div class="settings-section">
            <h3>Change Password</h3>
            <form id="changeMyPasswordForm">
             <!-- Hidden field for the logged-in user ID -->
             <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($logged_in_admin_id); ?>">
             <div class="grid-container" style="grid-template-columns: 1fr;"> <!-- Single column layout -->
                 <div class="form-group">
                    <label for="currentPassword">Current Password:</label>
                    <input type="password" id="currentPassword" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="newPassword">New Password:</label>
                    <input type="password" id="newPassword" name="new_password" required autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="confirmNewPassword">Confirm New Password:</label>
                    <input type="password" id="confirmNewPassword" name="confirm_new_password" required autocomplete="new-password">
                </div>
            </div>
            <div class="settings-actions">
             <button type="submit" class="btn primary-btn">Update Password</button>
         </div>
     </form>
 </div>

 <!-- Logout and Delete Account Section -->
 <div class="settings-section danger-zone" style="margin-bottom: 40px; padding-bottom: 20px;">
    <h3>Account Actions</h3>
    <p>Be careful with these actions.</p>
    <div class="settings-actions" style="text-align: left;"> <!-- Align buttons left in this section -->
        <button type="button" id="logoutButton" class="btn secondary-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
        <button type="button" id="deleteMyAccountButton" class="btn danger-btn"><i class="fas fa-trash-alt"></i> Delete My Account</button>
    </div>
</div>

</div>
</section>

<!-- About Section -->
<section id="about" class="hidden" style="min-height: 80vh;">
    <h1>About</h1>

    <!-- About Project -->
    <div class="about-section">
        <h2>About Our Project: EASY</h2>
        <!-- ... content unchanged ... -->
        <p> The Electronic Abstract System (EASY) is designed to streamline the management, submission, and retrieval of academic abstracts, specifically theses and dissertations, within an educational institution. Our goal is to provide a centralized, searchable, and user-friendly platform for both students/users and administrators. </p> <p> Key features include secure user authentication, role-based access control (Users vs. Admins), categorization of abstracts by type (Thesis/Dissertation) and associated programs/departments, robust search and filtering capabilities, and efficient management tools for administrators. </p>
    </div>

    <!-- Our Team -->
    <div class="about-section">
        <h2>Our Team</h2>

        <!-- Lead Card -->
        <div class="team-card-lead">
            <img src="assets/gallego.jpg" alt="Lead Name"> <!-- Replace with actual image path -->
            <h3>James Ryan S. Gallego</h3>
            <p>Project Lead / Lead Developer</p>
        </div>

        <!-- Member Cards Container -->
        <div class="team-cards-members-container">
            <div class="team-card">
                <img src="images/placeholder_avatar.png" alt="Member 1 Name"> <!-- Replace -->
                <h3>Aubrey Rose C. Baluyo</h3> <p>Frontend Developer</p>
            </div>
            <div class="team-card">
                <img src="images/placeholder_avatar.png" alt="Member 2 Name"> <!-- Replace -->
                <h3>Jay P. Bayrante</h3> <p>Backend Developer</p>
            </div>
            <div class="team-card">
                <img src="images/placeholder_avatar.png" alt="Member 3 Name"> <!-- Replace -->
                <h3>Kimberly Guevara</h3> <p>Database Administrator</p>
            </div>
            <div class="team-card">
                <img src="images/placeholder_avatar.png" alt="Member 4 Name"> <!-- Replace -->
                <h3>Bell Anton P. Mahometano</h3> <p>UI/UX Designer</p>
            </div>
            <div class="team-card">
                <img src="images/placeholder_avatar.png" alt="Member 5 Name"> <!-- Replace -->
                <h3>Chris Vincent P. Payte</h3> <p>QA Tester</p>
            </div>
            <div class="team-card">
                <img src="images/placeholder_avatar.png" alt="Member 6 Name"> <!-- Replace -->
                <h3>Aubrey Kate M. Pinto</h3> <p>Documentation Specialist</p>
            </div>
        </div>
    </div>

    <!-- Changelog -->
    <div class="about-section">
        <h2>Changelogs</h2>
        <!-- ... content unchanged ... -->
        <ul class="changelog-list"> <li> <strong>v1.1.0 (2025-04-04)</strong> Added User Settings page allowing profile and password updates.<br> Added Account Deletion option for users.<br> Added "About" section with project info, team details, and changelog. </li> <li> <strong>v1.0.1 (2025-02-26)</strong> Implemented search and filtering for Thesis and Dissertation abstracts.<br> Fixed minor display bugs on the Home section. </li> <li> <strong>v1.0.0 (2025-02-25)</strong> Initial release: User login, Home dashboard displaying abstract counts and recent theses, basic viewing and downloading of abstracts. </li> </ul>
    </div>

</section>

<!-- Loading Overlay/Modal -->
<div id="loadingOverlay" class="modal hidden" style="z-index: 1050; background-color: rgba(0, 0, 0, 0.6);"> <!-- Higher z-index -->
    <div style="background-color: white; padding: 25px 40px; border-radius: 8px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom: 15px; color: #007bff;"></i>
        <p style="margin: 0; font-size: 1.1em; color: #333;">Processing Abstract...</p>
        <p style="margin: 5px 0 0 0; font-size: 0.9em; color: #666;">Please wait, this may take a moment.</p>
    </div>
</div>

</main>

<!-- Footer or other elements -->

<script>
    const loggedInAdminId = <?php echo json_encode($logged_in_admin_id); ?>;
    // Optional: Pass initial admin details if needed, otherwise fetch via API
    // const initialAdminDetails = <?php //echo json_encode($current_admin_details ?: null); ?>;

    // --- Existing JS below ---
    // Sidebar Toggle Function
    function toggleMenu() {
        // ... (keep existing toggleMenu function)
        const sidebar = document.getElementById('sidebar');
        const shadow = document.getElementById('shadow');
        const body = document.body; // Get body element

        if (sidebar && shadow) { // Check if elements exist
            const isHidden = sidebar.classList.contains('hidden');
            if (isHidden) {
                // Show sidebar
                sidebar.classList.remove('hidden');
                shadow.classList.remove('hidden');
                body.classList.remove('sidebar-hidden'); // For content margin adjustment
            } else {
                // Hide sidebar
                sidebar.classList.add('hidden');
                shadow.classList.add('hidden');
                body.classList.add('sidebar-hidden'); // For content margin adjustment
            }
        } else {
            console.error("Sidebar or shadow element not found!");
        }
    }


    // Section Switching Logic (UPDATED)
    document.querySelectorAll('.sidebar nav ul li a:not(.logout)').forEach(link => { // Removed :not(.logout) as it's gone
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-target');
            if (!targetId) return;

            document.querySelectorAll('.content > section').forEach(section => {
                section.classList.add('hidden');
                section.classList.remove('active');
            });
            document.querySelectorAll('.sidebar nav ul li a').forEach(a => a.classList.remove('active'));

            const targetSection = document.getElementById(targetId);
            if (targetSection) {
                targetSection.classList.remove('hidden');
                targetSection.classList.add('active');
                this.classList.add('active');

                // --- Trigger Fetch for Newly Active Section ---
                switch(targetId) {
                case 'manage-accounts': fetchAccounts(); break;
                case 'manage-abstracts': fetchAbstracts(); break;
                case 'manage-programs': fetchPrograms(); break;
                case 'manage-departments': fetchDepartments(); break;
                    case 'settings': fetchMyDetails(); break; // Fetch details for settings page
                    }
                }
                if (window.innerWidth < 768) { toggleMenu(); }
            });
    });

    // --- Generic Fetch/Post Functions --- (Keep existing fetchData and postData)
     // --- Generic Fetch Function ---
    function fetchData(url, callback, errorCallback) {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.error) {
                            console.error("API Error:", data.error);
                            if(errorCallback) errorCallback(data.error);
                            else alert("Error: " + data.error);
                        } else {
                            callback(data);
                        }
                    } catch (e) {
                        console.error("Parse Error:", e, xhr.responseText);
                        if(errorCallback) errorCallback("Error parsing server response.");
                        else alert("Error parsing server response.");
                    }
                } else {
                    console.error("HTTP Error:", xhr.status, xhr.statusText);
                    if(errorCallback) errorCallback(`Server error: ${xhr.status}`);
                    else alert(`Server error: ${xhr.status}`);
                }
            }
        };
        xhr.onerror = function() {
            console.error("Network Error");
            if(errorCallback) errorCallback("Network error.");
            else alert("Network error.");
        };
        xhr.send();
    }

     // --- Generic Post Function ---
    function postData(url, data, callback, errorCallback) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);

        const body = (data instanceof FormData) ? data : new URLSearchParams(data);
        if (!(data instanceof FormData)) {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status >= 200 && xhr.status < 300) { // Accept 2xx
                    try {
                        if (!xhr.responseText) { // Handle empty response (e.g., 204 No Content)
                            callback({});
                            return;
                        }
                        const response = JSON.parse(xhr.responseText);
                        if (response.error) {
                            console.error("API Error (within 2xx):", response.error);
                            if(errorCallback) errorCallback(response.error);
                            else alert("Error: " + response.error);
                        } else {
                            callback(response);
                        }
                    } catch (e) {
                        console.error("Parse Error (2xx):", e, xhr.responseText);
                        if(errorCallback) errorCallback("Error parsing server response.");
                        else alert("Error parsing server response.");
                    }
                } else { // Handle non-2xx errors
                    let errorMsg = `Server responded with status ${xhr.status}`;
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse && errorResponse.error) {
                            errorMsg = errorResponse.error;
                        }
                    } catch (e) { /* Ignore if error response is not JSON */ }
                        console.error("HTTP Error:", xhr.status, xhr.statusText, xhr.responseText);
                        if(errorCallback) errorCallback(errorMsg);
                        else alert(errorMsg);
                    }
                }
            };
            xhr.onerror = function() {
                console.error("Network Error");
                const networkErrorMsg = "Network error.";
                if(errorCallback) errorCallback(networkErrorMsg);
                else alert(networkErrorMsg);
            };
            xhr.send(body);
        }

    // --- Manage Accounts JS --- (Keep existing functions: fetchAccounts, openEditAccountModal, etc.)
    // Make sure the delete listener correctly identifies the loggedInAdminId
        const accountSearchInput = document.getElementById('search-bar-accounts');
        const accountTypeFilter = document.getElementById('filter-account-type');
        const programFilter = document.getElementById('filter-program');
        const accountSortSelect = document.getElementById('sort-accounts-by');
        const accountFilterButton = document.getElementById('filter-button-accounts');
        const accountsListContainer = document.getElementById('accounts-list');
        const editAccountModal = document.getElementById('editAccountModal');
        const editAccountForm = document.getElementById('editAccountForm');
    const viewAccountModal = document.getElementById('viewAccountModal'); // Get View Modal
    const viewAccountInfo = document.getElementById('viewAccountInfo'); // Get View Modal content area
    const addAdminModal = document.getElementById('addAdminModal'); // Get Add Admin Modal
    const addAdminForm = document.getElementById('addAdminForm'); // Get Add Admin Form

    function fetchAccounts() {
        const searchTerm = accountSearchInput.value;
        const accountType = accountTypeFilter.value;
        const programId = programFilter.value;
        const sortBy = accountSortSelect.value;

        accountsListContainer.innerHTML = '<p>Loading accounts...</p>'; // Show loading state

        const url = `api-admin/fetch_accounts.php?search=${encodeURIComponent(searchTerm)}&type=${encodeURIComponent(accountType)}&program=${encodeURIComponent(programId)}&sort=${encodeURIComponent(sortBy)}`; // Updated API endpoint

        fetchData(url, (accounts) => {
            accountsListContainer.innerHTML = ''; // Clear loading/previous
            if (accounts.length === 0) {
                accountsListContainer.innerHTML = '<p>No accounts found matching criteria.</p>';
                return;
            }
            accounts.forEach(acc => {
                const row = document.createElement('div');
                row.classList.add('user-row');
                // --- Prevent showing delete for the logged-in admin ---
                const isCurrentUser = parseInt(acc.account_id) === parseInt(loggedInAdminId);
                const deleteOption = isCurrentUser
                    ? `<li style="color: grey; cursor: not-allowed;" title="Cannot delete yourself">Delete</li>` // Disabled delete option
                    : `<li class="delete-account" data-id="${acc.account_id}" data-name="${acc.username}">Delete</li>`;

                    row.innerHTML = `
                    <div class="user-info">
                        <p class="name"><strong>${acc.name}</strong> (${acc.account_type})</p>
                        <p>Username: ${acc.username}</p>
                        ${acc.program_name ? `<p>Program: ${acc.program_name}</p>` : ''}
                        ${acc.position ? `<p>Position: ${acc.position}</p>` : ''}
                    </div>
                    <div class="menu">
                        <i class="fas fa-ellipsis-vertical" onclick="showMenu(this)"></i>
                        <ul class="menu-options hidden">
                            <li onclick="openEditAccountModal(${acc.account_id})">Edit</li>
                            <li onclick="openViewAccountModal(${acc.account_id})">View</li>
                            ${deleteOption}
                        </ul>
                    </div>
                    `;
                    accountsListContainer.appendChild(row);
                });
        }, (errorMsg) => {
         accountsListContainer.innerHTML = `<p style="color: red;">Error loading accounts: ${errorMsg}</p>`;
     });
    }

    if(accountFilterButton) { accountFilterButton.addEventListener('click', fetchAccounts); }

    function openEditAccountModal(accountId) {
     const url = `api-admin/get_account.php?account_id=${encodeURIComponent(accountId)}`;
     fetchData(url, (account) => {
         if (!account) { alert("Could not retrieve account details."); return; }

         document.getElementById('editAccountId').value = account.account_id;
         document.getElementById('editAccountType').value = account.account_type;
         document.getElementById('editAccUsername').value = account.username;
         document.getElementById('editAccName').value = account.name;
         document.getElementById('editAccSex').value = account.sex || '';

         const userFields = editAccountModal.querySelectorAll('.user-field');
         const adminFields = editAccountModal.querySelectorAll('.admin-field');

         if (account.account_type === 'User') {
             userFields.forEach(f => f.style.display = 'block');
             adminFields.forEach(f => f.style.display = 'none');
             document.getElementById('editAccAcademicLevel').value = account.academic_level || '';
             document.getElementById('editAccProgramId').value = account.program_id || '';
             document.getElementById('editAccWorkId').value = '';
             document.getElementById('editAccPosition').value = '';
         } else if (account.account_type === 'Admin') {
             userFields.forEach(f => f.style.display = 'none');
             adminFields.forEach(f => f.style.display = 'block');
             document.getElementById('editAccWorkId').value = account.work_id || '';
             document.getElementById('editAccPosition').value = account.position || '';
             document.getElementById('editAccAcademicLevel').value = '';
             document.getElementById('editAccProgramId').value = '';
         } else {
          userFields.forEach(f => f.style.display = 'none');
          adminFields.forEach(f => f.style.display = 'none');
      }
      editAccountModal.classList.remove('hidden');
  }, (errorMsg) => { alert(`Error fetching account details: ${errorMsg}`); });
 }

 function closeEditAccountModal() {
    if(editAccountModal) editAccountModal.classList.add('hidden');
    if(editAccountForm) editAccountForm.reset();
}

if(editAccountForm) {
    editAccountForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const accountType = document.getElementById('editAccountType').value;

        if(accountType === 'User') {
            formData.delete('work_id');
            formData.delete('position');
        } else if (accountType === 'Admin') {
            formData.delete('academic_level');
            formData.delete('program_id');
        }
             // Ensure account_id is sent
        if (!formData.has('account_id') || !formData.get('account_id')) {
         alert('Error: Account ID is missing.');
                 return; // Prevent submission without ID
             }

             const url = 'api-admin/update_account.php';
             postData(url, formData, (response) => {
                alert(response.success || "Account updated.");
                closeEditAccountModal();
                fetchAccounts(); // Refresh list
                 // If the updated account is the current admin, refresh settings too
                if (parseInt(formData.get('account_id')) === parseInt(loggedInAdminId)) {
                    fetchMyDetails(); // Update the settings view
                }
            }, (errorMsg) => { alert(`Error updating account: ${errorMsg}`); });
         });
}

     // Handle Account Deletion (Listener on the container)
if(accountsListContainer) {
    accountsListContainer.addEventListener('click', function(e) {
     if (e.target.classList.contains('delete-account')) {
        const accountId = e.target.getAttribute('data-id');
        const accountName = e.target.getAttribute('data-name');

                // Double check - although button should be disabled, check ID again
        if (parseInt(accountId) === parseInt(loggedInAdminId)) {
            alert("You cannot delete your own account from this management list. Please use the Settings section.");
            return;
        }

        if (confirm(`Are you sure you want to delete account: ${accountName} (ID: ${accountId})?`)) {
            const url = 'api-admin/delete_account.php';
            const data = { account_id: accountId };
            postData(url, data, (response) => {
                alert(response.success || "Account deleted.");
                        fetchAccounts(); // Refresh list
                    }, (errorMsg) => { alert(`Error deleting account: ${errorMsg}`); });
        }
    }
});
}

function openViewAccountModal(accountId) {
        viewAccountInfo.innerHTML = '<p>Loading details...</p>'; // Show loading state
        viewAccountModal.classList.remove('hidden');
        const url = `api-admin/get_account.php?account_id=${encodeURIComponent(accountId)}`;
        fetchData(url, (account) => {
            if (!account) {
                viewAccountInfo.innerHTML = '<p style="color: red;">Error: Could not retrieve account details.</p>';
                return;
            }
            let detailsHtml = `
                <p><strong>Account ID:</strong> ${account.account_id}</p>
                <p><strong>Username:</strong> ${account.username}</p>
                <p><strong>Name:</strong> ${account.name}</p>
                <p><strong>Sex:</strong> ${account.sex === 'M' ? 'Male' : (account.sex === 'F' ? 'Female' : 'N/A')}</p>
                <p><strong>Account Type:</strong> ${account.account_type}</p>
            `;
            if (account.account_type === 'User') {
                detailsHtml += `
                    <p><strong>Academic Level:</strong> ${account.academic_level || 'N/A'}</p>
                    <p><strong>Program:</strong> ${account.program_name || 'N/A'} (ID: ${account.program_id || 'N/A'})</p>
                `;
            } else if (account.account_type === 'Admin') {
             detailsHtml += `
                    <p><strong>Work ID:</strong> ${account.work_id || 'N/A'}</p>
                    <p><strong>Position:</strong> ${account.position || 'N/A'}</p>
             `;
         }
         viewAccountInfo.innerHTML = detailsHtml;
     }, (errorMsg) => {
        viewAccountInfo.innerHTML = `<p style="color: red;">Error loading details: ${errorMsg}</p>`;
    });
    }
    function closeViewAccountModal() {
        if (viewAccountModal) viewAccountModal.classList.add('hidden');
    }

    function openAddAdminModal() {
        if(addAdminForm) addAdminForm.reset();
        if(addAdminModal) addAdminModal.classList.remove('hidden');
    }
    function closeAddAdminModal() {
        if (addAdminModal) addAdminModal.classList.add('hidden');
    }
    if (addAdminForm) {
        addAdminForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm_password');

            if (password !== confirmPassword) {
                alert("Passwords do not match!");
                return;
            }
            formData.append('account_type', 'Admin');

            const url = 'api-admin/add_admin.php';
            postData(url, formData, (response) => {
                alert(response.success || "Admin account added successfully.");
                closeAddAdminModal();
                fetchAccounts(); // Refresh list
            }, (errorMsg) => { alert(`Error adding admin: ${errorMsg}`); });
        });
    }


    // --- Manage Abstracts JS --- (Keep existing functions: fetchAbstracts, openAdd/EditAbstractModal, etc.)
    const abstractSearchInput = document.getElementById('search-bar-abstracts');
    const abstractTypeFilter = document.getElementById('filter-abstract-type');
    const abstractFilterButton = document.getElementById('filter-button-abstracts');
    const abstractsListContainer = document.getElementById('abstracts-list');
    const addAbstractButton = document.getElementById('abstract-add');
    const addEditAbstractModal = document.getElementById('addEditAbstractModal');
    const addEditAbstractForm = document.getElementById('addEditAbstractForm');
    const abstractModalTitle = document.getElementById('abstractModalTitle');
    const abstractSubmitButton = document.getElementById('abstractSubmitButton');
    const abstractTypeSelect = document.getElementById('abstractType'); // The select in the modal
    const abstractProgramField = addEditAbstractModal.querySelector('.program-field');
    const abstractDepartmentField = addEditAbstractModal.querySelector('.department-field');
    const currentFileNameSpan = document.getElementById('currentFileName');
    const abstractFileInput = document.getElementById('abstractFile');

    function fetchAbstracts() {
        const searchTerm = abstractSearchInput.value;
        const filterType = abstractTypeFilter.value;
        abstractsListContainer.innerHTML = '<p>Loading abstracts...</p>';
        const url = `api-admin/fetch_abstracts.php?search=${encodeURIComponent(searchTerm)}&filterByType=${encodeURIComponent(filterType)}`;

        fetchData(url, (abstracts) => {
            abstractsListContainer.innerHTML = ''; // Clear
            if (abstracts.length === 0) {
                abstractsListContainer.innerHTML = '<p>No abstracts found matching criteria.</p>';
                return;
            }
            abstracts.forEach(abs => {
             const row = document.createElement('div');
             row.classList.add('user-row');
             row.innerHTML = `
                    <div class="user-info">
                        <p class="title"><strong>${abs.title}</strong> (${abs.abstract_type})</p>
                        <p>Researchers: ${abs.researchers || 'N/A'}</p>
                 ${abs.related_entity_name ? `<p>Related: ${abs.related_entity_name}</p>` : ''}
                        <p>Citation: ${abs.citation || 'N/A'}</p>
                    </div>
                    <div class="menu">
                        <i class="fas fa-ellipsis-vertical" onclick="showMenu(this)"></i>
                        <ul class="menu-options hidden">
                            <li onclick="openEditAbstractModal(${abs.abstract_id})">Edit</li>
                            <li onclick="viewAbstract(${abs.abstract_id})">View</li>
                            <li onclick="downloadAbstract(${abs.abstract_id})">Download</li>
                            <li class="delete-abstract" data-id="${abs.abstract_id}" data-title="${abs.title}">Delete</li>
                        </ul>
                    </div>
             `;
             abstractsListContainer.appendChild(row);
         });
        }, (errorMsg) => {
            abstractsListContainer.innerHTML = `<p style="color: red;">Error loading abstracts: ${errorMsg}</p>`;
        });
    }
    if(abstractFilterButton) { abstractFilterButton.addEventListener('click', fetchAbstracts); }
    function openAddAbstractModal() {
     addEditAbstractForm.reset();
     document.getElementById('editAbstractId').value = '';
     abstractModalTitle.textContent = 'Add New Abstract';
     abstractSubmitButton.textContent = 'Upload';
     currentFileNameSpan.textContent = '';
     abstractFileInput.required = true;
     toggleAbstractFields();
     addEditAbstractModal.classList.remove('hidden');
 }
 function openEditAbstractModal(abstractId) {
    addEditAbstractForm.reset();
    const url = `api-admin/get_abstract.php?abstract_id=${encodeURIComponent(abstractId)}`;
    fetchData(url, (abstract) => {
        if(!abstract) { alert("Could not retrieve abstract details."); return; }
        document.getElementById('editAbstractId').value = abstract.abstract_id;
        document.getElementById('abstractTitle').value = abstract.title || '';
        document.getElementById('abstractResearchers').value = abstract.researchers || '';
        document.getElementById('abstractCitation').value = abstract.citation || '';
        document.getElementById('abstractType').value = abstract.abstract_type || '';
        document.getElementById('abstractDescription').value = abstract.description || '';
        document.getElementById('abstractProgramId').value = abstract.program_id || '';
        document.getElementById('abstractDepartmentId').value = abstract.department_id || '';
        currentFileNameSpan.textContent = abstract.file_name ? `Current: ${abstract.file_name}` : 'No file uploaded or info unavailable';
        abstractFileInput.required = false;
        toggleAbstractFields();
        abstractModalTitle.textContent = 'Edit Abstract';
        abstractSubmitButton.textContent = 'Save Changes';
        addEditAbstractModal.classList.remove('hidden');
    }, (errorMsg) => { alert(`Error fetching abstract details: ${errorMsg}`); });
}
function closeAddEditAbstractModal() {
    if(addEditAbstractModal) addEditAbstractModal.classList.add('hidden');
    if(addEditAbstractForm) addEditAbstractForm.reset();
    currentFileNameSpan.textContent = '';
    if(abstractProgramField) abstractProgramField.style.display = 'none';
    if(abstractDepartmentField) abstractDepartmentField.style.display = 'none';
}
function toggleAbstractFields() {
 const type = abstractTypeSelect.value;
 if (type === 'Thesis') {
     if(abstractProgramField) abstractProgramField.style.display = 'block';
     if(abstractDepartmentField) abstractDepartmentField.style.display = 'none';
     document.getElementById('abstractDepartmentId').value = '';
 } else if (type === 'Dissertation') {
    if(abstractProgramField) abstractProgramField.style.display = 'none';
    if(abstractDepartmentField) abstractDepartmentField.style.display = 'block';
    document.getElementById('abstractProgramId').value = '';
} else {
    if(abstractProgramField) abstractProgramField.style.display = 'none';
    if(abstractDepartmentField) abstractDepartmentField.style.display = 'none';
    document.getElementById('abstractDepartmentId').value = '';
    document.getElementById('abstractProgramId').value = '';
}
}
if(abstractTypeSelect) { abstractTypeSelect.addEventListener('change', toggleAbstractFields); }
if(addAbstractButton) { addAbstractButton.addEventListener('click', openAddAbstractModal); }

if(addEditAbstractForm) {
    addEditAbstractForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const abstractId = formData.get('abstract_id');
        const abstractType = formData.get('abstract_type');

        if (abstractType === 'Thesis' && !formData.get('program_id')) { alert('Please select a Program for a Thesis.'); return; }
        if (abstractType === 'Dissertation' && !formData.get('department_id')) { alert('Please select a Department for a Dissertation.'); return; }
        if (abstractType === 'Thesis') formData.delete('department_id');
        if (abstractType === 'Dissertation') formData.delete('program_id');

        const url = abstractId ? 'api-admin/update_abstract.php' : 'api-admin/upload_abstract.php';

        if (loadingOverlay) {
                loadingOverlay.classList.remove('hidden'); // Show the overlay
                // Or use: loadingOverlay.style.display = 'flex'; if you didn't add the hidden class CSS
            }

            postData(url, formData, (response) => {
               if (loadingOverlay) loadingOverlay.classList.add('hidden');
               alert(response.success || `Abstract ${abstractId ? 'updated' : 'added'}.`);
               closeAddEditAbstractModal();
               fetchAbstracts();
           }, (errorMsg) => { alert(`Error: ${errorMsg}`);if (loadingOverlay) loadingOverlay.classList.add('hidden'); });
        });
}


if(abstractsListContainer) {
    abstractsListContainer.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-abstract')) {
            const abstractId = e.target.getAttribute('data-id');
            const abstractTitle = e.target.getAttribute('data-title');
            if (confirm(`Are you sure you want to delete abstract: "${abstractTitle}" (ID: ${abstractId})?`)) {
                const url = 'api-admin/delete_abstract.php';
                const data = { abstract_id: abstractId };
                postData(url, data, (response) => {
                 alert(response.success || "Abstract deleted.");
                 fetchAbstracts();
             }, (errorMsg) => { alert(`Error deleting abstract: ${errorMsg}`); });
            }
        }
    });
}
function viewAbstract(abstractId) {
    const viewUrl = `api-general/view_abstract.php?id=${encodeURIComponent(abstractId)}`;
    window.open(viewUrl, '_blank');
}
function downloadAbstract(abstractId) {
    window.location.href = `api-general/download_abstract.php?id=${encodeURIComponent(abstractId)}`;
}
if(abstractFilterButton) { abstractFilterButton.addEventListener('click', fetchAbstracts); }


    // --- Manage Programs JS --- (Keep existing functions: fetchPrograms, openAdd/EditProgramModal, etc.)
const programSearchInput = document.getElementById('search-bar-programs');
const programDeptFilter = document.getElementById('filter-program-department');
const programFilterButton = document.getElementById('filter-button-programs');
const programsListContainer = document.getElementById('programs-list');
const addEditProgramModal = document.getElementById('addEditProgramModal');
const addEditProgramForm = document.getElementById('addEditProgramForm');
const viewProgramModal = document.getElementById('viewProgramModal');
const viewProgramInfo = document.getElementById('viewProgramInfo');
const programModalTitle = document.getElementById('programModalTitle');
const programSubmitButton = document.getElementById('programSubmitButton');

function fetchPrograms() {
    const searchTerm = programSearchInput.value;
    const departmentId = programDeptFilter.value;
    programsListContainer.innerHTML = '<p>Loading programs...</p>';
    const url = `api-admin/fetch_programs.php?search=${encodeURIComponent(searchTerm)}&department_id=${encodeURIComponent(departmentId)}`;

    fetchData(url, (programs) => {
        programsListContainer.innerHTML = '';
        if (programs.length === 0) { programsListContainer.innerHTML = '<p>No programs found matching criteria.</p>'; return; }
        programs.forEach(prog => {
            const row = document.createElement('div');
            row.classList.add('user-row');
            row.innerHTML = `
                    <div class="user-info">
                        <p class="name"><strong>${prog.program_name}</strong> (${prog.program_initials || 'N/A'})</p>
                        <p>Department: ${prog.department_name || 'N/A'}</p>
                    </div>
                    <div class="menu">
                        <i class="fas fa-ellipsis-vertical" onclick="showMenu(this)"></i>
                        <ul class="menu-options hidden">
                            <li onclick="openEditProgramModal(${prog.program_id})">Edit</li>
                            <li onclick="openViewProgramModal(${prog.program_id})">View</li>
                            <li class="delete-program" data-id="${prog.program_id}" data-name="${prog.program_name}">Delete</li>
                        </ul>
                    </div>
            `;
            programsListContainer.appendChild(row);
        });
    }, (errorMsg) => { programsListContainer.innerHTML = `<p style="color: red;">Error loading programs: ${errorMsg}</p>`; });
}
function openAddProgramModal() {
    addEditProgramForm.reset();
    document.getElementById('editProgramId').value = '';
    programModalTitle.textContent = 'Add New Program';
    programSubmitButton.textContent = 'Add Program';
    addEditProgramModal.classList.remove('hidden');
}
function openEditProgramModal(programId) {
    addEditProgramForm.reset();
    const url = `api-admin/get_program.php?program_id=${programId}`;
    fetchData(url, (program) => {
        if (!program) { alert("Could not retrieve program details."); return; }
        document.getElementById('editProgramId').value = program.program_id;
        document.getElementById('programName').value = program.program_name || '';
        document.getElementById('programInitials').value = program.program_initials || '';
        document.getElementById('programDepartmentId').value = program.department_id || '';
        programModalTitle.textContent = 'Edit Program';
        programSubmitButton.textContent = 'Save Changes';
        addEditProgramModal.classList.remove('hidden');
    }, (errorMsg) => { alert(`Error fetching details: ${errorMsg}`); });
}
function openViewProgramModal(programId) {
    viewProgramInfo.innerHTML = '<p>Loading details...</p>';
    viewProgramModal.classList.remove('hidden');
    const url = `api-admin/get_program.php?program_id=${programId}`;
    fetchData(url, (program) => {
        if (!program) { viewProgramInfo.innerHTML = '<p style="color: red;">Error loading details.</p>'; return; }
        viewProgramInfo.innerHTML = `
                <p><strong>Program ID:</strong> ${program.program_id}</p>
                <p><strong>Name:</strong> ${program.program_name}</p>
                <p><strong>Initials:</strong> ${program.program_initials || 'N/A'}</p>
                <p><strong>Department:</strong> ${program.department_name || 'N/A'} (ID: ${program.department_id})</p>
        `;
    }, (errorMsg) => { viewProgramInfo.innerHTML = `<p style="color: red;">Error loading details: ${errorMsg}</p>`; });
}
function closeProgramModals() {
    if(addEditProgramModal) addEditProgramModal.classList.add('hidden');
    if(viewProgramModal) viewProgramModal.classList.add('hidden');
}
if(addEditProgramForm) {
    addEditProgramForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const programId = formData.get('program_id');
        const url = programId ? 'api-admin/update_program.php' : 'api-admin/add_program.php';

        postData(url, formData, (response) => {
            alert(response.success || `Program ${programId ? 'updated' : 'added'}.`);
            closeProgramModals();
            fetchPrograms();
        }, (errorMsg) => { alert(`Error: ${errorMsg}`); });
    });
}
if(programsListContainer) {
    programsListContainer.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-program')) {
            const programId = e.target.getAttribute('data-id');
            const programName = e.target.getAttribute('data-name');
            if (confirm(`Are you sure you want to delete program: "${programName}"? This might fail if abstracts or users are associated with it.`)) {
                const url = 'api-admin/delete_program.php';
                postData(url, { program_id: programId }, (response) => {
                    alert(response.success || "Program deleted.");
                    fetchPrograms();
                }, (errorMsg) => { alert(`Error deleting program: ${errorMsg}`); });
            }
        }
    });
}
if(programFilterButton) { programFilterButton.addEventListener('click', fetchPrograms); }


    // --- Manage Departments JS --- (Keep existing functions: fetchDepartments, openAdd/EditDepartmentModal, etc.)
const deptSearchInput = document.getElementById('search-bar-departments');
const deptFilterButton = document.getElementById('filter-button-departments');
const deptsListContainer = document.getElementById('departments-list');
const addEditDeptModal = document.getElementById('addEditDepartmentModal');
const addEditDeptForm = document.getElementById('addEditDepartmentForm');
const viewDeptModal = document.getElementById('viewDepartmentModal');
const viewDeptInfo = document.getElementById('viewDepartmentInfo');
const deptModalTitle = document.getElementById('departmentModalTitle');
const deptSubmitButton = document.getElementById('departmentSubmitButton');

function fetchDepartments() {
    const searchTerm = deptSearchInput.value;
    deptsListContainer.innerHTML = '<p>Loading departments...</p>';
    const url = `api-admin/fetch_departments.php?search=${encodeURIComponent(searchTerm)}`;

    fetchData(url, (departments) => {
        deptsListContainer.innerHTML = '';
        if (departments.length === 0) { deptsListContainer.innerHTML = '<p>No departments found.</p>'; return; }
        departments.forEach(dept => {
         const row = document.createElement('div');
         row.classList.add('user-row');
         row.innerHTML = `
                    <div class="user-info">
                        <p class="name"><strong>${dept.department_name}</strong> (${dept.department_initials || 'N/A'})</p>
                    </div>
                    <div class="menu">
                        <i class="fas fa-ellipsis-vertical" onclick="showMenu(this)"></i>
                        <ul class="menu-options hidden">
                            <li onclick="openEditDepartmentModal(${dept.department_id})">Edit</li>
                            <li onclick="openViewDepartmentModal(${dept.department_id})">View</li>
                            <li class="delete-department" data-id="${dept.department_id}" data-name="${dept.department_name}">Delete</li>
                        </ul>
                    </div>
         `;
         deptsListContainer.appendChild(row);
     });
    }, (errorMsg) => { deptsListContainer.innerHTML = `<p style="color: red;">Error loading departments: ${errorMsg}</p>`; });
}
function openAddDepartmentModal() {
    addEditDeptForm.reset();
    document.getElementById('editDepartmentId').value = '';
    deptModalTitle.textContent = 'Add New Department';
    deptSubmitButton.textContent = 'Add Department';
    addEditDeptModal.classList.remove('hidden');
}
function openEditDepartmentModal(departmentId) {
    addEditDeptForm.reset();
    const url = `api-admin/get_department.php?department_id=${departmentId}`;
    fetchData(url, (dept) => {
     if (!dept) { alert("Could not retrieve department details."); return; }
     document.getElementById('editDepartmentId').value = dept.department_id;
     document.getElementById('departmentName').value = dept.department_name || '';
     document.getElementById('departmentInitials').value = dept.department_initials || '';
     deptModalTitle.textContent = 'Edit Department';
     deptSubmitButton.textContent = 'Save Changes';
     addEditDeptModal.classList.remove('hidden');
 }, (errorMsg) => { alert(`Error fetching details: ${errorMsg}`); });
}
function openViewDepartmentModal(departmentId) {
    viewDeptInfo.innerHTML = '<p>Loading details...</p>';
    viewDeptModal.classList.remove('hidden');
    const url = `api-admin/get_department.php?department_id=${departmentId}`;
    fetchData(url, (dept) => {
     if (!dept) { viewDeptInfo.innerHTML = '<p style="color: red;">Error loading details.</p>'; return; }
     viewDeptInfo.innerHTML = `
                <p><strong>Department ID:</strong> ${dept.department_id}</p>
                <p><strong>Name:</strong> ${dept.department_name}</p>
                <p><strong>Initials:</strong> ${dept.department_initials || 'N/A'}</p>
     `;
 }, (errorMsg) => { viewDeptInfo.innerHTML = `<p style="color: red;">Error loading details: ${errorMsg}</p>`; });
}
function closeDepartmentModals() {
    if(addEditDeptModal) addEditDeptModal.classList.add('hidden');
    if(viewDeptModal) viewDeptModal.classList.add('hidden');
}
if(addEditDeptForm) {
    addEditDeptForm.addEventListener('submit', function(e) {
     e.preventDefault();
     const formData = new FormData(this);
     const deptId = formData.get('department_id');
     const url = deptId ? 'api-admin/update_department.php' : 'api-admin/add_department.php';

     postData(url, formData, (response) => {
        alert(response.success || `Department ${deptId ? 'updated' : 'added'}.`);
        closeDepartmentModals();
        fetchDepartments();
    }, (errorMsg) => { alert(`Error: ${errorMsg}`); });
 });
}
if(deptsListContainer) {
    deptsListContainer.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-department')) {
            const deptId = e.target.getAttribute('data-id');
            const deptName = e.target.getAttribute('data-name');
            if (confirm(`Are you sure you want to delete department: "${deptName}"? This will fail if programs, dissertations, etc. are associated with it.`)) {
                const url = 'api-admin/delete_department.php';
                postData(url, { department_id: deptId }, (response) => {
                    alert(response.success || "Department deleted.");
                    fetchDepartments();
                }, (errorMsg) => { alert(`Error deleting department: ${errorMsg}`); });
            }
        }
    });
}
if(deptFilterButton) { deptFilterButton.addEventListener('click', fetchDepartments); }


    // =======================
    // == SETTINGS PAGE JS ==
    // =======================
const personalDetailsInfo = document.getElementById('personalDetailsInfo');
const editMyProfileForm = document.getElementById('editMyProfileForm');
const changeMyPasswordForm = document.getElementById('changeMyPasswordForm');
const logoutButton = document.getElementById('logoutButton');
const deleteMyAccountButton = document.getElementById('deleteMyAccountButton');

    // Function to fetch and display current admin's details
function fetchMyDetails() {
    personalDetailsInfo.innerHTML = '<p>Loading your details...</p>';
        // Clear forms potentially? Or prefill after fetch
        // editMyProfileForm.reset(); // Optional: reset form before fetching

        const url = `api-admin/get_personal_details.php`; // Needs new API endpoint

        fetchData(url, (admin) => {

            if (!admin || !admin.data.account_id || admin.error != null) {
                personalDetailsInfo.innerHTML = '<p style="color: red;">Could not load your details.</p>';
                return;
            }

            // Populate Personal Details View
            personalDetailsInfo.innerHTML = `
                <p><strong>Account ID:</strong> ${admin.data.account_id}</p>
                <p><strong>Username:</strong> ${admin.data.username}</p>
                <p><strong>Name:</strong> ${admin.data.name}</p>
                <p><strong>Sex:</strong> ${admin.data.sex === 'M' ? 'Male' : (admin.data.sex === 'F' ? 'Female' : 'N/A')}</p>
                <p><strong>Work ID:</strong> ${admin.data.work_id || 'N/A'}</p>
                <p><strong>Position:</strong> ${admin.data.position || 'N/A'}</p>
            `;

            // Pre-fill Edit Profile Form
            document.getElementById('myProfileUsername').value = admin.data.username || '';
            document.getElementById('myProfileName').value = admin.data.name || '';
            document.getElementById('myProfileSex').value = admin.data.sex || '';
            document.getElementById('myProfileWorkId').value = admin.data.work_id || '';
            document.getElementById('myProfilePosition').value = admin.data.position || '';

        }, (errorMsg) => {
            personalDetailsInfo.innerHTML = `<p style="color: red;">Error loading your details: ${errorMsg}</p>`;
        });
    }

    // --- Event Listeners for Settings Section ---

    // Edit My Profile Form Submission
    if (editMyProfileForm) {
        editMyProfileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            // Add any extra client-side validation if needed

            const url = 'api-admin/update_profile.php'; // Needs new API endpoint
            postData(url, formData, (response) => {
                alert(response.success || "Profile updated successfully.");
                fetchMyDetails(); // Refresh the details view
            }, (errorMsg) => {
                alert(`Error updating profile: ${errorMsg}`);
            });
        });
    }

    // Change My Password Form Submission
    if (changeMyPasswordForm) {
        changeMyPasswordForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const newPassword = formData.get('new_password');
            const confirmNewPassword = formData.get('confirm_new_password');

            if (newPassword !== confirmNewPassword) {
                alert("New password and confirmation password do not match.");
                return;
            }
            if (!newPassword) { // Basic check
             alert("New password cannot be empty.");
             return;
         }
            // Add password complexity checks if desired

            const url = 'api-admin/change_password.php'; // Needs new API endpoint
            postData(url, formData, (response) => {
                alert(response.success || "Password changed successfully.");
                changeMyPasswordForm.reset(); // Clear the form
            }, (errorMsg) => {
                alert(`Error changing password: ${errorMsg}`);
                // Do not clear the form on error, maybe just the password fields
                document.getElementById('currentPassword').value = '';
                document.getElementById('newPassword').value = '';
                document.getElementById('confirmNewPassword').value = '';
            });
        });
    }

    // Logout Button
    if (logoutButton) {
        logoutButton.addEventListener('click', function() {
            if (confirm("Are you sure you want to logout?")) {
                window.location.href = 'logout.php';
            }
        });
    }

    // Delete My Account Button
    if (deleteMyAccountButton) {
        deleteMyAccountButton.addEventListener('click', function() {
            // Add extra confirmation, maybe ask for password again
            if (confirm("!!! DANGER !!!\nAre you absolutely sure you want to permanently delete your own account?\nThis action CANNOT be undone.")) {
                 // Optionally add a prompt for current password for verification before deleting
                 // const currentPassword = prompt("Please enter your current password to confirm deletion:");
                 // if (currentPassword === null || currentPassword === "") {
                 //     alert("Account deletion cancelled.");
                 //     return;
                 // }

                const url = 'api-admin/delete_self_account.php'; // Needs new API endpoint
                 // Send necessary data, maybe password if prompted
                const data = {
                    account_id: loggedInAdminId // Ensure backend verifies this against session
                    // password: currentPassword // If password prompt is used
                };

                postData(url, data, (response) => {
                    alert(response.success || "Your account has been deleted.");
                    // Redirect to login page after successful deletion
                    window.location.href = 'login.php';
                }, (errorMsg) => {
                    alert(`Error deleting account: ${errorMsg}`);
                });
            }
        });
    }


    // ================================
    // == General Helper Functions ==
    // ================================
     // Show context menu for rows
    function showMenu(icon) {
        const menu = icon.nextElementSibling;
        document.querySelectorAll('.menu-options').forEach(m => {
            if (m !== menu) m.classList.add('hidden');
        });
        if(menu) menu.classList.toggle('hidden');
    }

    // Close Menus When Clicking Outside
    document.addEventListener('click', function(e) {
        if (!e.target.matches('.fa-ellipsis-vertical') && !e.target.closest('.menu-options')) {
            document.querySelectorAll('.menu-options').forEach(m => m.classList.add('hidden'));
        }
    });


</script>
</body>
</html>