
<?php

require_once __DIR__ . "/config/database.php";

// --------------------------------------------------
// ESCAPE OUTPUT
// --------------------------------------------------

function publicGalleryEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// --------------------------------------------------
// FETCH PUBLIC PHOTOS ONLY
// --------------------------------------------------

$galleryError = false;
$photos = [];

try {

    $stmt = $pdo->query("
        SELECT
            id,
            title,
            description,
            created_at
        FROM gallery_photos
        WHERE visibility = 'public'
        ORDER BY created_at DESC, id DESC
    ");

    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log("Public gallery error: " . $e->getMessage());

    $galleryError = true;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Our Gallery | Our Website</title>

    <meta
        name="description"
        content="Explore our property gallery, projects and memorable moments."
    >

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        /* NAVIGATION */

        .navbar {
            width: 100%;
            background: #ffffff;
            padding: 18px 7%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .brand {
            color: #172554;
            font-size: 23px;
            font-weight: 800;
            text-decoration: none;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .nav-links a {
            color: #334155;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            transition: 0.2s;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: #2563eb;
        }

        /* HERO */

        .hero {
            background:
                linear-gradient(
                    135deg,
                    #172554,
                    #1e40af
                );

            color: #ffffff;
            text-align: center;
            padding: 85px 20px;
        }

        .hero-label {
            display: inline-block;
            color: #bfdbfe;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .hero h1 {
            font-size: 43px;
            line-height: 1.2;
            margin-bottom: 15px;
        }

        .hero p {
            max-width: 650px;
            margin: 0 auto;
            color: #dbeafe;
            font-size: 16px;
        }

        /* MAIN CONTAINER */

        .container {
            max-width: 1250px;
            margin: 0 auto;
            padding: 65px 25px;
        }

        .section-heading {
            text-align: center;
            margin-bottom: 35px;
        }

        .section-heading span {
            color: #2563eb;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .section-heading h2 {
            color: #172554;
            font-size: 30px;
            margin-top: 7px;
        }

        .section-heading p {
            color: #64748b;
            font-size: 14px;
            margin-top: 8px;
        }

        /* GALLERY GRID */

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(280px, 1fr)
            );
            gap: 25px;
        }

        .gallery-card {
            background: #ffffff;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 5px 20px rgba(15,23,42,0.05);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .gallery-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 28px rgba(15,23,42,0.12);
        }

        .gallery-image-wrapper {
            width: 100%;
            height: 250px;
            overflow: hidden;
            background: #e2e8f0;
        }

        .gallery-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.4s ease;
        }

        .gallery-card:hover img {
            transform: scale(1.05);
        }

        .gallery-content {
            padding: 21px;
        }

        .gallery-content h3 {
            color: #172554;
            font-size: 18px;
            margin-bottom: 9px;
            overflow-wrap: anywhere;
        }

        .gallery-content p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .gallery-date {
            display: block;
            margin-top: 14px;
            color: #94a3b8;
            font-size: 12px;
        }

        /* EMPTY / ERROR MESSAGE */

        .empty-message {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 55px 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(15,23,42,0.04);
        }

        .empty-message h3 {
            color: #172554;
            font-size: 21px;
            margin-bottom: 10px;
        }

        .empty-message p {
            color: #64748b;
            font-size: 14px;
        }

        /* FOOTER */

        .footer {
            background: #172554;
            color: #ffffff;
            padding: 35px 20px;
            text-align: center;
        }

        .footer p {
            font-size: 13px;
            color: #cbd5e1;
        }

        .footer a {
            display: inline-block;
            margin-top: 12px;
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /* RESPONSIVE DESIGN */

        @media (max-width: 650px) {

            .navbar {
                padding: 17px 20px;
                flex-direction: column;
            }

            .nav-links {
                gap: 20px;
            }

            .hero {
                padding: 65px 20px;
            }

            .hero h1 {
                font-size: 32px;
            }

            .hero p {
                font-size: 14px;
            }

            .container {
                padding: 45px 17px;
            }

            .section-heading h2 {
                font-size: 25px;
            }

            .gallery-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .gallery-image-wrapper {
                height: 230px;
            }

        }

    </style>

</head>

<body>

    <!-- NAVIGATION -->

    <header class="navbar">

        <a href="index.php" class="brand">
            Our Website
        </a>

        <nav class="nav-links">

            <a href="index.php">
                Home
            </a>

            <a href="public-gallery.php" class="active">
                Gallery
            </a>

        </nav>

    </header>

    <!-- HERO SECTION -->

    <section class="hero">

        <span class="hero-label">
            Our Collection
        </span>

        <h1>Our Photo Gallery</h1>

        <p>
            Discover our properties, projects and memorable moments
            through our photo collection.
        </p>

    </section>

    <!-- GALLERY SECTION -->

    <main class="container">

        <div class="section-heading">

            <span>Explore Our Collection</span>

            <h2>Gallery Highlights</h2>

            <p>
                Take a closer look at our latest photos and projects.
            </p>

        </div>

        <?php if ($galleryError): ?>

            <div class="empty-message">

                <h3>Gallery Temporarily Unavailable</h3>

                <p>
                    We are unable to load the gallery right now.
                    Please visit again later.
                </p>

            </div>

        <?php elseif (empty($photos)): ?>

            <div class="empty-message">

                <h3>No Photos Available Yet</h3>

                <p>
                    Our gallery collection will be updated soon.
                    Please check back later.
                </p>

            </div>

        <?php else: ?>

            <div class="gallery-grid">

                <?php foreach ($photos as $photo): ?>

                    <article class="gallery-card">

                        <div class="gallery-image-wrapper">

                            <img
                                src="gallery_public_image.php?id=<?php echo (int) $photo['id']; ?>"
                                alt="<?php echo publicGalleryEscape($photo['title'] ?: 'Gallery photo'); ?>"
                                loading="lazy"
                            >

                        </div>

                        <div class="gallery-content">

                            <?php if (!empty($photo['title'])): ?>

                                <h3>
                                    <?php echo publicGalleryEscape($photo['title']); ?>
                                </h3>

                            <?php endif; ?>

                            <?php if (!empty($photo['description'])): ?>

                                <p>
                                    <?php echo nl2br(publicGalleryEscape($photo['description'])); ?>
                                </p>

                            <?php endif; ?>

                            <span class="gallery-date">

                                <?php
                                    $timestamp = strtotime($photo['created_at']);

                                    echo $timestamp !== false
                                        ? date("d M Y", $timestamp)
                                        : "";
                                ?>

                            </span>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </main>

    <!-- FOOTER -->

    <footer class="footer">

        <p>
            &copy; <?php echo date("Y"); ?>
            Our Website. All Rights Reserved.
        </p>

        <a href="index.php">
            Back to Home
        </a>

    </footer>

</body>

</html>
