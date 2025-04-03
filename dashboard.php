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


try {
    // Fetch total number of users (account_type = 'User')
    $stmt_users = $conn->query("SELECT COUNT(*) AS total FROM ACCOUNT WHERE account_type = 'User'");
    $total_users = $stmt_users->fetchColumn() ?: 0; // Use fetchColumn for single value
    $stmt_users->closeCursor();

    // Fetch total number of admins (account_type = 'Admin') - Optional card
    $stmt_admins = $conn->query("SELECT COUNT(*) AS total FROM ACCOUNT WHERE account_type = 'Admin'");
    $total_admins = $stmt_admins->fetchColumn() ?: 0;
    $stmt_admins->closeCursor();

    // Fetch total number of abstracts
    $stmt_abstracts = $conn->query("SELECT COUNT(*) AS total FROM ABSTRACT");
    $total_abstracts = $stmt_abstracts->fetchColumn() ?: 0;
    $stmt_abstracts->closeCursor();

    // Fetch total number of programs
    $stmt_programs_count = $conn->query("SELECT COUNT(*) AS total FROM PROGRAM");
    $total_programs = $stmt_programs_count->fetchColumn() ?: 0;
    $stmt_programs_count->closeCursor();

    // Fetch total number of departments
    $stmt_departments_count = $conn->query("SELECT COUNT(*) AS total FROM DEPARTMENT");
    $total_departments = $stmt_departments_count->fetchColumn() ?: 0;
    $stmt_departments_count->closeCursor();

    // --- Fetch Data for Dropdowns (used in multiple modals) ---
    // Fetch programs
    $stmt_programs_dropdown = $conn->query("SELECT program_id, program_name FROM PROGRAM ORDER BY program_name ASC");
    $programs = $stmt_programs_dropdown->fetchAll(PDO::FETCH_ASSOC);
    $stmt_programs_dropdown->closeCursor();

    // Fetch departments
    $stmt_departments_dropdown = $conn->query("SELECT department_id, department_name FROM DEPARTMENT ORDER BY department_name ASC");
    $departments = $stmt_departments_dropdown->fetchAll(PDO::FETCH_ASSOC);
    $stmt_departments_dropdown->closeCursor();

     // --- Fetch Recent Abstracts (Example: Top 5) ---
     // This requires joining to get related name (program/department)
     $stmt_recent = $conn->query("
         SELECT
             a.abstract_id, a.title, a.abstract_type,
             CASE
                 WHEN a.abstract_type = 'Thesis' THEN p.program_name
                 WHEN a.abstract_type = 'Dissertation' THEN dpt.department_name
                 ELSE NULL
             END AS related_entity_name
             -- Assuming you add a timestamp column like 'created_at' to ABSTRACT table:
             -- , a.created_at
         FROM ABSTRACT a
         LEFT JOIN THESIS_ABSTRACT ta ON a.abstract_id = ta.abstract_id AND a.abstract_type = 'Thesis'
         LEFT JOIN PROGRAM p ON ta.program_id = p.program_id
         LEFT JOIN DISSERTATION_ABSTRACT da ON a.abstract_id = da.abstract_id AND a.abstract_type = 'Dissertation'
         LEFT JOIN DEPARTMENT dpt ON da.department_id = dpt.department_id
         ORDER BY a.abstract_id DESC -- Or by a timestamp column like 'created_at' DESC
         LIMIT 5
     ");
     $recent_abstracts = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
     $stmt_recent->closeCursor();


} catch (PDOException $e) {
    // Log error and set user-friendly message
    error_log("Dashboard Database Error: " . $e->getMessage());
    $db_error_message = "An error occurred while loading dashboard data. Please try again later.";
    // You might want to halt further processing or display the error prominently
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
        .modal-content-view { background-color: white; padding: 20px; border-radius: 5px; width: 80%; max-width: 500px; } /* Smaller modal for viewing */
        .grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;}
        .form-group.full-width { grid-column: 1 / -1; }
        .hidden { display: none; }
        .active { display: block; }
        /* Styles for view modal */
        .view-info p { margin: 8px 0; font-size: 0.95rem; line-height: 1.5; }
        .view-info strong { color: #333; margin-right: 8px; display: inline-block; min-width: 120px; } /* Align labels */
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
                <li><a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i><span> Logout</span></a></li>
            </ul>
        </nav>
    </aside>

    <div id="shadow" class="shadow hidden" onclick="toggleMenu()"></div>

    <main class="content">
        <?php if ($db_error_message): ?>
            <div class="db-error"><?php echo htmlspecialchars($db_error_message); ?></div>
        <?php endif; ?>

        <!-- Dashboard Section -->
        <section id="dashboard" class="active">
            <h1>Dashboard Overview</h1>
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
                                <!-- Add timestamp if you have it: -->
                                <!-- <div class="abstract-info"><strong>Uploaded:</strong> <?php echo htmlspecialchars($recent['created_at']); ?></div> -->
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No recent abstracts found.</p>
                <?php endif; ?>
            </section>
        </section>

        <!-- Manage Accounts Section (Combined Users & Admins) -->
        <section id="manage-accounts" class="hidden" style="min-height: 80vh;">
            <h2>Manage Accounts</h2>
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
                        <input type="hidden" id="editAccountId" name="editAccountId"> <!-- ADD name="editAccountId" -->
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
                                    <!-- <option value="O">Other</option> -->
                                </select>
                            </div>

                             <!-- User Specific Fields -->
                            <div class="form-group user-field" style="display: none;"> <!-- Hide initially -->
                                <label for="editAccAcademicLevel">Academic Level:</label>
                                <input type="text" id="editAccAcademicLevel" name="academic_level">
                            </div>
                            <div class="form-group user-field" style="display: none;"> <!-- Hide initially -->
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
                            <div class="form-group admin-field" style="display: none;"> <!-- Hide initially -->
                                <label for="editAccWorkId">Work ID:</label>
                                <input type="text" id="editAccWorkId" name="work_id">
                            </div>
                             <div class="form-group admin-field" style="display: none;"> <!-- Hide initially -->
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

        <!-- Manage Abstracts Section -->
        <section id="manage-abstracts" class="hidden" style="min-height: 80vh;">
            <h2>Manage Abstracts</h2>
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

        <section id="manage-programs" class="hidden" style="min-height: 80vh;">
            <h2>Manage Programs</h2>
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

        <!-- Manage Departments Section -->
        <section id="manage-departments" class="hidden" style="min-height: 80vh;">
            <h2>Manage Departments</h2>
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

    </main>

    <!-- Footer or other elements -->

<script>
    const loggedInAdminId = <?php echo json_encode($logged_in_admin_id); ?>; // Make PHP 

    // --- Existing JS below ---
    // Sidebar Toggle Function
    function toggleMenu() {
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

    // ... (rest of your existing JavaScript for fetching data, modals, etc.) ...
    // Section Switching Logic
    document.querySelectorAll('.sidebar nav ul li a:not(.logout)').forEach(link => {
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
                    case 'manage-programs': fetchPrograms(); break; // Add this
                    case 'manage-departments': fetchDepartments(); break; // Add this
                }
            }
            if (window.innerWidth < 768) { toggleMenu(); }
        });
    });



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

    // Send as form data or JSON depending on your API needs
    // Assuming form data for these examples based on previous code
    const body = (data instanceof FormData) ? data : new URLSearchParams(data);
    if (!(data instanceof FormData)) {
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            // --- CHANGE HERE: Accept any 2xx status code as success ---
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    // Check if response body is empty (common for 204 No Content)
                    if (!xhr.responseText) {
                        // If empty, treat as success with no specific data
                        callback({}); // Pass an empty object or null/undefined as needed
                        return; // Exit early
                    }

                    // Attempt to parse response if not empty
                    const response = JSON.parse(xhr.responseText);

                    // Check for application-level errors WITHIN the successful response
                    if (response.error) {
                        console.error("API Error (within 2xx response):", response.error);
                        if(errorCallback) errorCallback(response.error);
                        else alert("Error: " + response.error);
                    } else {
                        // Genuine success
                        callback(response);
                    }
                } catch (e) {
                    console.error("Parse Error on successful response:", e, xhr.responseText);
                    if(errorCallback) errorCallback("Error parsing server response despite success status.");
                    else alert("Error parsing server response despite success status.");
                }
            } else {
                // --- Handle HTTP errors (4xx, 5xx, etc.) ---
                let errorMsg = `Server responded with status ${xhr.status}`; // Default error
                try {
                    // Attempt to parse error details from response body
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse && errorResponse.error) {
                        errorMsg = errorResponse.error; // Use specific error from API if available
                    }
                } catch (e) {
                    // Ignore parse error - response body might not be JSON
                    console.warn("Could not parse error response body as JSON.");
                }

                console.error("HTTP Error:", xhr.status, xhr.statusText, xhr.responseText); // Log details
                if(errorCallback) {
                    errorCallback(errorMsg); // Use the potentially more specific error message
                } else {
                     alert(errorMsg); // Fallback alert
                }
            }
        }
    };

    xhr.onerror = function() {
        // --- Handle Network errors ---
        console.error("Network Error");
        const networkErrorMsg = "Network error. Please check your connection.";
        if(errorCallback) errorCallback(networkErrorMsg);
        else alert(networkErrorMsg);
    };

    xhr.send(body);
}


    // ===========================
    // == ACCOUNT MANAGEMENT JS ==
    // ===========================
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
        // Add sort direction if needed

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
                            <li onclick="openViewAccountModal(${acc.account_id})">View</li> <!-- Added View -->
                            <li class="delete-account" data-id="${acc.account_id}" data-name="${acc.username}">Delete</li>
                        </ul>
                    </div>
                `;
                accountsListContainer.appendChild(row);
            });
        }, (errorMsg) => {
             accountsListContainer.innerHTML = `<p style="color: red;">Error loading accounts: ${errorMsg}</p>`;
        });
    }

    // Event listeners for account filters/sort
    if(accountFilterButton) {
        accountFilterButton.addEventListener('click', fetchAccounts);
    }

    // Function to open edit account modal
    function openEditAccountModal(accountId) {
         // Fetch account details for the given ID
         const url = `api-admin/get_account.php?account_id=${encodeURIComponent(accountId)}`; // Needs new API endpoint
         fetchData(url, (account) => {
             if (!account) {
                 alert("Could not retrieve account details.");
                 return;
             }
             console.log(account.account_id);
             // Populate common fields
             document.getElementById('editAccountId').value = account.account_id;
             document.getElementById('editAccountType').value = account.account_type; // Store type
             document.getElementById('editAccUsername').value = account.username;
             document.getElementById('editAccName').value = account.name;
             document.getElementById('editAccSex').value = account.sex || ''; // Handle null sex

             // Show/hide specific fields based on type
             const userFields = editAccountModal.querySelectorAll('.user-field');
             const adminFields = editAccountModal.querySelectorAll('.admin-field');

             if (account.account_type === 'User') {
                 userFields.forEach(f => f.style.display = 'block');
                 adminFields.forEach(f => f.style.display = 'none');
                 document.getElementById('editAccAcademicLevel').value = account.academic_level || '';
                 document.getElementById('editAccProgramId').value = account.program_id || '';
                 // Clear admin fields
                 document.getElementById('editAccWorkId').value = '';
                 document.getElementById('editAccPosition').value = '';
             } else if (account.account_type === 'Admin') {
                 userFields.forEach(f => f.style.display = 'none');
                 adminFields.forEach(f => f.style.display = 'block');
                 document.getElementById('editAccWorkId').value = account.work_id || '';
                 document.getElementById('editAccPosition').value = account.position || '';
                  // Clear user fields
                 document.getElementById('editAccAcademicLevel').value = '';
                 document.getElementById('editAccProgramId').value = '';
             } else {
                 // Hide all specific fields if type is unknown
                  userFields.forEach(f => f.style.display = 'none');
                  adminFields.forEach(f => f.style.display = 'none');
             }

             editAccountModal.classList.remove('hidden');
         }, (errorMsg) => {
             alert(`Error fetching account details: ${errorMsg}`);
         });
    }

    // Function to close edit account modal
    function closeEditAccountModal() {
        if(editAccountModal) editAccountModal.classList.add('hidden');
        if(editAccountForm) editAccountForm.reset(); // Clear the form
    }

    // Handle Edit Account Form Submission
    if(editAccountForm) {
        editAccountForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const accountId = formData.get('account_id'); // Ensure hidden field has name="account_id"
            const accountType = document.getElementById('editAccountType').value; // Get stored type

             // Remove fields not relevant to the account type before sending
            if(accountType === 'User') {
                formData.delete('work_id');
                formData.delete('position');
            } else if (accountType === 'Admin') {
                formData.delete('academic_level');
                formData.delete('program_id');
            }

            const url = 'api-admin/update_account.php'; // Needs new API endpoint
            postData(url, formData, (response) => {
                alert(response.success || "Account updated.");
                closeEditAccountModal();
                fetchAccounts(); // Refresh list
            }, (errorMsg) => {
                alert(`Error updating account: ${errorMsg}`);
            });
        });
    }

    // Handle Account Deletion
    if(accountsListContainer) {
        accountsListContainer.addEventListener('click', function(e) {
             if (e.target.classList.contains('delete-account')) {
                const accountId = e.target.getAttribute('data-id');
                const accountName = e.target.getAttribute('data-name');
                // --- IMPORTANT: Check against logged-in admin's ID ---
                const loggedInAdminId = <?php echo json_encode($_SESSION['account_id']); ?>; // Get from PHP session

                if (parseInt(accountId) === parseInt(loggedInAdminId)) {
                    alert("You cannot delete your own account.");
                    return;
                }
                // --- End Self-delete Check ---

                if (confirm(`Are you sure you want to delete account: ${accountName} (ID: ${accountId})?`)) {
                    const url = 'api-admin/delete_account.php'; // Needs new API endpoint
                    const data = { account_id: accountId };
                    postData(url, data, (response) => {
                        alert(response.success || "Account deleted.");
                        fetchAccounts(); // Refresh list
                    }, (errorMsg) => {
                        alert(`Error deleting account: ${errorMsg}`);
                    });
                }
            }
        });
    }

    // View Account Modal Functions
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

    // Add Admin Modal Functions
    function openAddAdminModal() {
        if(addAdminForm) addAdminForm.reset();
        if(addAdminModal) addAdminModal.classList.remove('hidden');
    }
    function closeAddAdminModal() {
        if (addAdminModal) addAdminModal.classList.add('hidden');
    }
    // Handle Add Admin Form Submission
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

            // Add account_type explicitly for the backend
            formData.append('account_type', 'Admin');

            const url = 'api-admin/add_admin.php'; // New API endpoint needed
            postData(url, formData, (response) => {
                alert(response.success || "Admin account added successfully.");
                closeAddAdminModal();
                fetchAccounts(); // Refresh list
            }, (errorMsg) => {
                alert(`Error adding admin: ${errorMsg}`);
            });
        });
    }

    if(accountFilterButton) { accountFilterButton.addEventListener('click', fetchAccounts); }


    // ==============================
    // == ABSTRACT MANAGEMENT JS ==
    // ==============================
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
        const filterType = abstractTypeFilter.value; // Value should be 'Thesis' or 'Dissertation'

        abstractsListContainer.innerHTML = '<p>Loading abstracts...</p>'; // Loading state

        // Use the updated fetch_abstracts API endpoint from previous examples
        const url = `api-admin/fetch_abstracts.php?search=${encodeURIComponent(searchTerm)}&filterByType=${encodeURIComponent(filterType)}`; // Use 'filterByType'

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
                            <li onclick="viewAbstract(${abs.abstract_id})">View</li> <!-- Added View -->
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

     // Event listener for abstract filter button
     if(abstractFilterButton) {
         abstractFilterButton.addEventListener('click', fetchAbstracts);
     }

     // Open Add/Edit Abstract Modal
     function openAddAbstractModal() {
         addEditAbstractForm.reset();
         document.getElementById('editAbstractId').value = ''; // Clear edit ID
         abstractModalTitle.textContent = 'Add New Abstract';
         abstractSubmitButton.textContent = 'Upload';
         currentFileNameSpan.textContent = ''; // Clear current file name
         abstractFileInput.required = true; // File required for add
         toggleAbstractFields(); // Set initial field visibility
         addEditAbstractModal.classList.remove('hidden');
     }

     function openEditAbstractModal(abstractId) {
        addEditAbstractForm.reset();
        const url = `api-admin/get_abstract.php?abstract_id=${encodeURIComponent(abstractId)}`; // Needs API endpoint
        fetchData(url, (abstract) => {
            if(!abstract) {
                alert("Could not retrieve abstract details.");
                return;
            }
            // Populate form
            document.getElementById('editAbstractId').value = abstract.abstract_id;
            document.getElementById('abstractTitle').value = abstract.title || '';
            document.getElementById('abstractResearchers').value = abstract.researchers || '';
            document.getElementById('abstractCitation').value = abstract.citation || '';
            document.getElementById('abstractType').value = abstract.abstract_type || '';
            document.getElementById('abstractDescription').value = abstract.description || '';
            document.getElementById('abstractProgramId').value = abstract.program_id || ''; // Will be set even if hidden initially
            document.getElementById('abstractDepartmentId').value = abstract.department_id || ''; // Will be set even if hidden initially

             // Show current file info (you'll need the API to return filename)
            currentFileNameSpan.textContent = abstract.file_name ? `Current: ${abstract.file_name}` : 'No file uploaded or info unavailable';
            abstractFileInput.required = false; // File not required for edit

            // Toggle fields based on fetched type
            toggleAbstractFields();

            abstractModalTitle.textContent = 'Edit Abstract';
            abstractSubmitButton.textContent = 'Save Changes';
            addEditAbstractModal.classList.remove('hidden');

        }, (errorMsg) => {
             alert(`Error fetching abstract details: ${errorMsg}`);
        });
     }


     // Close Add/Edit Abstract Modal
    function closeAddEditAbstractModal() {
        if(addEditAbstractModal) addEditAbstractModal.classList.add('hidden');
        if(addEditAbstractForm) addEditAbstractForm.reset();
        currentFileNameSpan.textContent = '';
         // Reset field visibility
         if(abstractProgramField) abstractProgramField.style.display = 'none';
         if(abstractDepartmentField) abstractDepartmentField.style.display = 'none';
    }

     // Toggle Program/Department fields in modal based on Abstract Type
     function toggleAbstractFields() {
         const type = abstractTypeSelect.value; // Get value from the modal's select
          if (type === 'Thesis') {
             if(abstractProgramField) abstractProgramField.style.display = 'block';
             if(abstractDepartmentField) abstractDepartmentField.style.display = 'none';
             document.getElementById('abstractDepartmentId').value = ''; // Clear hidden field
         } else if (type === 'Dissertation') {
            if(abstractProgramField) abstractProgramField.style.display = 'none';
            if(abstractDepartmentField) abstractDepartmentField.style.display = 'block';
             document.getElementById('abstractProgramId').value = ''; // Clear hidden field
         } else {
            if(abstractProgramField) abstractProgramField.style.display = 'none';
            if(abstractDepartmentField) abstractDepartmentField.style.display = 'none';
             document.getElementById('abstractDepartmentId').value = '';
             document.getElementById('abstractProgramId').value = '';
         }
     }

     // Add event listener for type change in modal
     if(abstractTypeSelect) {
         abstractTypeSelect.addEventListener('change', toggleAbstractFields);
     }

     // Add event listener for Add Abstract button
     if(addAbstractButton) {
         addAbstractButton.addEventListener('click', openAddAbstractModal);
     }

    // Handle Abstract Form Submission (Add/Edit)
    if(addEditAbstractForm) {
        addEditAbstractForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const abstractId = formData.get('abstract_id'); // Get hidden ID
            const abstractType = formData.get('abstract_type');

            // Conditional validation based on type (client-side pre-check)
             if (abstractType === 'Thesis' && !formData.get('program_id')) {
                alert('Please select a Program for a Thesis.'); return;
             }
              if (abstractType === 'Dissertation' && !formData.get('department_id')) {
                alert('Please select a Department for a Dissertation.'); return;
             }
             // Remove irrelevant ID before sending
             if (abstractType === 'Thesis') formData.delete('department_id');
             if (abstractType === 'Dissertation') formData.delete('program_id');


            const url = abstractId ? 'api-admin/update_abstract.php' : 'api-admin/upload_abstract.php'; // Determine API endpoint

            postData(url, formData, (response) => {
                 alert(response.success || `Abstract ${abstractId ? 'updated' : 'added'}.`);
                 closeAddEditAbstractModal();
                 fetchAbstracts(); // Refresh list
            }, (errorMsg) => {
                 alert(`Error: ${errorMsg}`);
            });
        });
    }

    // Handle Abstract Deletion
     if(abstractsListContainer) {
        abstractsListContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('delete-abstract')) {
                const abstractId = e.target.getAttribute('data-id');
                const abstractTitle = e.target.getAttribute('data-title');
                if (confirm(`Are you sure you want to delete abstract: "${abstractTitle}" (ID: ${abstractId})?`)) {
                    const url = 'api-admin/delete_abstract.php'; // Use updated API
                    const data = { abstract_id: abstractId };
                    postData(url, data, (response) => {
                         alert(response.success || "Abstract deleted.");
                         fetchAbstracts(); // Refresh list
                    }, (errorMsg) => {
                         alert(`Error deleting abstract: ${errorMsg}`);
                    });
                }
            }
        });
     }

      // View Abstract Function (Opens PDF in new tab)
    function viewAbstract(abstractId) {
        // Point to a PHP script that fetches the file and outputs it with appropriate headers
        const viewUrl = `api-general/view_abstract.php?id=${encodeURIComponent(abstractId)}`;
        window.open(viewUrl, '_blank'); // Open in new tab
        // Note: We will need to creacte view_abstract.php on the backend
    }

    // Download Abstract Function (Ensure logging is added in PHP)
    function downloadAbstract(abstractId) {
        window.location.href = `api-general/download_abstract.php?id=${encodeURIComponent(abstractId)}`;
    }

    // Delete Abstract (Ensure logging is added in PHP)
    // ... keep existing delete handler ...

    // Abstract Filters
    if(abstractFilterButton) { abstractFilterButton.addEventListener('click', fetchAbstracts); }

     // ===========================
    // == PROGRAM MANAGEMENT JS ==
    // ===========================
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
        const url = `api-admin/fetch_programs.php?search=${encodeURIComponent(searchTerm)}&department_id=${encodeURIComponent(departmentId)}`; // New API

        fetchData(url, (programs) => {
            programsListContainer.innerHTML = '';
            if (programs.length === 0) {
                programsListContainer.innerHTML = '<p>No programs found matching criteria.</p>'; return;
            }
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
        }, (errorMsg) => {
            programsListContainer.innerHTML = `<p style="color: red;">Error loading programs: ${errorMsg}</p>`;
        });
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
        const url = `api-admin/get_program.php?program_id=${programId}`; // New API
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
        const url = `api-admin/get_program.php?program_id=${programId}`; // Reuse get API
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

    // Program Form Submit (Add/Edit)
    if(addEditProgramForm) {
        addEditProgramForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const programId = formData.get('program_id');
            const url = programId ? 'api-admin/update_program.php' : 'api-admin/add_program.php'; // New APIs

            postData(url, formData, (response) => {
                alert(response.success || `Program ${programId ? 'updated' : 'added'}.`);
                closeProgramModals();
                fetchPrograms();
            }, (errorMsg) => { alert(`Error: ${errorMsg}`); });
        });
    }

     // Program Delete
    if(programsListContainer) {
        programsListContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('delete-program')) {
                const programId = e.target.getAttribute('data-id');
                const programName = e.target.getAttribute('data-name');
                if (confirm(`Are you sure you want to delete program: "${programName}"? This might fail if abstracts or users are associated with it.`)) {
                    const url = 'api-admin/delete_program.php'; // New API
                    postData(url, { program_id: programId }, (response) => {
                        alert(response.success || "Program deleted.");
                        fetchPrograms();
                    }, (errorMsg) => { alert(`Error deleting program: ${errorMsg}`); });
                }
            }
        });
    }

    // Program Filters
    if(programFilterButton) { programFilterButton.addEventListener('click', fetchPrograms); }

     // ==============================
    // == DEPARTMENT MANAGEMENT JS ==
    // ==============================
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
        const url = `api-admin/fetch_departments.php?search=${encodeURIComponent(searchTerm)}`; // New API

        fetchData(url, (departments) => {
            deptsListContainer.innerHTML = '';
             if (departments.length === 0) {
                 deptsListContainer.innerHTML = '<p>No departments found.</p>'; return;
             }
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
        }, (errorMsg) => {
             deptsListContainer.innerHTML = `<p style="color: red;">Error loading departments: ${errorMsg}</p>`;
        });
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
        const url = `api-admin/get_department.php?department_id=${departmentId}`; // New API
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
        const url = `api-admin/get_department.php?department_id=${departmentId}`; // Reuse get API
        fetchData(url, (dept) => {
             if (!dept) { viewDeptInfo.innerHTML = '<p style="color: red;">Error loading details.</p>'; return; }
             viewDeptInfo.innerHTML = `
                <p><strong>Department ID:</strong> ${dept.department_id}</p>
                <p><strong>Name:</strong> ${dept.department_name}</p>
                <p><strong>Initials:</strong> ${dept.department_initials || 'N/A'}</p>
                <!-- Add program count or list if needed later -->
            `;
        }, (errorMsg) => { viewDeptInfo.innerHTML = `<p style="color: red;">Error loading details: ${errorMsg}</p>`; });
    }

    function closeDepartmentModals() {
        if(addEditDeptModal) addEditDeptModal.classList.add('hidden');
        if(viewDeptModal) viewDeptModal.classList.add('hidden');
    }

    // Department Form Submit (Add/Edit)
    if(addEditDeptForm) {
        addEditDeptForm.addEventListener('submit', function(e) {
             e.preventDefault();
             const formData = new FormData(this);
             const deptId = formData.get('department_id');
             const url = deptId ? 'api-admin/update_department.php' : 'api-admin/add_department.php'; // New APIs

             postData(url, formData, (response) => {
                alert(response.success || `Department ${deptId ? 'updated' : 'added'}.`);
                closeDepartmentModals();
                fetchDepartments();
             }, (errorMsg) => { alert(`Error: ${errorMsg}`); });
        });
    }

     // Department Delete
    if(deptsListContainer) {
        deptsListContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('delete-department')) {
                const deptId = e.target.getAttribute('data-id');
                const deptName = e.target.getAttribute('data-name');
                if (confirm(`Are you sure you want to delete department: "${deptName}"? This will fail if programs, dissertations, etc. are associated with it.`)) {
                    const url = 'api-admin/delete_department.php'; // New API
                    postData(url, { department_id: deptId }, (response) => {
                        alert(response.success || "Department deleted.");
                        fetchDepartments();
                    }, (errorMsg) => { alert(`Error deleting department: ${errorMsg}`); });
                }
            }
        });
    }

    // Department Filters
    if(deptFilterButton) { deptFilterButton.addEventListener('click', fetchDepartments); }

    // ================================
    // == General Helper Functions ==
    // ================================
     // Show context menu for rows
    function showMenu(icon) {
        const menu = icon.nextElementSibling;
        // Hide other menus first
        document.querySelectorAll('.menu-options').forEach(m => {
            if (m !== menu) m.classList.add('hidden');
        });
        // Toggle current menu
        if(menu) menu.classList.toggle('hidden');
    }

    // Close Menus When Clicking Outside
    document.addEventListener('click', function(e) {
        // If the click is not on an ellipsis icon or inside a menu-options list
        if (!e.target.matches('.fa-ellipsis-vertical') && !e.target.closest('.menu-options')) {
            document.querySelectorAll('.menu-options').forEach(m => m.classList.add('hidden'));
        }
    });

    // --- Initial Data Loads ---
    fetchAccounts(); // Fetch accounts when the script loads (assuming it's the default view or needed early)

</script>
</body>
</html>