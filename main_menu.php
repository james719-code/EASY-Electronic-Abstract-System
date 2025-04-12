<?php
session_start();

// Security: Redirect non-users to login page based on ACCOUNT table structure
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'User' || !isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

// Use the config consistent with admin dashboard
include 'api-general/config.php'; // Make sure this path is correct

$userAccountId = $_SESSION['account_id']; // User's Account ID from session
$userFullName = $_SESSION['name'] ?? 'User'; // Assumes 'name' from ACCOUNT table is in session
$logged_in_user_id = $userAccountId; // Variable name consistent with JS convention

$programs = [];
$departments = []; // For dissertation filter
$userProgramId = null;
$userProgramName = 'N/A';
$recentAbstracts = [];
$total_thesis = 0; // Initialize count
$total_dissertation = 0; // Initialize count
$db_error_message = null;

try {
    // Fetch all programs for filter dropdowns AND settings edit form
    $stmtPrograms = $conn->query("SELECT program_id, program_name FROM program ORDER BY program_name ASC");
    $programs = $stmtPrograms->fetchAll(PDO::FETCH_ASSOC);
    $stmtPrograms->closeCursor();

    // Fetch all departments for the dissertation filter dropdown
    $stmtDepartments = $conn->query("SELECT department_id, department_name FROM department ORDER BY department_name ASC");
    $departments = $stmtDepartments->fetchAll(PDO::FETCH_ASSOC);
    $stmtDepartments->closeCursor();

    // Fetch total Thesis abstracts count
    $stmtTotalThesis = $conn->query("SELECT COUNT(*) FROM abstract WHERE abstract_type = 'Thesis'");
    $total_thesis = $stmtTotalThesis->fetchColumn() ?: 0;
    $stmtTotalThesis->closeCursor();

    // Fetch total Dissertation abstracts count
    $stmtTotalDissertation = $conn->query("SELECT COUNT(*) FROM abstract WHERE abstract_type = 'Dissertation'");
    $total_dissertation = $stmtTotalDissertation->fetchColumn() ?: 0;
    $stmtTotalDissertation->closeCursor();

    // Fetch the user's program_id and name using joins
    $stmtUserProgram = $conn->prepare("
        SELECT u.program_id, p.program_name
        FROM user u -- References USER table
        JOIN program p ON u.program_id = p.program_id -- References PROGRAM table
        WHERE u.user_id = :account_id -- References USER.user_id
        ");
    $stmtUserProgram->bindParam(':account_id', $userAccountId, PDO::PARAM_INT);
    $stmtUserProgram->execute();
    $userProgramInfo = $stmtUserProgram->fetch(PDO::FETCH_ASSOC);
    $stmtUserProgram->closeCursor();

    if ($userProgramInfo) {
        $userProgramId = $userProgramInfo['program_id'];
        $userProgramName = $userProgramInfo['program_name'];

        // Fetch 10 recent abstracts for the user's program (Theses)
        $stmtRecent = $conn->prepare("
            SELECT
            a.abstract_id, a.title, a.researchers, p.program_name
            FROM abstract a
            JOIN thesis_abstract ta ON a.abstract_id = ta.thesis_id
            JOIN program p ON ta.program_id = p.program_id
            WHERE ta.program_id = :program_id AND a.abstract_type = 'Thesis'
            ORDER BY a.abstract_id DESC LIMIT 10
            ");
        $stmtRecent->bindParam(':program_id', $userProgramId, PDO::PARAM_INT);
        $stmtRecent->execute();
        $recentAbstracts = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
        $stmtRecent->closeCursor();
    } else {
       $db_error_message = "Could not determine your associated program. Please contact an administrator.";
       error_log("User Main Menu: Failed to find program for account_id: " . $userAccountId);
   }

} catch (PDOException $e) {
    error_log("User Main Menu DB Error: " . $e->getMessage());
    $db_error_message = "An error occurred loading data. Please check the system logs or contact support.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main Menu - Electronic Abstract System</title>
    <link rel="stylesheet" href="css/dashboard.css"> <!-- Ensure this path is correct -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* --- Base Styles (Keep existing relevant styles) --- */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .card { background-color: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: flex; flex-direction: column; align-items: center; text-align: center; border: 1px solid #eee; }
        .card i { font-size: 2rem; color: #007bff; margin-bottom: 10px; }
        .card h3 { margin: 10px 0 5px 0; font-size: 1rem; color: #555; }
        .card p { font-size: 1.5rem; font-weight: bold; color: #333; margin: 0; }

        .abstract-row { background-color: #fff; border-radius: 5px; padding: 15px; margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; border: 1px solid #eee; }
        .abstract-info { flex-grow: 1; margin-right: 15px; }
        .abstract-info .title { font-weight: bold; font-size: 1.1em; color: #333; margin-bottom: 5px; }
        .abstract-info p { font-size: 0.9em; color: #555; margin: 3px 0; line-height: 1.4; }
        .abstract-info p strong { color: #444; min-width: 90px; display: inline-block; }
        .abstract-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; }
        .abstract-actions button { padding: 5px 10px; font-size: 0.85em; cursor: pointer; border-radius: 4px; border: 1px solid transparent; min-width: 80px; text-align: center; }
        .abstract-actions .btn-view { background-color: #007bff; color: white; border-color: #007bff; }
        .abstract-actions .btn-view:hover { background-color: #0056b3; border-color: #0056b3; }
        .abstract-actions .btn-download { background-color: #28a745; color: white; border-color: #28a745; }
        .abstract-actions .btn-download:hover { background-color: #218838; border-color: #1e7e34; }
        .search-filter-container select, .search-filter-container input[type="text"] { margin-right: 10px; margin-bottom: 10px; padding: 8px 10px; border-radius: 4px; border: 1px solid #ccc; font-size: 0.9rem;}
        .search-filter-container button { margin-bottom: 10px; } /* Align button */
        .db-error { color: red; background-color: #ffeeee; border: 1px solid red; padding: 10px; margin: 15px; }

        .grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;}
        .form-group.full-width { grid-column: 1 / -1; }
        .hidden { display: none; }
        .active { display: block; }
        .view-info p { margin: 8px 0; font-size: 0.95rem; line-height: 1.5; }
        .view-info strong { color: #333; margin-right: 8px; display: inline-block; min-width: 120px; }

        .settings-container { max-width: 800px; margin: 20px auto; padding: 20px; background-color: #f9f9f9; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .settings-section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .settings-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0;}
        .settings-section h3 { margin-top: 0; margin-bottom: 15px; color: #333; border-bottom: 1px solid #ddd; padding-bottom: 5px;}
        #personalDetailsInfo p { margin-bottom: 10px; }
        #personalDetailsInfo strong { display: inline-block; width: 150px; color: #555; }
        .danger-zone { border: 1px solid #dc3545; padding: 15px; border-radius: 5px; background-color: #f8d7da; }
        .danger-zone h3 { color: #721c24; border-bottom-color: #f5c6cb;}
        .danger-zone p { color: #721c24; margin-bottom: 15px; }
        .danger-zone button { background-color: #dc3545; border-color: #dc3545; }
        .danger-zone button:hover { background-color: #c82333; border-color: #bd2130; }
        .btn.danger-btn { background-color: #dc3545; color: white; border-color: #dc3545; }
        .btn.danger-btn:hover { background-color: #c82333; border-color: #bd2130; }
        .settings-actions { margin-top: 20px; text-align: right; }
        .settings-actions .btn { margin-left: 10px;}

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
        <div class="menu" onclick="toggleMenu()"> <i class="fa-solid fa-bars"></i> </div>
        <div class="nav_name" id="nav_name">EASY</div>
    </header>

    <aside id="sidebar" class="sidebar hidden">
        <i id="x-button" class="fa-solid fa-times close-sidebar" onclick="toggleMenu()"></i>
        <div class="logo"><h2>EASY</h2></div>
        <nav>
           <ul>
            <li><a href="#" data-target="home" class="active"><i class="fas fa-home"></i><span> Home</span></a></li>
            <li><a href="#" data-target="thesisAbstracts"><i class="fas fa-book"></i><span> Thesis Abstracts</span></a></li>
            <li><a href="#" data-target="dissertationAbstracts"><i class="fas fa-scroll"></i><span> Dissertation Abstracts</span></a></li>
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

    <!-- Home Section -->
    <section id="home" class="active">
        <!-- ... content unchanged ... -->
        <h1>Welcome, <?php echo htmlspecialchars($userFullName); ?>!</h1>
        <section class="cards">
            <div class="card"> <i class="fas fa-book"></i> <h3>Total Thesis Abstracts</h3> <p><?php echo htmlspecialchars($total_thesis); ?></p> </div>
            <div class="card"> <i class="fas fa-scroll"></i> <h3>Total Dissertation Abstracts</h3> <p><?php echo htmlspecialchars($total_dissertation); ?></p> </div>
        </section>
        <p>Your current program: <strong><?php echo htmlspecialchars($userProgramName); ?></strong></p>
        <h2>Recently Added Theses</h2>
        <?php if ($userProgramId && !$db_error_message): ?>
           <div class="row-lay" id="recent-abstracts-list">
               <?php if (!empty($recentAbstracts)): ?>
                   <?php foreach ($recentAbstracts as $abstract): ?>
                       <div class="abstract-row">
                           <div class="abstract-info">
                               <div class="title"><?php echo htmlspecialchars($abstract['title']); ?></div>
                               <p><strong>Researchers:</strong> <?php echo htmlspecialchars($abstract['researchers'] ?? 'N/A'); ?></p>
                               <p><strong>Program:</strong> <?php echo htmlspecialchars($abstract['program_name'] ?? 'N/A'); ?></p>
                               <p><strong>Abstract ID:</strong> <?php echo htmlspecialchars($abstract['abstract_id']); ?></p>
                           </div>
                           <div class="abstract-actions">
                               <button class="btn-view" onclick="viewAbstract(<?php echo htmlspecialchars($abstract['abstract_id']); ?>)">View</button>
                               <button class="btn-download" onclick="downloadAbstract(<?php echo htmlspecialchars($abstract['abstract_id']); ?>)">Download</button>
                           </div>
                       </div>
                   <?php endforeach; ?>
               <?php else: ?>
                   <p>No recent abstracts found for your program.</p>
               <?php endif; ?>
           </div>
       <?php elseif(!$db_error_message): ?>
          <p>Could not load recent abstracts because your program information is unavailable.</p>
      <?php endif; ?>
  </section>

  <!-- Thesis Abstracts Section -->
  <section id="thesisAbstracts" class="hidden">
    <!-- ... content unchanged ... -->
    <h2>Search Thesis Abstracts</h2>
    <div class="search-filter-container">
        <input type="text" id="thesisSearchInput" placeholder="Search title, researchers...">
        <select id="thesisProgramFilter"> <option value="">All Programs</option> <?php foreach ($programs as $program): ?> <option value="<?php echo htmlspecialchars($program['program_id']); ?>"> <?php echo htmlspecialchars($program['program_name']); ?> </option> <?php endforeach; ?> </select>
        <select id="thesisSortBy"> <option value="id_desc">Newest First (by ID)</option> <option value="id_asc">Oldest First (by ID)</option> <option value="title_asc">Title (A-Z)</option> <option value="title_desc">Title (Z-A)</option> <option value="researchers_asc">Researchers (A-Z)</option> <option value="researchers_desc">Researchers (Z-A)</option> </select>
        <button id="thesisApplyFilters" class="btn primary-btn">Search</button>
    </div>
    <div class="row-lay" id="thesis-list"> <p>Loading abstracts...</p> </div>
</section>

<!-- Dissertation Abstracts Section -->
<section id="dissertationAbstracts" class="hidden">
   <!-- ... content unchanged ... -->
   <h2>Search Dissertation Abstracts</h2>
   <div class="search-filter-container">
    <input type="text" id="dissSearchInput" placeholder="Search title, researchers...">
    <select id="dissDepartmentFilter"> <option value="">All Departments</option> <?php foreach ($departments as $dept): ?> <option value="<?php echo htmlspecialchars($dept['department_id']); ?>"> <?php echo htmlspecialchars($dept['department_name']); ?> </option> <?php endforeach; ?> </select>
    <select id="dissSortBy"> <option value="id_desc">Newest First (by ID)</option> <option value="id_asc">Oldest First (by ID)</option> <option value="title_asc">Title (A-Z)</option> <option value="title_desc">Title (Z-A)</option> <option value="researchers_asc">Researchers (A-Z)</option> <option value="researchers_desc">Researchers (Z-A)</option> </select>
    <button id="dissApplyFilters" class="btn primary-btn">Search</button>
</div>
<div class="row-lay" id="dissertations-list"> <p>Loading abstracts...</p> </div>
</section>

<!-- Settings Section -->
<section id="settings" class="hidden" style="min-height: 80vh;">
   <!-- ... content unchanged ... -->
   <h1>Account Settings</h1>
   <div class="settings-container">
    <div class="settings-section"> <h3>Personal Details</h3> <div id="personalDetailsInfo"> <p>Loading your details...</p> </div> </div>
    <div class="settings-section">
        <h3>Edit Profile</h3>
        <form id="editMyProfileForm">
            <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($logged_in_user_id); ?>">
            <div class="grid-container">
                <div class="form-group"> <label for="myProfileUsername">Username:</label> <input type="text" id="myProfileUsername" name="username" required> </div>
                <div class="form-group"> <label for="myProfileName">Name:</label> <input type="text" id="myProfileName" name="name" required> </div>
                <div class="form-group"> <label for="myProfileSex">Sex:</label> <select id="myProfileSex" name="sex" required> <option value="">Select Sex</option> <option value="M">Male</option> <option value="F">Female</option> </select> </div>
                <div class="form-group"> <label for="myProfileAcademicLevel">Academic Level:</label> <input type="text" id="myProfileAcademicLevel" name="academic_level"> </div>
                <div class="form-group"> <label for="myProfileProgramId">Program:</label> <select id="myProfileProgramId" name="program_id" required> <option value="">Select Program</option> <?php foreach ($programs as $program): ?> <option value="<?php echo htmlspecialchars($program['program_id']); ?>"> <?php echo htmlspecialchars($program['program_name']); ?> </option> <?php endforeach; ?> </select> </div>
            </div>
            <div class="settings-actions"> <button type="submit" class="btn primary-btn">Save Profile Changes</button> </div>
        </form>
    </div>
    <div class="settings-section">
        <h3>Change Password</h3>
        <form id="changeMyPasswordForm">
            <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($logged_in_user_id); ?>">
            <div class="grid-container" style="grid-template-columns: 1fr;">
                <div class="form-group"> <label for="currentPassword">Current Password:</label> <input type="password" id="currentPassword" name="current_password" required autocomplete="current-password"> </div>
                <div class="form-group"> <label for="newPassword">New Password:</label> <input type="password" id="newPassword" name="new_password" required autocomplete="new-password"> </div>
                <div class="form-group"> <label for="confirmNewPassword">Confirm New Password:</label> <input type="password" id="confirmNewPassword" name="confirm_new_password" required autocomplete="new-password"> </div>
            </div>
            <div class="settings-actions"> <button type="submit" class="btn primary-btn">Update Password</button> </div>
        </form>
    </div>
    <div class="settings-section danger-zone" style="margin-bottom: 40px; padding-bottom: 20px;">
        <h3>Account Actions</h3> <p>Be careful with these actions.</p>
        <div class="settings-actions" style="text-align: left;"> <button type="button" id="logoutButton" class="btn secondary-btn"><i class="fas fa-sign-out-alt"></i> Logout</button> <button type="button" id="deleteMyAccountButton" class="btn danger-btn"><i class="fas fa-trash-alt"></i> Delete My Account</button> </div>
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
        <h2>Changelog</h2>
        <!-- ... content unchanged ... -->
        <ul class="changelog-list"> 
            <li><strong>v1.1.0 (2025-04-04)</strong> Added User Settings page allowing profile and password updates.<br> Added Account Deletion option for users.<br> Added "About" section with project info, team details, and changelog. </li>
            <li> <strong>v1.0.1 (2025-02-26)</strong> Implemented search and filtering for Thesis and Dissertation abstracts.<br> Fixed minor display bugs on the Home section. </li>
            <li> <strong>v1.0.0 (2025-02-25)</strong> Initial release: User login, Home dashboard displaying abstract counts and recent theses, basic viewing and downloading of abstracts. </li>
        </ul>
    </div>

</section>

</main>

<script>
        // --- All JavaScript remains unchanged ---
    const loggedInUserId = <?php echo json_encode($logged_in_user_id); ?>;

        function toggleMenu() { /* ... */
    const sidebar = document.getElementById('sidebar');
    const shadow = document.getElementById('shadow');
    const body = document.body;
    if (!sidebar || !shadow || !body) return;
    const isHidden = sidebar.classList.contains('hidden');
    sidebar.classList.toggle('hidden', !isHidden);
    shadow.classList.toggle('hidden', !isHidden);
    body.classList.toggle('sidebar-hidden', !isHidden);
}

document.querySelectorAll('.sidebar nav ul li a:not(.logout)').forEach(link => {
            link.addEventListener('click', function(e) { /* ... */
    e.preventDefault();
    const targetId = this.getAttribute('data-target');
    if (!targetId) return;
    document.querySelectorAll('.content > section').forEach(section => { section.classList.add('hidden'); section.classList.remove('active'); });
    document.querySelectorAll('.sidebar nav ul li a').forEach(a => a.classList.remove('active'));
    const targetSection = document.getElementById(targetId);
    if (targetSection) {
        targetSection.classList.remove('hidden');
        targetSection.classList.add('active');
        this.classList.add('active');
        switch(targetId) {
        case 'thesisAbstracts': fetchTheses(); break;
        case 'dissertationAbstracts': fetchDissertations(); break;
        case 'settings': fetchMyUserDetails(); break;
        }
    }
    if (window.innerWidth < 768) { toggleMenu(); }
});
        });

const thesisSearchInput = document.getElementById('thesisSearchInput');
const thesisProgramFilter = document.getElementById('thesisProgramFilter');
const thesisSortBy = document.getElementById('thesisSortBy');
const thesisApplyFiltersBtn = document.getElementById('thesisApplyFilters');
const thesisListContainer = document.getElementById('thesis-list');
        function fetchTheses() { /* ... */
const search = thesisSearchInput ? thesisSearchInput.value : ''; const program = thesisProgramFilter ? thesisProgramFilter.value : ''; const sort = thesisSortBy ? thesisSortBy.value : 'id_desc';
if (!thesisListContainer) return; thesisListContainer.innerHTML = '<p>Loading theses...</p>'; const url = `api-general/fetch_abstracts_user.php?abstract_type=Thesis&search=${encodeURIComponent(search)}&program=${encodeURIComponent(program)}&sort=${encodeURIComponent(sort)}`;
fetchData(url, displayTheses, (errorMsg) => { thesisListContainer.innerHTML = `<p style="color: red;">Error loading theses: ${errorMsg}</p>`; });
}
        function displayTheses(theses) { /* ... */
if (!thesisListContainer) return; thesisListContainer.innerHTML = '';
if (theses && theses.length > 0) {
   theses.forEach(abstract => { const row = document.createElement('div'); row.className = 'abstract-row'; row.innerHTML = ` <div class="abstract-info"> <div class="title">${abstract.title ?? 'Untitled'}</div> <p><strong>Researchers:</strong> ${abstract.researchers ?? 'N/A'}</p> <p><strong>Program:</strong> ${abstract.program_name ?? 'N/A'}</p> <p><strong>Abstract ID:</strong> ${abstract.abstract_id ?? 'N/A'}</p> </div> <div class="abstract-actions"> <button class="btn-view" onclick="viewAbstract(${abstract.abstract_id})">View</button> <button class="btn-download" onclick="downloadAbstract(${abstract.abstract_id})">Download</button> </div> `; thesisListContainer.appendChild(row); });
} else { thesisListContainer.innerHTML = '<p>No thesis abstracts found matching your criteria.</p>'; }
}
if(thesisApplyFiltersBtn) { thesisApplyFiltersBtn.addEventListener('click', fetchTheses); }

const dissSearchInput = document.getElementById('dissSearchInput');
const dissDepartmentFilter = document.getElementById('dissDepartmentFilter');
const dissSortBy = document.getElementById('dissSortBy');
const dissApplyFiltersBtn = document.getElementById('dissApplyFilters');
const dissertationsListContainer = document.getElementById('dissertations-list');
        function fetchDissertations() { /* ... */
const search = dissSearchInput ? dissSearchInput.value : ''; const department = dissDepartmentFilter ? dissDepartmentFilter.value : ''; const sort = dissSortBy ? dissSortBy.value : 'id_desc';
if (!dissertationsListContainer) return; dissertationsListContainer.innerHTML = '<p>Loading dissertations...</p>'; const url = `api-general/fetch_abstracts_dissertation.php?abstract_type=Dissertation&search=${encodeURIComponent(search)}&department=${encodeURIComponent(department)}&sort=${encodeURIComponent(sort)}`;
fetchData(url, displayDissertations, (errorMsg) => { dissertationsListContainer.innerHTML = `<p style="color: red;">Error loading dissertations: ${errorMsg}</p>`; });
}
        function displayDissertations(dissertations) { /* ... */
if (!dissertationsListContainer) return; dissertationsListContainer.innerHTML = '';
if (dissertations && dissertations.length > 0) {
   dissertations.forEach(abstract => { const row = document.createElement('div'); row.className = 'abstract-row'; row.innerHTML = ` <div class="abstract-info"> <div class="title">${abstract.title ?? 'Untitled'}</div> <p><strong>Researchers:</strong> ${abstract.researchers ?? 'N/A'}</p> <p><strong>Department:</strong> ${abstract.department_name ?? 'N/A'}</p> <p><strong>Abstract ID:</strong> ${abstract.abstract_id ?? 'N/A'}</p> </div> <div class="abstract-actions"> <button class="btn-view" onclick="viewAbstract(${abstract.abstract_id})">View</button> <button class="btn-download" onclick="downloadAbstract(${abstract.abstract_id})">Download</button> </div> `; dissertationsListContainer.appendChild(row); });
} else { dissertationsListContainer.innerHTML = '<p>No dissertation abstracts found matching your criteria.</p>'; }
}
if(dissApplyFiltersBtn) { dissApplyFiltersBtn.addEventListener('click', fetchDissertations); }

        function fetchData(url, callback, errorCallback) { /* ... */
const xhr = new XMLHttpRequest(); xhr.open('GET', url, true);
xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
        if (xhr.status === 200) { try { const response = JSON.parse(xhr.responseText); if (response && response.error) { console.error("API Error:", response.error); if(errorCallback) errorCallback(response.error); } else { callback(response); } } catch (e) { console.error("Parse Error:", e, xhr.responseText); if(errorCallback) errorCallback("Error processing server response."); } }
        else { console.error("HTTP Error:", xhr.status, xhr.statusText); if(errorCallback) errorCallback(`Server error (${xhr.status})`); }
    }
};
xhr.onerror = function() { console.error("Network Error"); if(errorCallback) errorCallback("Network error. Please check connection."); }; xhr.send();
}
        function postData(url, data, callback, errorCallback) { /* ... */
const xhr = new XMLHttpRequest(); xhr.open('POST', url, true); const body = (data instanceof FormData) ? data : new URLSearchParams(data); if (!(data instanceof FormData)) { xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); }
xhr.onreadystatechange = function () {
    if (xhr.readyState === 4) {
        if (xhr.status >= 200 && xhr.status < 300) { try { if (!xhr.responseText) { callback({}); return; } const response = JSON.parse(xhr.responseText); if (response.error) { console.error("API Error (within 2xx):", response.error); if(errorCallback) errorCallback(response.error); else alert("Error: " + response.error); } else { callback(response); } } catch (e) { console.error("Parse Error (2xx):", e, xhr.responseText); if(errorCallback) errorCallback("Error parsing server response."); else alert("Error parsing server response."); } }
                    else { let errorMsg = `Server responded with status ${xhr.status}`; try { const errorResponse = JSON.parse(xhr.responseText); if (errorResponse && errorResponse.error) { errorMsg = errorResponse.error; } } catch (e) { /* Ignore */ } console.error("HTTP Error:", xhr.status, xhr.statusText, xhr.responseText); if(errorCallback) errorCallback(errorMsg); else alert(errorMsg); }
    }
};
xhr.onerror = function() { console.error("Network Error"); const networkErrorMsg = "Network error."; if(errorCallback) errorCallback(networkErrorMsg); else alert(networkErrorMsg); }; xhr.send(body);
}
        function viewAbstract(abstractId) { /* ... */ if (!abstractId) return; const url = `api-general/view_abstract.php?id=${encodeURIComponent(abstractId)}`; window.open(url, '_blank'); }
        function downloadAbstract(abstractId) { /* ... */ if (!abstractId) return; window.location.href = `api-general/download_abstract.php?id=${encodeURIComponent(abstractId)}`; }

const personalDetailsInfo = document.getElementById('personalDetailsInfo');
const editMyProfileForm = document.getElementById('editMyProfileForm');
const changeMyPasswordForm = document.getElementById('changeMyPasswordForm');
const logoutButton = document.getElementById('logoutButton');
const deleteMyAccountButton = document.getElementById('deleteMyAccountButton');
        function fetchMyUserDetails() { /* ... */
if (!personalDetailsInfo) return; personalDetailsInfo.innerHTML = '<p>Loading your details...</p>'; const url = `api-user/get_personal_details.php`;
fetchData(url, (response) => { if (!response || response.success === false || !response.data) { const errorMsg = response && response.error ? response.error : 'Could not load your details.'; personalDetailsInfo.innerHTML = `<p style="color: red;">${errorMsg}</p>`; return; } const user = response.data; personalDetailsInfo.innerHTML = ` <p><strong>Account ID:</strong> ${user.account_id}</p> <p><strong>Username:</strong> ${user.username}</p> <p><strong>Name:</strong> ${user.name}</p> <p><strong>Sex:</strong> ${user.sex === 'M' ? 'Male' : (user.sex === 'F' ? 'Female' : 'N/A')}</p> <p><strong>Academic Level:</strong> ${user.academic_level || 'N/A'}</p> <p><strong>Program:</strong> ${user.program_name || 'N/A'}</p> `; if (editMyProfileForm) { document.getElementById('myProfileUsername').value = user.username || ''; document.getElementById('myProfileName').value = user.name || ''; document.getElementById('myProfileSex').value = user.sex || ''; document.getElementById('myProfileAcademicLevel').value = user.academic_level || ''; document.getElementById('myProfileProgramId').value = user.program_id || ''; } }, (errorMsg) => { personalDetailsInfo.innerHTML = `<p style="color: red;">Error loading your details: ${errorMsg}</p>`; });
}
        if (editMyProfileForm) { editMyProfileForm.addEventListener('submit', function(e) { /* ... */ e.preventDefault(); const formData = new FormData(this); if (!formData.get('program_id')) { alert('Please select your program.'); return; } const url = 'api-user/update_profile.php'; postData(url, formData, (response) => { alert(response.success || "Profile updated successfully."); fetchMyUserDetails(); const newName = formData.get('name'); if (newName && document.querySelector('#home h1')) { document.querySelector('#home h1').textContent = `Welcome, ${newName}!`; } }, (errorMsg) => { alert(`Error updating profile: ${errorMsg}`); }); }); }
        if (changeMyPasswordForm) { changeMyPasswordForm.addEventListener('submit', function(e) { /* ... */ e.preventDefault(); const formData = new FormData(this); const newPassword = formData.get('new_password'); const confirmNewPassword = formData.get('confirm_new_password'); if (newPassword !== confirmNewPassword) { alert("New password and confirmation password do not match."); return; } if (!newPassword) { alert("New password cannot be empty."); return; } const url = 'api-user/change_password.php'; postData(url, formData, (response) => { alert(response.success || "Password changed successfully."); changeMyPasswordForm.reset(); }, (errorMsg) => { alert(`Error changing password: ${errorMsg}`); document.getElementById('currentPassword').value = ''; document.getElementById('newPassword').value = ''; document.getElementById('confirmNewPassword').value = ''; }); }); }
        if (logoutButton) { logoutButton.addEventListener('click', function() { /* ... */ if (confirm("Are you sure you want to logout?")) { window.location.href = 'logout.php'; } }); }
        if (deleteMyAccountButton) { deleteMyAccountButton.addEventListener('click', function() { /* ... */ if (confirm("!!! DANGER !!!\nAre you absolutely sure you want to permanently delete your account?\nThis action CANNOT be undone.")) { const url = 'api-user/delete_self_account.php'; const data = { account_id: loggedInUserId }; postData(url, data, (response) => { alert(response.success || "Your account has been deleted."); window.location.href = 'login.php'; }, (errorMsg) => { alert(`Error deleting account: ${errorMsg}`); }); } }); }
</script>
</body>
</html>