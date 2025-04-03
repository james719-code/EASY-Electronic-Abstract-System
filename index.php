<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Electronic Abstract System</title>
    <link rel="stylesheet" href="css/index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <section class="hero">
        <div class="hero-content">
            <h2>EASy</h2>
            <h1>Discover Research, Explore Knowledge</h1>
            <p>Access different thesis abstracts from various disciplines and find research inspiration.</p>
            <div class="cta-buttons">
                <a href="login.php" class="btn primary-btn">Login</a>
                <a href="register.php" class="btn secondary-btn">Sign Up</a>
            </div>
        </div>
        <img src="assets/igm.png" alt="Thesis Management Illustration" class="hero-image">
    </section>

    <section id="features" class="features">
        <div class="feature-card">
            <i class="fas fa-eye"></i>
            <h3>Explore Research at a Glance</h3>
            <p>Browse and read different thesis abstracts effortlessly, gaining quick insights into various research topics</p>
        </div>
        <div class="feature-card">
            <i class="fas fa-search"></i>
            <h3>Search & Discover</h3>
            <p>Find relevant thesis abstracts using advanced search and filtering options.</p>
        </div>
        <div class="feature-card">
            <i class="fas fa-download"></i>
            <h3>Save Knowledge</h3>
            <p>Easily download thesis abstracts in PDF format for offline reading and future references.</p>
        </div>
    </section>

    <section id="about" class="about">
        <h2>About Us</h2>
        <p>
            Our Thesis Management System is designed to streamline the process of managing, sharing, and collaborating on thesis abstracts. Whether you're a student, researcher, or administrator, this platform offers everything you need to organize and showcase your work.
        </p>
    </section>

    <footer>
        <p>© <?php echo date("Y"); ?> Electronic Abstract System. All rights reserved.</p>
    </footer>
</body>