<?php
session_start();

// Security: Redirect non-users to login page based on ACCOUNT table structure
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'User' || !isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

// Use the config consistent with admin dashboard
include 'api-general/config.php'; // Make sure this path is correct

$userAccountId = $_SESSION['account_id'];
$userFullName = $_SESSION['name'] ?? 'User'; // Assumes 'name' from ACCOUNT table is in session

$programs = [];
$departments = []; // For dissertation filter
$userProgramId = null;
$userProgramName = 'N/A';
$recentAbstracts = [];
$total_thesis = 0; // Initialize count
$total_dissertation = 0; // Initialize count
$db_error_message = null;

try {
    // Fetch all programs for the filter dropdown - Matches PROGRAM table
    $stmtPrograms = $conn->query("SELECT program_id, program_name FROM program ORDER BY program_name ASC");
    $programs = $stmtPrograms->fetchAll(PDO::FETCH_ASSOC);
    $stmtPrograms->closeCursor();

    // Fetch all departments for the dissertation filter dropdown - Matches DEPARTMENT table
    $stmtDepartments = $conn->query("SELECT department_id, department_name FROM department ORDER BY department_name ASC");
    $departments = $stmtDepartments->fetchAll(PDO::FETCH_ASSOC);
    $stmtDepartments->closeCursor();

    // Fetch total Thesis abstracts count
    $stmtTotalThesis = $conn->query("SELECT COUNT(*) FROM ABSTRACT WHERE abstract_type = 'Thesis'");
    $total_thesis = $stmtTotalThesis->fetchColumn() ?: 0;
    $stmtTotalThesis->closeCursor();

    // Fetch total Dissertation abstracts count
    $stmtTotalDissertation = $conn->query("SELECT COUNT(*) FROM ABSTRACT WHERE abstract_type = 'Dissertation'");
    $total_dissertation = $stmtTotalDissertation->fetchColumn() ?: 0;
    $stmtTotalDissertation->closeCursor();

    // Fetch the user's program_id and name using joins - Matches USER and PROGRAM tables
    $stmtUserProgram = $conn->prepare("
        SELECT u.program_id, p.program_name
        FROM USER u -- References USER table
        JOIN PROGRAM p ON u.program_id = p.program_id -- References PROGRAM table
        WHERE u.account_id = :account_id -- References USER.account_id
    ");
    $stmtUserProgram->bindParam(':account_id', $userAccountId, PDO::PARAM_INT);
    $stmtUserProgram->execute();
    $userProgramInfo = $stmtUserProgram->fetch(PDO::FETCH_ASSOC);
    $stmtUserProgram->closeCursor();

    if ($userProgramInfo) {
        $userProgramId = $userProgramInfo['program_id'];
        $userProgramName = $userProgramInfo['program_name'];

        // Fetch 10 recent abstracts for the user's program (Still focusing on Thesis for recent)
        $stmtRecent = $conn->prepare("
            SELECT
                a.abstract_id, a.title, a.researchers, p.program_name
            FROM ABSTRACT a
            JOIN THESIS_ABSTRACT ta ON a.abstract_id = ta.abstract_id
            JOIN PROGRAM p ON ta.program_id = p.program_id
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
        /* Styles from previous version - ensure they align with dashboard.css */
        /* Include styles for .cards and .card if not in dashboard.css */
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
        @media (max-width: 600px) {
            .abstract-row { flex-direction: column; align-items: flex-start; }
            .abstract-actions { flex-direction: row; width: 100%; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; justify-content: flex-end; }
        }
    </style>
</head>
<body class="sidebar-hidden">

    <header class="nav_bar">
        <div class="menu" onclick="toggleMenu()"> <i class="fa-solid fa-bars"></i> </div>
        <div class="nav_name" id="nav_name">EASY</div> <!-- Changed Name -->
    </header>

    <aside id="sidebar" class="sidebar hidden">
        <i id="x-button" class="fa-solid fa-times close-sidebar" onclick="toggleMenu()"></i>
        <div class="logo"><h2>EASY</h2></div> <!-- Changed Name -->
        <nav>
             <ul>
                <li><a href="#" data-target="home" class="active"><i class="fas fa-home"></i><span> Home</span></a></li>
                <li><a href="#" data-target="thesisAbstracts"><i class="fas fa-book"></i><span> Thesis Abstracts</span></a></li>
                <li><a href="#" data-target="dissertationAbstracts"><i class="fas fa-scroll"></i><span> Dissertation Abstracts</span></a></li> <!-- New Link -->
                <li><a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i><span> Logout</span></a></li>
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
             <h1>Welcome, <?php echo htmlspecialchars($userFullName); ?>!</h1>

             <!-- Added Cards Section -->
             <section class="cards">
                <div class="card">
                    <i class="fas fa-book"></i>
                    <h3>Total Thesis Abstracts</h3>
                    <p><?php echo htmlspecialchars($total_thesis); ?></p>
                </div>
                <div class="card">
                    <i class="fas fa-scroll"></i>
                    <h3>Total Dissertation Abstracts</h3>
                    <p><?php echo htmlspecialchars($total_dissertation); ?></p>
                </div>
            </section>

             <p>Your current program: <strong><?php echo htmlspecialchars($userProgramName); ?></strong></p>
             <h2>Recently Added Theses</h2>
             <?php if ($userProgramId): ?>
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
            <h2>Search Thesis Abstracts</h2>
            <div class="search-filter-container">
                <input type="text" id="thesisSearchInput" placeholder="Search title, researchers...">
                <select id="thesisProgramFilter">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?php echo htmlspecialchars($program['program_id']); ?>"
                                <?php if ($userProgramId && $program['program_id'] == $userProgramId) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($program['program_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="thesisSortBy">
                    <option value="id_desc">Newest First (by ID)</option>
                    <option value="id_asc">Oldest First (by ID)</option>
                    <option value="title_asc">Title (A-Z)</option>
                    <option value="title_desc">Title (Z-A)</option>
                    <option value="researchers_asc">Researchers (A-Z)</option>
                    <option value="researchers_desc">Researchers (Z-A)</option>
                </select>
                <button id="thesisApplyFilters" class="btn primary-btn">Search</button>
            </div>
            <div class="row-lay" id="thesis-list">
                <p>Loading abstracts...</p>
            </div>
        </section>

        <!-- NEW Dissertation Abstracts Section -->
        <section id="dissertationAbstracts" class="hidden">
            <h2>Search Dissertation Abstracts</h2>
            <div class="search-filter-container">
                <input type="text" id="dissSearchInput" placeholder="Search title, researchers...">
                <select id="dissDepartmentFilter"> <!-- Filter by Department -->
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['department_id']); ?>">
                            <?php echo htmlspecialchars($dept['department_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="dissSortBy">
                    <option value="id_desc">Newest First (by ID)</option>
                    <option value="id_asc">Oldest First (by ID)</option>
                    <option value="title_asc">Title (A-Z)</option>
                    <option value="title_desc">Title (Z-A)</option>
                    <option value="researchers_asc">Researchers (A-Z)</option>
                    <option value="researchers_desc">Researchers (Z-A)</option>
                </select>
                <button id="dissApplyFilters" class="btn primary-btn">Search</button>
            </div>
            <div class="row-lay" id="dissertations-list">
                <p>Loading abstracts...</p>
            </div>
        </section>

    </main>

    <script>
        // Sidebar Toggle Function (Unchanged)
        function toggleMenu() { /* ... same as before ... */
            const sidebar = document.getElementById('sidebar');
            const shadow = document.getElementById('shadow');
            const body = document.body;
            if (!sidebar || !shadow || !body) return;
            const isHidden = sidebar.classList.contains('hidden');
            sidebar.classList.toggle('hidden', !isHidden);
            shadow.classList.toggle('hidden', !isHidden);
            body.classList.toggle('sidebar-hidden', !isHidden);
         }

        // Section Switching Logic (Updated to handle new section)
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

                    // Trigger fetch for the specific section when activated
                    if (targetId === 'thesisAbstracts') {
                        fetchTheses(); // Renamed for clarity
                    } else if (targetId === 'dissertationAbstracts') {
                        fetchDissertations(); // Call fetch for dissertations
                    }
                }
                if (window.innerWidth < 768) {
                    toggleMenu();
                }
            });
        });

        // --- Thesis Abstract Fetching and Display ---
        const thesisSearchInput = document.getElementById('thesisSearchInput');
        const thesisProgramFilter = document.getElementById('thesisProgramFilter');
        const thesisSortBy = document.getElementById('thesisSortBy');
        const thesisApplyFiltersBtn = document.getElementById('thesisApplyFilters');
        const thesisListContainer = document.getElementById('thesis-list');

        function fetchTheses() {
            const search = thesisSearchInput ? thesisSearchInput.value : '';
            const program = thesisProgramFilter ? thesisProgramFilter.value : '';
            const sort = thesisSortBy ? thesisSortBy.value : 'id_desc';

            if (!thesisListContainer) return;
            thesisListContainer.innerHTML = '<p>Loading theses...</p>';

            // *** Use the API endpoint dedicated to fetching THESES for users ***
            // OR modify a general endpoint to accept abstract_type='Thesis'
            const url = `api-general/fetch_abstracts_user.php?abstract_type=Thesis&search=${encodeURIComponent(search)}&program=${encodeURIComponent(program)}&sort=${encodeURIComponent(sort)}`;

            fetchData(url, displayTheses, (errorMsg) => {
                 thesisListContainer.innerHTML = `<p style="color: red;">Error loading theses: ${errorMsg}</p>`;
            });
        }

        function displayTheses(theses) {
             if (!thesisListContainer) return;
             thesisListContainer.innerHTML = '';

             if (theses && theses.length > 0) {
                 theses.forEach(abstract => {
                     const row = document.createElement('div');
                     row.className = 'abstract-row';
                     row.innerHTML = `
                        <div class="abstract-info">
                            <div class="title">${abstract.title ?? 'Untitled'}</div>
                            <p><strong>Researchers:</strong> ${abstract.researchers ?? 'N/A'}</p>
                            <p><strong>Program:</strong> ${abstract.program_name ?? 'N/A'}</p>
                            <p><strong>Abstract ID:</strong> ${abstract.abstract_id ?? 'N/A'}</p>
                        </div>
                        <div class="abstract-actions">
                            <button class="btn-view" onclick="viewAbstract(${abstract.abstract_id})">View</button>
                            <button class="btn-download" onclick="downloadAbstract(${abstract.abstract_id})">Download</button>
                        </div>
                     `;
                     thesisListContainer.appendChild(row);
                 });
             } else {
                 thesisListContainer.innerHTML = '<p>No thesis abstracts found matching your criteria.</p>';
             }
        }

         // Event Listener for Thesis Search Button
        if(thesisApplyFiltersBtn) {
             thesisApplyFiltersBtn.addEventListener('click', fetchTheses);
        }

        // --- Dissertation Abstract Fetching and Display ---
        const dissSearchInput = document.getElementById('dissSearchInput');
        const dissDepartmentFilter = document.getElementById('dissDepartmentFilter');
        const dissSortBy = document.getElementById('dissSortBy');
        const dissApplyFiltersBtn = document.getElementById('dissApplyFilters');
        const dissertationsListContainer = document.getElementById('dissertations-list');

        function fetchDissertations() {
            const search = dissSearchInput ? dissSearchInput.value : '';
            const department = dissDepartmentFilter ? dissDepartmentFilter.value : ''; // Get department ID
            const sort = dissSortBy ? dissSortBy.value : 'id_desc';

            if (!dissertationsListContainer) return;
            dissertationsListContainer.innerHTML = '<p>Loading dissertations...</p>';

            // *** Use an API endpoint dedicated to fetching DISSERTATIONS for users ***
            // This endpoint needs to join with DEPARTMENT table instead of PROGRAM
            // Pass 'department' parameter instead of 'program'
             const url = `api-general/fetch_abstracts_dissertation.php?abstract_type=Dissertation&search=${encodeURIComponent(search)}&department=${encodeURIComponent(department)}&sort=${encodeURIComponent(sort)}`;


            fetchData(url, displayDissertations, (errorMsg) => {
                 dissertationsListContainer.innerHTML = `<p style="color: red;">Error loading dissertations: ${errorMsg}</p>`;
            });
        }

        function displayDissertations(dissertations) {
             if (!dissertationsListContainer) return;
             dissertationsListContainer.innerHTML = '';

             if (dissertations && dissertations.length > 0) {
                 dissertations.forEach(abstract => {
                     const row = document.createElement('div');
                     row.className = 'abstract-row';
                     // Display Department Name, fetched by the API
                     row.innerHTML = `
                        <div class="abstract-info">
                            <div class="title">${abstract.title ?? 'Untitled'}</div>
                            <p><strong>Researchers:</strong> ${abstract.researchers ?? 'N/A'}</p>
                            <p><strong>Department:</strong> ${abstract.department_name ?? 'N/A'}</p> <!-- Show Department -->
                            <p><strong>Abstract ID:</strong> ${abstract.abstract_id ?? 'N/A'}</p>
                        </div>
                        <div class="abstract-actions">
                            <button class="btn-view" onclick="viewAbstract(${abstract.abstract_id})">View</button>
                            <button class="btn-download" onclick="downloadAbstract(${abstract.abstract_id})">Download</button>
                        </div>
                     `;
                     dissertationsListContainer.appendChild(row);
                 });
             } else {
                 dissertationsListContainer.innerHTML = '<p>No dissertation abstracts found matching your criteria.</p>';
             }
        }

         // Event Listener for Dissertation Search Button
        if(dissApplyFiltersBtn) {
             dissApplyFiltersBtn.addEventListener('click', fetchDissertations);
        }


        // --- Generic Fetch Function (Helper) ---
        function fetchData(url, callback, errorCallback) {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) { // Check for application-level error
                                console.error("API Error:", response.error);
                                if(errorCallback) errorCallback(response.error);
                            } else {
                                callback(response); // Success
                            }
                        } catch (e) {
                            console.error("Parse Error:", e, xhr.responseText);
                             if(errorCallback) errorCallback("Error processing server response.");
                        }
                    } else { // HTTP error
                        console.error("HTTP Error:", xhr.status, xhr.statusText);
                        if(errorCallback) errorCallback(`Server error (${xhr.status})`);
                    }
                }
            };
            xhr.onerror = function() { // Network error
                console.error("Network Error");
                if(errorCallback) errorCallback("Network error. Please check connection.");
            };
            xhr.send();
        }


        // --- View Action (Unchanged) ---
        function viewAbstract(abstractId) {
            if (!abstractId) return;
            const url = `api-general/view_abstract.php?id=${encodeURIComponent(abstractId)}`;
            window.open(url, '_blank');
        }

        // --- Download Action (Unchanged) ---
        function downloadAbstract(abstractId) {
            if (!abstractId) return;
            window.location.href = `api-general/download_abstract.php?id=${encodeURIComponent(abstractId)}`;
        }

        // --- Initial Load ---
        // Home section is active by default.

    </script>
</body>
</html>